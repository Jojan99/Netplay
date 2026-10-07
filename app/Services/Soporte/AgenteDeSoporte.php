<?php

namespace App\Services\Soporte;

use App\Models\SoporteCaso;
use App\Models\SoporteConfig;
use App\Services\Cobranza\Ia;
use App\Services\WhatsApp\LineasDeWhatsApp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\PhpExecutableFinder;

/**
 * La puerta del asistente de soporte: decide si un mensaje que entra por
 * WhatsApp es un pedido de soporte, abre el caso y lo deja en manos del
 * asistente; y lo retira cuando una persona de la empresa toma la conversación.
 *
 * Sirve igual para WhatsApp Web (provider «netplay») y para la API de Meta.
 * La conversación con la IA no corre aquí: se lanza en un proceso aparte para
 * no ocupar los procesos que atienden la web.
 */
class AgenteDeSoporte
{
    /** Frases que, sin tildes y en minúscula, suenan a «tengo un problema con el servicio». */
    /** «no (tengo|tenemos|hay|…) [un par de palabras] internet/servicio/señal/wifi/conexión», ya en plano. */
    private const SIN_SERVICIO = '/\bno (?:me |nos |le |les )?(?:tengo|tenemos|tiene|tienen|hay|llega|ha (?:vuelto|llegado|regresado)|han (?:vuelto|llegado|activado|conectado|reconectado|restablecido|puesto|devuelto))(?: \w+){0,2}? (?:internet|servicio|senal|wifi|conexion)\b/';

    private const PALABRAS = [
        'sin internet', 'no tengo internet', 'no hay internet', 'no me llega internet', 'se fue el internet', 'se cayo el internet',
        'internet lento', 'internet esta lento', 'esta muy lento', 'muy lento', 'lentitud', 'internet malo', 'internet pesimo',
        'se cae', 'se me cae', 'intermitente', 'intermitencia', 'se desconecta', 'no conecta', 'no me conecta', 'no navega', 'no carga',
        'no funciona', 'no me funciona', 'no sirve el internet', 'no me sirve el internet', 'sin servicio', 'no tengo servicio',
        'sin senal', 'no tengo senal', 'mala senal', 'sin wifi', 'no tengo wifi', 'no aparece el wifi', 'no aparece la red',
        'luz roja', 'bombillo rojo', 'los en rojo', 'los rojo',
        'soporte', 'falla', 'averia', 'dano en el servicio', 'problema con el servicio', 'problemas con el servicio', 'problema con el internet', 'problemas con el internet',
        'problema con mi internet', 'problemas con mi internet', 'problema de internet', 'problemas de internet',
        'contrasena', 'clave del wifi', 'clave wifi', 'clave de wifi', 'clave del internet', 'clave de la red', 'cambiar la clave', 'cambio de clave', 'cambiar clave',
        'reiniciar el router', 'reiniciar el modem', 'reiniciar mi equipo',
    ];

    /** Minutos sin que el asistente se meta después de que una persona escribió en la conversación. */
    private const RESPETO_AL_HUMANO = 30;

    /** En las pruebas no se lanzan procesos. */
    public static bool $lanzarProcesos = true;

    public static function suena(?string $texto, ?string $propias = null): bool
    {
        $t = self::plano((string) $texto);

        if ($t === '' || mb_strlen($t) > 600) {
            return false;
        }

        $lista = array_merge(self::PALABRAS, array_filter(array_map(fn ($p) => self::plano($p), explode(',', (string) $propias)), fn ($p) => mb_strlen($p) >= 3));

        foreach ($lista as $p) {
            if (str_contains($t, $p)) {
                return true;
            }
        }

        // La lista fija solo conoce la primera persona: «no tenemos internet», «no nos
        // ha llegado el servicio» o «no han conectado el internet» se quedaban sin
        // reconocer y el mensaje terminaba en el bot de facturas.
        return preg_match(self::SIN_SERVICIO, $t) === 1;
    }

    /**
     * ¿Este mensaje lo debería atender el asistente? Se pregunta ANTES de que el
     * bot de menús lo procese (Meta), cuando todavía no hay conversación.
     */
    public static function esParaSoporte(int $companyId, string $provider, string $telefono, ?string $texto): bool
    {
        try {
            $cfg = SoporteConfig::deEmpresa($companyId);

            if (!$cfg->atiende($provider) || !Ia::disponible($companyId)) {
                return false;
            }

            if (SoporteCaso::abiertoCon($companyId, $provider, $telefono)) {
                return true;
            }

            $conversacion = DB::table('crm_conversations as c')->join('crm_customers as k', 'k.id', '=', 'c.customer_id')
                ->where('c.company_id', $companyId)->where('c.provider', $provider)->where('k.phone', $telefono)->orderByDesc('c.id')->value('c.id');

            return self::suena($texto, $cfg->palabras) && self::puedeAbrir($cfg, $provider, $telefono, $conversacion ? (int) $conversacion : null);
        } catch (\Throwable $e) {
            Log::warning('[Soporte] No se pudo decidir si el mensaje es de soporte', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Entró un mensaje del cliente ya guardado en el CRM. Si le corresponde al
     * asistente, queda a su cargo y devuelve el caso; si no, null y el mensaje
     * sigue su camino de siempre.
     */
    public static function tomar(int $companyId, string $provider, string $telefono, int $conversationId, int $mensajeId, ?string $texto, string $tipo = 'text', ?int $userId = null, ?int $lineaId = null, ?string $instancia = null): ?SoporteCaso
    {
        $cfg = SoporteConfig::deEmpresa($companyId);

        if (!$cfg->atiende($provider) || !Ia::disponible($companyId)) {
            return null;
        }

        $caso = SoporteCaso::abiertoCon($companyId, $provider, $telefono);

        if (!$caso) {
            if ($tipo !== 'text' || !self::suena($texto, $cfg->palabras) || !self::puedeAbrir($cfg, $provider, $telefono, $conversationId)) {
                return null;
            }

            // Si la conversación ya está atada a un cliente (dio su cédula antes), es él.
            $userId = $userId ?: ((int) DB::table('crm_conversations as c')->join('crm_customers as k', 'k.id', '=', 'c.customer_id')->where('c.id', $conversationId)->value('k.user_id') ?: null);
            [$userId, $verificado] = self::quienEs($companyId, $telefono, $userId);

            $caso = SoporteCaso::create([
                'company_id' => $companyId, 'user_id' => $userId, 'telefono' => $telefono, 'provider' => $provider,
                'wa_linea_id' => $lineaId, 'conversation_id' => $conversationId, 'estado' => 'activo', 'verificado' => $verificado,
                'historial' => [], 'diagnostico' => [],
            ]);

            $caso->pauso_bot = self::pausarBot($caso, $instancia);
            \App\Services\Cobranza\UsoIa::conversacion($companyId);
        }

        $caso->fill(['conversation_id' => $conversationId, 'wa_linea_id' => $lineaId ?? $caso->wa_linea_id, 'ultimo_mensaje_id' => $mensajeId, 'ultimo_mensaje_en' => now()])->save();

        self::lanzar((int) $caso->id, $mensajeId);

        return $caso;
    }

    /** Una persona de la empresa escribió en la conversación: el asistente se retira y no vuelve a meterse. */
    public static function retirar(int $conversationId): void
    {
        try {
            SoporteCaso::where('conversation_id', $conversationId)->whereIn('estado', SoporteCaso::ABIERTOS)->get()
                // El otro bot queda callado: ahora atiende una persona.
                ->each(fn (SoporteCaso $c) => self::cerrar($c, 'humano', 'lo_tomo_una_persona', false));
        } catch (\Throwable $e) {
            Log::warning('[Soporte] No se pudo retirar al asistente', ['conversation_id' => $conversationId, 'error' => $e->getMessage()]);
        }
    }

    public static function cerrar(SoporteCaso $caso, string $estado, ?string $resultado = null, bool $soltarAlBot = true, ?string $resumen = null): void
    {
        $caso->fill(array_filter([
            'estado' => $estado, 'resultado' => $resultado ?? $caso->resultado, 'resumen' => $resumen ?? $caso->resumen,
        ], fn ($v) => $v !== null) + ['pendiente' => null, 'cerrado_en' => now()])->save();

        if ($soltarAlBot && $caso->pauso_bot) {
            self::soltarBot($caso);
        }
    }

    /** Anota lo que el asistente está por mandar, para reconocer su eco cuando WhatsApp lo devuelva. */
    public static function anotarEnvio(int $conversationId, string $texto): void
    {
        try {
            \Illuminate\Support\Facades\Cache::store('redis')->put("soporte:eco:{$conversationId}:" . md5(trim($texto)), 1, now()->addMinutes(5));
        } catch (\Throwable) {
            // Sin Redis no hay marca: a lo sumo el eco se guarda como un mensaje más.
        }
    }

    public static function esEcoPropio(int $conversationId, string $texto): bool
    {
        try {
            return (bool) \Illuminate\Support\Facades\Cache::store('redis')->get("soporte:eco:{$conversationId}:" . md5(trim($texto)));
        } catch (\Throwable) {
            return false;
        }
    }

    // ── Decisiones ───────────────────────────────────────────────────────────

    private static function puedeAbrir(SoporteConfig $cfg, string $provider, string $telefono, ?int $conversationId): bool
    {
        $companyId = (int) $cfg->company_id;

        // Una persona ya está hablando con este cliente: el asistente no interrumpe.
        if ($conversationId && DB::table('crm_messages')->where('conversation_id', $conversationId)->where('sender_type', 'agent')
            ->where('created_at', '>=', now()->subMinutes(self::RESPETO_AL_HUMANO))
            ->where(fn ($q) => $q->whereNotNull('sender_user_id')->orWhere('agent_signature', 'Desde WhatsApp'))->exists()) {
            return false;
        }

        if (SoporteCaso::where('company_id', $companyId)->where('provider', $provider)->where('telefono', $telefono)
            ->whereIn('estado', ['humano', 'escalado'])->where('cerrado_en', '>=', now()->subMinutes(self::RESPETO_AL_HUMANO * 2))->exists()) {
            return false;
        }

        // El asistente de cobranza ya conversa con este número.
        if ($provider === 'netplay' && \App\Models\CobranzaCaso::conversandoCon($companyId, $telefono)) {
            return false;
        }

        // Con la clave de IA de Netvula hay un cupo diario de prueba (compartido con cobranza);
        // con la clave propia de la empresa, sólo el tope que ella misma puso.
        if (!\App\Services\Cobranza\UsoIa::puedeEmpezar($companyId)) {
            return false;
        }

        return SoporteCaso::where('company_id', $companyId)->where('created_at', '>=', now()->startOfDay())->count() < max(1, (int) $cfg->max_casos_dia);
    }

    /**
     * Quién es el que escribe y si lo hace desde el teléfono registrado del cliente.
     *
     * @return array{0:?int,1:bool}
     */
    public static function quienEs(int $companyId, string $telefono, ?int $userId): array
    {
        $ultimos = substr(preg_replace('/\D/', '', $telefono), -10);

        if (strlen($ultimos) < 10) {
            return [$userId, false];
        }

        if ($userId) {
            $suyo = substr(preg_replace('/\D/', '', (string) DB::table('user_data')->where('company_id', $companyId)->where('user_id', $userId)->value('phone')), -10);

            return [$userId, $suyo === $ultimos];
        }

        // Sin identificar: si ese teléfono es de un solo cliente vigente, es él.
        $deEseTelefono = DB::table('user_data')->where('company_id', $companyId)->where('active', 1)->where('phone', 'like', "%{$ultimos}")->limit(2)->pluck('user_id');

        return $deEseTelefono->count() === 1 ? [(int) $deEseTelefono[0], true] : [null, false];
    }

    // ── El otro bot ──────────────────────────────────────────────────────────

    /** Calla al bot de menús con este número. Devuelve true si fue el asistente quien lo calló. */
    private static function pausarBot(SoporteCaso $caso, ?string $instancia): bool
    {
        try {
            $donde = ['company_id' => $caso->company_id, 'provider' => $caso->provider, 'phone' => $caso->telefono];

            if (DB::table('wa_bot_pauses')->where($donde)->exists()) {
                return false;
            }

            DB::table('wa_bot_pauses')->insert($donde + ['paused_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

            if ($caso->provider === 'netplay') {
                app(Mensajero::class)->pausarBotWeb((int) $caso->company_id, $instancia ?: self::instancia($caso), (string) $caso->telefono, true);
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('[Soporte] No se pudo pausar el bot', ['caso' => $caso->id, 'error' => $e->getMessage()]);

            return true;
        }
    }

    private static function soltarBot(SoporteCaso $caso): void
    {
        try {
            DB::table('wa_bot_pauses')->where(['company_id' => $caso->company_id, 'provider' => $caso->provider, 'phone' => $caso->telefono])->delete();

            if ($caso->provider === 'netplay') {
                app(Mensajero::class)->pausarBotWeb((int) $caso->company_id, self::instancia($caso), (string) $caso->telefono, false);
            }
        } catch (\Throwable $e) {
            Log::warning('[Soporte] No se pudo reactivar el bot', ['caso' => $caso->id, 'error' => $e->getMessage()]);
        }
    }

    public static function instancia(SoporteCaso $caso): ?string
    {
        return $caso->provider === 'netplay' ? LineasDeWhatsApp::instanciaDeConversacion($caso->wa_linea_id ? (int) $caso->wa_linea_id : null, (int) $caso->company_id) : null;
    }

    // ── Proceso aparte ───────────────────────────────────────────────────────

    /** Arranca el proceso que le contesta al cliente y vuelve sin esperarlo. */
    public static function lanzar(int $casoId, int $mensajeId): void
    {
        if (!self::$lanzarProcesos) {
            return;
        }

        $php = (new PhpExecutableFinder())->find() ?: 'php';

        exec(sprintf(
            'nohup %s %s soporte:atender %d %d >> %s 2>&1 &',
            escapeshellarg($php), escapeshellarg(base_path('artisan')), $casoId, $mensajeId, escapeshellarg(storage_path('logs/soporte-asistente.log'))
        ));
    }

    private static function plano(string $t): string
    {
        $t = mb_strtolower(trim($t));
        $t = strtr($t, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);

        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9 ]/', ' ', $t)));
    }
}

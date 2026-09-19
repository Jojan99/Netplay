<?php

namespace App\Services\Alertas;

use App\Models\Alerta;
use App\Models\Company;
use App\Models\WaNotificationRoute;
use App\Services\NetplayWhatsAppService;
use App\Services\NotificationRouterService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Las alertas de la red, al grupo de WhatsApp de los técnicos.
 *
 * Al momento sólo va lo que pide salir a la calle (crítico): puerto PON caído,
 * OLT o túnel sin respuesta, cliente caído por fibra, señal crítica. Cuando se
 * cierra, un "resuelto" para que nadie vaya a revisar algo que ya volvió. Los
 * avisos menores esperan al resumen de la mañana: mandados uno por uno el
 * grupo se vuelve ruido y lo terminan silenciando.
 *
 * Sale siempre por la línea de WhatsApp Web: la API de Meta no maneja grupos.
 *
 * El destino se configura desde el panel (Avisos y destinos) como dos eventos
 * más, alerta_red y alerta_resumen, junto con los avisos de tickets y pagos.
 * companies.alertas_grupo_jid se sigue guardando como espejo del primero: lo
 * usan el comando alertas:grupo y la tarea del resumen para saber qué empresas
 * tienen alertas prendidas. Cuando una empresa nunca pasó por el panel, el
 * espejo es el que manda, así que nada dejó de funcionar.
 */
class AvisosAlGrupo
{
    /** Tipos que le sirven al técnico. "datos" es trabajo de oficina. */
    private const TIPOS_DE_RED = ['corte', 'olt', 'tunel', 'senal', 'caida'];

    /** Más que esto en una tanda se resume, para no llenar la pantalla. */
    private const MAXIMO_POR_MENSAJE = 10;

    public function __construct(private int $companyId) {}

    /** El grupo espejo en companies. Se mantiene para el comando y la tarea. */
    public function grupo(): ?string
    {
        return Company::whereKey($this->companyId)->value('alertas_grupo_jid') ?: null;
    }

    /**
     * A dónde van las alertas de un evento: [destino => etiqueta].
     *
     * Si la empresa nunca configuró la red en el panel se usa el espejo; desde
     * que toca la pantalla, manda lo que ella dejó ahí (y si borra todo, deja
     * de recibir, que es lo que pidió).
     *
     * @return array<string,string>
     */
    public function destinos(string $evento = 'alerta_red'): array
    {
        $configurados = NotificationRouterService::destinos($this->companyId, $evento);

        if ($configurados || self::tieneRutasDeRed($this->companyId)) {
            return $configurados;
        }

        $espejo = $this->grupo();

        if (!$espejo) {
            return [];
        }

        $nombre = Company::whereKey($this->companyId)->value('alertas_grupo_nombre');

        return [$espejo => (string) ($nombre ?: $espejo)];
    }

    /** ¿La empresa ya configuró la red desde el panel? (prendida o apagada) */
    public static function tieneRutasDeRed(int $companyId): bool
    {
        if (!Schema::hasTable('wa_notification_routes')) {
            return false;
        }

        return WaNotificationRoute::where('company_id', $companyId)
            ->whereIn('event_type', NotificationRouterService::EVENTOS_DE_RED)
            ->exists();
    }

    /**
     * Pasa el grupo del espejo a las rutas del panel, una sola vez por empresa.
     * Lo llaman la migración y la pantalla: así el dueño ve su grupo de siempre
     * ya cargado aunque la migración todavía no haya corrido.
     */
    public static function materializarEspejo(int $companyId): void
    {
        if (!Schema::hasTable('wa_notification_routes') || self::tieneRutasDeRed($companyId)) {
            return;
        }

        $empresa = Company::find($companyId);

        if (!$empresa?->alertas_grupo_jid) {
            return;
        }

        foreach (NotificationRouterService::EVENTOS_DE_RED as $evento) {
            try {
                WaNotificationRoute::create([
                    'company_id'  => $companyId,
                    'event_type'  => $evento,
                    'destination' => $empresa->alertas_grupo_jid,
                    'label'       => NotificationRouterService::textoLimpio($empresa->alertas_grupo_nombre),
                    'enabled'     => true,
                ]);
            } catch (\Throwable $e) {
                // Dos pestañas abriendo la pantalla a la vez: el índice único
                // frena la segunda y la fila ya está. No es un error.
                Log::info('[Alertas] El grupo ya estaba pasado a rutas', ['empresa' => $companyId, 'evento' => $evento]);
            }
        }
    }

    /**
     * Deja companies.alertas_grupo_jid apuntando al primer destino activo de
     * las alertas críticas (o del resumen, si sólo configuró ese). Sin esto la
     * tarea alertas:resumen no sabría a qué empresas recorrer.
     */
    public static function sincronizarEspejo(int $companyId): void
    {
        $empresa = Company::find($companyId);

        if (!$empresa) {
            return;
        }

        $destinos = NotificationRouterService::destinos($companyId, 'alerta_red')
            ?: NotificationRouterService::destinos($companyId, 'alerta_resumen');

        $jid    = $destinos ? (string) array_key_first($destinos) : null;
        $nombre = $jid ? (string) $destinos[$jid] : null;

        $empresa->forceFill([
            'alertas_grupo_jid'    => $jid,
            'alertas_grupo_nombre' => $nombre,
        ])->save();
    }

    /**
     * Deja un único grupo para las dos alertas de red (o ninguno con null).
     * Es lo que hace el comando alertas:grupo; la pantalla usa el ABM de rutas
     * y termina llamando a sincronizarEspejo().
     */
    public static function asociarGrupo(int $companyId, ?string $jid, ?string $nombre = null): void
    {
        if (Schema::hasTable('wa_notification_routes')) {
            WaNotificationRoute::where('company_id', $companyId)
                ->whereIn('event_type', NotificationRouterService::EVENTOS_DE_RED)
                ->delete();

            if ($jid) {
                foreach (NotificationRouterService::EVENTOS_DE_RED as $evento) {
                    WaNotificationRoute::create([
                        'company_id'  => $companyId,
                        'event_type'  => $evento,
                        'destination' => $jid,
                        'label'       => NotificationRouterService::textoLimpio($nombre),
                        'enabled'     => true,
                    ]);
                }
            }
        }

        Company::whereKey($companyId)->update([
            'alertas_grupo_jid'    => $jid,
            'alertas_grupo_nombre' => $jid ? NotificationRouterService::textoLimpio($nombre) : null,
        ]);
    }

    /** Las empresas que hoy reciben alertas de red, por espejo o por rutas. */
    public static function empresasConAlertas(): array
    {
        $ids = Company::whereNotNull('alertas_grupo_jid')->pluck('id')->all();

        if (Schema::hasTable('wa_notification_routes')) {
            $ids = array_merge($ids, WaNotificationRoute::whereIn('event_type', NotificationRouterService::EVENTOS_DE_RED)
                ->where('enabled', true)
                ->pluck('company_id')->all());
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * Avisa las críticas nuevas y las que se resolvieron desde la última vez.
     *
     * @return array{abiertas:int, resueltas:int}
     */
    public function enviarPendientes(): array
    {
        if (!$this->destinos('alerta_red')) {
            return ['abiertas' => 0, 'resueltas' => 0];
        }

        $nuevas = Alerta::where('company_id', $this->companyId)
            ->abiertas()
            ->where('nivel', 'critico')
            ->whereIn('tipo', self::TIPOS_DE_RED)
            ->whereNull('avisada_en')
            ->orderBy('abierta_en')
            ->get();

        // Sólo el resuelto de lo que se anunció: críticas de red. Un aviso menor
        // que se cierra no tiene por qué aparecer en el grupo.
        $resueltas = Alerta::where('company_id', $this->companyId)
            ->where('nivel', 'critico')
            ->whereIn('tipo', self::TIPOS_DE_RED)
            ->whereNotNull('cerrada_en')
            ->whereNotNull('avisada_en')
            ->whereNull('cierre_avisado_en')
            ->orderBy('cerrada_en')
            ->get();

        // Sólo se marca lo que salió: si WhatsApp falla, se reintenta en la
        // próxima revisión en vez de perderse.
        if ($nuevas->isNotEmpty() && $this->enviar($this->textoDeNuevas($nuevas), 'alerta_red')) {
            Alerta::whereIn('id', $nuevas->pluck('id'))->update(['avisada_en' => now()]);
        }

        if ($resueltas->isNotEmpty() && $this->enviar($this->textoDeResueltas($resueltas), 'alerta_red')) {
            Alerta::whereIn('id', $resueltas->pluck('id'))->update(['cierre_avisado_en' => now()]);
        }

        return ['abiertas' => $nuevas->count(), 'resueltas' => $resueltas->count()];
    }

    /** Resumen de la mañana: lo que sigue abierto en la red. */
    public function enviarResumen(): bool
    {
        if (!$this->destinos('alerta_resumen')) {
            return false;
        }

        $abiertas = Alerta::where('company_id', $this->companyId)
            ->abiertas()
            ->whereIn('tipo', self::TIPOS_DE_RED)
            ->orderByRaw("nivel = 'critico' desc")
            ->orderBy('abierta_en')
            ->get();

        if ($abiertas->isEmpty()) {
            return $this->enviar("☀️ *Resumen de la red*\nSin novedades: no hay alertas abiertas.", 'alerta_resumen');
        }

        $criticas = $abiertas->where('nivel', 'critico');
        $lineas = ["☀️ *Resumen de la red* · {$abiertas->count()} abiertas ({$criticas->count()} críticas)", ''];

        foreach ($abiertas->take(self::MAXIMO_POR_MENSAJE * 2) as $a) {
            $lineas[] = ($a->nivel === 'critico' ? '🔴 ' : '🟡 ') . $a->titulo . ' · desde ' . $this->desde($a);
        }

        if ($abiertas->count() > self::MAXIMO_POR_MENSAJE * 2) {
            $lineas[] = '… y ' . ($abiertas->count() - self::MAXIMO_POR_MENSAJE * 2) . ' más en el panel (Alertas).';
        }

        return $this->enviar(implode("\n", $lineas), 'alerta_resumen');
    }

    /** El mismo texto que ve el grupo al quedar asociado, desde el panel o el comando. */
    public static function textoDeConfirmacion(): string
    {
        return "✅ Grupo asociado. Acá van a llegar las alertas de la red: cortes de puerto, OLT o túnel caídos, clientes caídos por fibra y señal crítica, más un resumen cada mañana.";
    }

    /** Mensaje de prueba al asociar el grupo. */
    public function probar(): bool
    {
        return $this->enviar(self::textoDeConfirmacion(), 'alerta_red');
    }

    /** Un solo envío a un destino puntual: la prueba que dispara el panel. */
    public function enviarA(string $destino, string $texto): bool
    {
        try {
            (new NetplayWhatsAppService($this->companyId, true))->mensajeInformativo($destino, $texto);

            return true;
        } catch (\Throwable $e) {
            Log::warning('[Alertas] No se pudo enviar', ['empresa' => $this->companyId, 'destino' => $destino, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Los grupos de la línea de WhatsApp Web de la empresa.
     *
     * @return list<array{jid:string, nombre:string, participantes:int}>
     */
    public function gruposDeLaLinea(): array
    {
        $empresa = Company::find($this->companyId);

        if (!$empresa?->wa_instance_id || !$empresa->wa_api_key) {
            throw new \RuntimeException('La empresa no tiene una línea de WhatsApp Web vinculada.');
        }

        $base = rtrim(preg_replace('#/crm$#', '', (string) config('services.netplay_whatsapp.base_url')), '/');
        $res = Http::timeout(20)
            ->withHeaders(['x-api-key' => $empresa->wa_api_key])
            ->get("{$base}/crm/instances/{$empresa->wa_instance_id}/groups");

        if (!$res->successful()) {
            throw new \RuntimeException('La línea de WhatsApp Web no respondió: ' . ($res->json('message') ?? $res->status()));
        }

        return array_map(fn ($g) => [
            'jid'           => (string) ($g['jid'] ?? ''),
            'nombre'        => (string) ($g['name'] ?? ''),
            'participantes' => (int) ($g['participants'] ?? 0),
        ], $res->json('groups') ?? []);
    }

    // ── Interno ───────────────────────────────────────────────────────────

    private function textoDeNuevas($alertas): string
    {
        if ($alertas->count() === 1) {
            $a = $alertas->first();

            return implode("\n", array_filter([
                "🚨 *{$a->titulo}*",
                $a->detalle,
                $this->ubicacion($a),
                '🕒 Desde ' . $this->desde($a),
            ]));
        }

        $lineas = ["🚨 *{$alertas->count()} alertas críticas nuevas*", ''];

        foreach ($alertas->take(self::MAXIMO_POR_MENSAJE) as $a) {
            $lugar = $this->ubicacion($a);
            $lineas[] = "• {$a->titulo}" . ($lugar ? "\n  " . str_replace("\n", "\n  ", $lugar) : '');
        }

        if ($alertas->count() > self::MAXIMO_POR_MENSAJE) {
            $lineas[] = '… y ' . ($alertas->count() - self::MAXIMO_POR_MENSAJE) . ' más en el panel (Alertas).';
        }

        return implode("\n", $lineas);
    }

    private function textoDeResueltas($alertas): string
    {
        $lineas = [$alertas->count() === 1 ? '✅ *Resuelto*' : "✅ *{$alertas->count()} alertas resueltas*"];

        foreach ($alertas->take(self::MAXIMO_POR_MENSAJE) as $a) {
            $lineas[] = "• {$a->titulo} · duró " . $this->duracion($a);
        }

        if ($alertas->count() > self::MAXIMO_POR_MENSAJE) {
            $lineas[] = '… y ' . ($alertas->count() - self::MAXIMO_POR_MENSAJE) . ' más.';
        }

        return implode("\n", $lineas);
    }

    /** Dónde ir: dirección del cliente y puerto de la OLT, si se saben. */
    private function ubicacion(Alerta $a): ?string
    {
        $partes = [];

        if ($a->user_id) {
            $direccion = DB::table('user_data')->where('user_id', $a->user_id)->value('address');
            if ($direccion) {
                $partes[] = "📍 {$direccion}";
            }
        }

        $datos = $a->datos ?? [];
        if (!empty($datos['olt'])) {
            $puerto = isset($datos['fsp']) ? " · puerto {$datos['fsp']}" . (isset($datos['ont_id']) ? " ONT {$datos['ont_id']}" : '') : '';
            $partes[] = "🔌 {$datos['olt']}{$puerto}";
        }

        return $partes ? implode("\n", $partes) : null;
    }

    private function desde(Alerta $a): string
    {
        $abierta = $a->abierta_en ?? $a->created_at;

        return $abierta->isToday() ? $abierta->format('H:i') : $abierta->format('d/m H:i');
    }

    private function duracion(Alerta $a): string
    {
        $abierta = $a->abierta_en ?? $a->created_at;

        return $abierta->diffForHumans($a->cerrada_en ?? now(), \Carbon\CarbonInterface::DIFF_ABSOLUTE, true, 2);
    }

    /**
     * Manda a todos los destinos del evento. Devuelve true si salió por lo
     * menos uno: con eso se marca la alerta como avisada. Si no salió por
     * ninguno se reintenta en la próxima revisión en vez de perderse.
     */
    private function enviar(string $texto, string $evento): bool
    {
        $destinos = $this->destinos($evento);

        if (!$destinos) {
            return false;
        }

        // true: aunque la empresa tenga apagado WhatsApp para clientes, las
        // alertas internas igual tienen que salir.
        $wa = new NetplayWhatsAppService($this->companyId, true);
        $alguno = false;

        foreach (array_keys($destinos) as $destino) {
            try {
                $wa->mensajeInformativo($destino, $texto);
                $alguno = true;
            } catch (\Throwable $e) {
                Log::warning('[Alertas] No se pudo avisar al grupo', [
                    'empresa' => $this->companyId,
                    'evento'  => $evento,
                    'destino' => $destino,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        return $alguno;
    }
}

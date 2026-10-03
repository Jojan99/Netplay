<?php

namespace App\Services\Plataforma;

use App\Services\Correo\Correo;
use App\Services\Correo\PlantillaDeCorreo;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Los correos de cobro que Netvula le manda a cada empresa (ISP) por su suscripción.
 *
 * El aviso ya existía dentro del panel, pero sólo lo ve quien entra. Esto es lo mismo por
 * correo, en el tono de un recordatorio de pago y no de una amenaza:
 *
 *   por_vencer    faltan pocos días para que venza un cobro (o la prueba)
 *   vence_hoy     el cobro vence hoy
 *   vencido       ya venció: dice hasta qué día tiene acceso
 *   ultimo_aviso  mañana (u hoy) es el último día antes de la suspensión
 *   suspendida    se suspendió el acceso al panel, y cómo recuperarlo
 *   reactivada    se registró el pago y la cuenta volvió
 *
 * Cada aviso sale una sola vez por vencimiento (queda anotado en plataforma_avisos_correo).
 * Lo que dice cada correo sale de EstadoDeCuenta, que es la misma fuente del aviso del panel:
 * no puede decir una fecha por correo y otra en la pantalla.
 */
class AvisosDeCuentaPorCorreo
{
    public static function activos(): bool
    {
        return (bool) config('plataforma.avisos_correo.activos', false);
    }

    /**
     * La pasada diaria. Con $simular no envía ni anota: sólo dice qué saldría.
     *
     * @return list<array{empresa:string, tipo:string, para:list<string>, asunto:string, enviado:bool, detalle:string}>
     */
    public static function revisar(bool $simular = false): array
    {
        $salida = [];

        foreach (DB::table('companies')->orderBy('id')->get(['id', 'name']) as $empresa) {
            try {
                $aviso = self::elQueToca((int) $empresa->id);
            } catch (\Throwable $e) {
                Log::warning('[Avisos de cuenta] No se pudo revisar la empresa', ['empresa' => $empresa->id, 'error' => $e->getMessage()]);
                continue;
            }

            if (!$aviso || self::yaSalio((int) $empresa->id, $aviso['tipo'], $aviso['referencia'])) {
                continue;
            }

            $salida[] = self::despachar((int) $empresa->id, (string) $empresa->name, $aviso, $simular);
        }

        return $salida;
    }

    /** Se registró el pago de una empresa que estaba suspendida: se le avisa que ya puede entrar. */
    public static function alReactivar(int $companyId): void
    {
        try {
            $estado = EstadoDeCuenta::de($companyId);
            $aviso = self::armar('reactivada', now()->format('Y-m-d H:i'), $estado, $companyId);
            self::despachar($companyId, (string) ($estado['empresa'] ?? ''), $aviso, false);
        } catch (\Throwable $e) {
            Log::warning('[Avisos de cuenta] No se pudo avisar la reactivación', ['empresa' => $companyId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Cómo se ve un aviso, sin que la empresa esté en esa situación: para revisar el diseño y los textos.
     *
     * @return array{asunto:string, html:string, texto:string}
     */
    public static function muestra(string $tipo): array
    {
        $hoy = now()->startOfDay();
        $estado = [
            'empresa' => 'Internet del Caribe S.A.S. (ejemplo)', 'plan' => 'Plan Crecimiento', 'estado' => $tipo === 'prueba_por_terminar' ? 'prueba' : 'activa',
            'pendiente' => 189900.0, 'vence' => $hoy->copy()->addDays(match ($tipo) { 'por_vencer' => 3, 'vence_hoy' => 0, default => -4 })->toDateString(),
            'prueba_hasta' => $hoy->copy()->addDays(3)->toDateString(), 'limite' => $hoy->copy()->addDays($tipo === 'ultimo_aviso' ? 1 : 4)->toDateString(),
            'motivo' => EstadoDeCuenta::MOTIVO_MORA, 'soporte' => ['whatsapp' => preg_replace('/\D/', '', (string) config('plataforma.soporte.whatsapp')) ?: null, 'correo' => config('plataforma.soporte.correo') ?: null],
        ];

        return array_intersect_key(self::armar($tipo, 'muestra', $estado, 0), ['asunto' => 1, 'html' => 1, 'texto' => 1]);
    }

    public const TIPOS = ['por_vencer', 'prueba_por_terminar', 'vence_hoy', 'vencido', 'ultimo_aviso', 'suspendida', 'reactivada'];

    // ── Qué aviso le toca hoy a una empresa ──────────────────────────────────

    /** @return array<string,mixed>|null */
    private static function elQueToca(int $companyId): ?array
    {
        $e = EstadoDeCuenta::de($companyId);
        $hoy = now()->startOfDay();

        if ($e['nivel'] === 'suspendida') {
            // Sólo al momento de suspender: a una empresa suspendida hace meses no se le empieza a escribir ahora.
            $cuando = DB::table('plataforma_suscripciones')->where('company_id', $companyId)->value('suspendida_auto_en');

            return $cuando && Carbon::parse($cuando)->gte(now()->subDays(2))
                ? self::armar('suspendida', Carbon::parse($cuando)->toDateString(), $e, $companyId)
                : null;
        }

        if ($e['nivel'] === 'urgente' && $e['limite']) {
            $faltan = (int) $hoy->diffInDays(Carbon::parse($e['limite'])->startOfDay(), false);

            // Primero el aviso de vencido; cuando ya salió y queda un día o menos, el último.
            if ($faltan <= 1 && self::yaSalio($companyId, 'vencido', (string) $e['limite']) && config('plataforma.suspension.automatica', true)) {
                return self::armar('ultimo_aviso', (string) $e['limite'], $e, $companyId);
            }

            return self::armar('vencido', (string) $e['limite'], $e, $companyId);
        }

        if ($e['nivel'] === 'aviso') {
            if ($e['pendiente'] > 0 && $e['vence']) {
                $faltan = (int) $hoy->diffInDays(Carbon::parse($e['vence'])->startOfDay(), false);

                return $faltan === 0 && self::yaSalio($companyId, 'por_vencer', (string) $e['vence'])
                    ? self::armar('vence_hoy', (string) $e['vence'], $e, $companyId)
                    : self::armar('por_vencer', (string) $e['vence'], $e, $companyId);
            }

            if (($e['estado'] ?? null) === 'prueba' && $e['prueba_hasta'] && Carbon::parse($e['prueba_hasta'])->startOfDay()->gte($hoy)) {
                return self::armar('prueba_por_terminar', (string) $e['prueba_hasta'], $e, $companyId);
            }
        }

        return null;
    }

    // ── El correo ────────────────────────────────────────────────────────────

    /** @return array{tipo:string, referencia:string, asunto:string, html:string, texto:string} */
    private static function armar(string $tipo, string $referencia, array $e, int $companyId): array
    {
        $nombre = PlantillaDeCorreo::e((string) ($e['empresa'] ?? 'su empresa'));
        $pesos = '$ ' . number_format((float) ($e['pendiente'] ?? 0), 0, ',', '.');
        $fecha = fn (?string $f) => $f ? Carbon::parse($f)->locale('es')->isoFormat('D [de] MMMM [de] YYYY') : '';
        $esPrueba = ($e['estado'] ?? null) === 'prueba' && !((float) ($e['pendiente'] ?? 0) > 0);
        $auto = (bool) config('plataforma.suspension.automatica', true);
        $p = PlantillaDeCorreo::netvula();
        $datos = ['Empresa' => $e['empresa'] ?? null, 'Plan' => $e['plan'] ?? null];

        switch ($tipo) {
            case 'por_vencer':
                $asunto = 'Recordatorio: su pago de Netvula vence el ' . Carbon::parse($e['vence'])->locale('es')->isoFormat('D [de] MMMM');
                $p->antetitulo('Recordatorio de pago')->titulo('Su pago vence pronto', 'info')
                    ->parrafo("Hola, <strong>{$nombre}</strong>.")
                    ->parrafo('Le recordamos que tiene un pago de su suscripción a Netvula próximo a vencer. Si lo realiza antes de la fecha, no tiene que hacer nada más.')
                    ->cifra('Valor a pagar', $pesos, 'Vence el ' . $fecha($e['vence']))
                    ->datos($datos + ['Fecha de vencimiento' => $fecha($e['vence'])]);
                break;

            case 'vence_hoy':
                $asunto = 'Su pago de Netvula vence hoy';
                $p->antetitulo('Recordatorio de pago')->titulo('Su pago vence hoy', 'aviso')
                    ->parrafo("Hola, <strong>{$nombre}</strong>.")
                    ->parrafo('Hoy vence el pago de su suscripción a Netvula. Si ya lo realizó, envíenos el comprobante y lo registramos enseguida.')
                    ->cifra('Valor a pagar', $pesos, 'Vence hoy, ' . $fecha($e['vence']))
                    ->datos($datos);
                break;

            case 'prueba_por_terminar':
                $asunto = 'Su periodo de prueba de Netvula termina el ' . Carbon::parse($e['prueba_hasta'])->locale('es')->isoFormat('D [de] MMMM');
                $p->antetitulo('Periodo de prueba')->titulo('Su prueba está por terminar', 'info')
                    ->parrafo("Hola, <strong>{$nombre}</strong>.")
                    ->parrafo('Su periodo de prueba en Netvula termina el <strong>' . $fecha($e['prueba_hasta']) . '</strong>. Para seguir usando la plataforma sin interrupciones, escríbanos y activamos el plan que mejor le quede.')
                    ->datos(['Empresa' => $e['empresa'] ?? null, 'La prueba termina' => $fecha($e['prueba_hasta'])])
                    ->aviso('Sus clientes, facturas y configuración se conservan tal como están al activar el plan.', 'info');
                break;

            case 'vencido':
                $asunto = $esPrueba ? 'Su periodo de prueba de Netvula terminó' : 'Tiene un pago pendiente con Netvula';
                $p->antetitulo($esPrueba ? 'Periodo de prueba' : 'Pago pendiente')->titulo($esPrueba ? 'Su periodo de prueba terminó' : 'Tiene un pago pendiente', 'aviso')
                    ->parrafo("Hola, <strong>{$nombre}</strong>.");

                if ($esPrueba) {
                    $p->parrafo('Su periodo de prueba terminó el <strong>' . $fecha($e['prueba_hasta']) . '</strong> y todavía no hay un plan activo. Escríbanos para activarlo y seguir trabajando con normalidad.');
                } else {
                    $p->parrafo('El pago de su suscripción a Netvula venció el <strong>' . $fecha($e['vence']) . '</strong> y aún no lo vemos registrado. Si ya lo realizó, envíenos el comprobante.')
                        ->cifra('Valor pendiente', $pesos, 'Venció el ' . $fecha($e['vence']));
                }

                $p->datos($datos + ['Acceso al panel hasta' => $auto ? $fecha($e['limite']) : null]);

                if ($auto) {
                    $p->aviso('Para que no se interrumpa su trabajo, tiene plazo hasta el <strong>' . $fecha($e['limite']) . '</strong>. Después de esa fecha se suspende el acceso al panel de administración. <strong>El internet de sus clientes no se ve afectado.</strong>', 'aviso');
                }
                break;

            case 'ultimo_aviso':
                $asunto = 'Último aviso: el acceso a Netvula se suspende después del ' . Carbon::parse($e['limite'])->locale('es')->isoFormat('D [de] MMMM');
                $p->antetitulo('Último aviso')->titulo('Mañana se suspende el acceso al panel', 'peligro')
                    ->parrafo("Hola, <strong>{$nombre}</strong>.")
                    ->parrafo($esPrueba
                        ? 'Su periodo de prueba terminó y todavía no hay un plan activo. El <strong>' . $fecha($e['limite']) . '</strong> es el último día con acceso al panel.'
                        : 'Sigue pendiente el pago de su suscripción. El <strong>' . $fecha($e['limite']) . '</strong> es el último día con acceso al panel.');

                if (!$esPrueba) {
                    $p->cifra('Valor pendiente', $pesos);
                }

                $p->datos($datos + ['Último día con acceso' => $fecha($e['limite'])])
                    ->aviso('Si paga o activa su plan antes de esa fecha, no pasa nada: su cuenta sigue igual. El internet de sus clientes no se ve afectado en ningún caso.', 'info');
                break;

            case 'suspendida':
                $asunto = 'El acceso de su empresa a Netvula fue suspendido';
                $p->antetitulo('Cuenta suspendida')->titulo('El acceso al panel está suspendido', 'peligro')
                    ->parrafo("Hola, <strong>{$nombre}</strong>.")
                    ->parrafo('El acceso de su empresa al panel de Netvula quedó suspendido. ' . PlantillaDeCorreo::e((string) ($e['motivo'] ?? '')));

                if ((float) ($e['pendiente'] ?? 0) > 0) {
                    $p->cifra('Valor pendiente', $pesos);
                }

                $p->datos($datos)
                    ->aviso('<strong>Sus datos están intactos</strong> y el internet de sus clientes, el portal de clientes y las tareas automáticas siguen funcionando. Lo único suspendido es la entrada al panel de administración.', 'info')
                    ->parrafo('Apenas se registre el pago o se active el plan, el acceso vuelve de inmediato.');
                break;

            default: // reactivada
                $asunto = 'Su cuenta de Netvula está activa de nuevo';
                $p->antetitulo('Pago recibido')->titulo('Su cuenta está activa de nuevo', 'ok')
                    ->parrafo("Hola, <strong>{$nombre}</strong>.")
                    ->parrafo('Registramos su pago y el acceso al panel de Netvula quedó habilitado. Ya puede entrar con normalidad.')
                    ->datos($datos)
                    ->parrafo('Gracias por seguir con nosotros.');
        }

        // Cómo responder: WhatsApp de soporte si está cargado; si no, el correo.
        $wa = $e['soporte']['whatsapp'] ?? null;
        $correo = $e['soporte']['correo'] ?? null;

        if ($tipo !== 'reactivada') {
            if ($wa) {
                $p->boton($esPrueba || $tipo === 'prueba_por_terminar' ? 'Activar mi plan por WhatsApp' : 'Enviar el comprobante por WhatsApp',
                    'https://wa.me/' . $wa . '?text=' . rawurlencode('Hola, les escribo de ' . ($e['empresa'] ?? 'mi empresa') . ' por la suscripción de Netvula.'));
            }

            $p->nota(($correo ? 'También puede escribirnos a <a href="mailto:' . PlantillaDeCorreo::e($correo) . '" style="color:#1463ff;">' . PlantillaDeCorreo::e($correo) . '</a>. ' : '')
                . 'Si ya realizó el pago, por favor ignore este mensaje: lo registraremos en cuanto lo veamos.');
        }

        if ($url = self::panelDe($companyId)) {
            $p->enlace($url, 'Su panel:');
        }

        return ['tipo' => $tipo, 'referencia' => $referencia, 'asunto' => $asunto, 'html' => $p->html(), 'texto' => $p->texto()];
    }

    // ── Envío ────────────────────────────────────────────────────────────────

    /** @return array{empresa:string, tipo:string, para:list<string>, asunto:string, enviado:bool, detalle:string} */
    private static function despachar(int $companyId, string $empresa, array $aviso, bool $simular): array
    {
        $para = self::destinatarios($companyId);
        $r = ['empresa' => $empresa, 'tipo' => $aviso['tipo'], 'para' => $para, 'asunto' => $aviso['asunto'], 'enviado' => false, 'detalle' => ''];

        if ($simular || !self::activos()) {
            $r['detalle'] = !$para ? 'La empresa no tiene un correo válido.' : ($simular ? 'Simulación: no se envió.' : 'Los avisos por correo están apagados: no se envió.');

            return $r;
        }

        if (!$para) {
            $r['detalle'] = 'La empresa no tiene un correo válido.';
        } else {
            $copia = filter_var(trim((string) config('plataforma.avisos_correo.copia')), FILTER_VALIDATE_EMAIL) ?: null;
            $envio = Correo::plataforma()->enviar(array_values(array_unique(array_filter(array_merge($para, [$copia])))), $aviso['asunto'], $aviso['html'], $aviso['texto'], [], config('plataforma.soporte.correo') ?: null);
            $r['enviado'] = (bool) $envio['ok'];
            $r['detalle'] = (string) $envio['detalle'];
        }

        // Se anota también el que no tiene correo o falló: mañana no se reintenta el mismo aviso
        // en bucle, y en la consola queda a la vista por qué no salió.
        try {
            DB::table('plataforma_avisos_correo')->insertOrIgnore([
                'company_id' => $companyId, 'tipo' => $aviso['tipo'], 'referencia' => $aviso['referencia'], 'para' => mb_substr(implode(', ', $para), 0, 400),
                'enviado' => $r['enviado'], 'detalle' => mb_substr($r['detalle'], 0, 300), 'created_at' => now(), 'updated_at' => now(),
            ]);
            Bitacora::anotar('empresa.aviso_por_correo', $companyId, ['tipo' => $aviso['tipo'], 'enviado' => $r['enviado'], 'para' => $para], 'empresa', $companyId);
        } catch (\Throwable $e) {
            Log::warning('[Avisos de cuenta] No se pudo anotar el aviso', ['empresa' => $companyId, 'error' => $e->getMessage()]);
        }

        return $r;
    }

    private static function yaSalio(int $companyId, string $tipo, string $referencia): bool
    {
        return DB::table('plataforma_avisos_correo')->where(['company_id' => $companyId, 'tipo' => $tipo, 'referencia' => $referencia])->exists();
    }

    /**
     * A quién se le escribe: al correo de la empresa y a sus administradores.
     *
     * @return list<string>
     */
    private static function destinatarios(int $companyId): array
    {
        $correos = [(string) DB::table('companies')->where('id', $companyId)->value('email')];

        foreach (DB::table('users as u')->join('profiles as p', 'p.id', '=', 'u.profile_id')->where('u.company_id', $companyId)
            ->whereRaw("UPPER(p.name) LIKE 'ADMIN%'")->limit(5)->pluck('u.email') as $c) {
            $correos[] = (string) $c;
        }

        return array_slice(array_values(array_unique(array_filter(array_map(fn ($c) => strtolower(trim($c)), $correos), fn ($c) => filter_var($c, FILTER_VALIDATE_EMAIL)))), 0, 4);
    }

    private static function panelDe(int $companyId): ?string
    {
        $sub = $companyId ? trim((string) DB::table('companies')->where('id', $companyId)->value('subdomain')) : '';

        return $sub !== '' ? 'https://' . $sub . '.' . config('plataforma.dominio', 'netvula.com') : null;
    }
}

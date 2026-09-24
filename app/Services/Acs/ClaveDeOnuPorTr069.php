<?php

namespace App\Services\Acs;

use Illuminate\Support\Facades\Log;

/**
 * Pone la clave de administración de la empresa en una ONU por TR-069.
 *
 * Así la clave con la que la plataforma entra a la página del equipo deja de
 * ser una suposición (la de fábrica) y pasa a ser una que la empresa decidió y
 * que el equipo confirmó.
 *
 * C-Data FD5xx (verificado en FD511GW-X-R371): la cuenta de administración del
 * proveedor es DeviceInfo.X_CATV_TeleComAccount — su usuario es fijo
 * ("adminisp") y la clave se escribe por TR-069. Leerla por TR-069 no sirve
 * para confirmar: el equipo la cambia pero sigue informando la de fábrica. Se
 * confirma entrando a su página web con la clave nueva.
 */
class ClaveDeOnuPorTr069
{
    /**
     * Dónde guarda C-Data la cuenta del proveedor, según el firmware.
     *
     * No todos los FD5xx usan el mismo prefijo: la FD511GW-X-R371 la publica
     * como «X_CATV_» y la FD512XWX como «X_CT-COM_». Con una sola ruta, a la
     * segunda se le contestaba «en esta marca todavía no se cambia por
     * TR-069» y el técnico tenía que ir al equipo a mano, cuando el equipo sí
     * la acepta.
     */
    private const RUTAS_CDATA = [
        'InternetGatewayDevice.DeviceInfo.X_CATV_TeleComAccount',
        'InternetGatewayDevice.DeviceInfo.X_CT-COM_TeleComAccount',
    ];

    private const USUARIO = 'adminisp';

    /**
     * Qué clave dice tener el equipo, releída después del cambio.
     *
     * Vale como confirmación sólo cuando coincide con la que se puso: si el
     * equipo informa otra cosa —o la de fábrica— no se concluye nada.
     */
    private function claveQueInforma(string $acsId): ?string
    {
        try {
            $d = $this->acs->dispositivo($acsId);
        } catch (\Throwable) {
            return null;
        }

        if (!$d) {
            return null;
        }

        $ruta = self::rutaDeLaCuenta($d);

        return $ruta ? (self::nodo($d, $ruta . '.Password')['_value'] ?? null) : null;
    }

    /**
     * Cuál de las rutas conocidas publica de verdad este equipo.
     *
     * @param array<string,mixed> $d
     */
    private static function rutaDeLaCuenta(array $d): ?string
    {
        foreach (self::RUTAS_CDATA as $ruta) {
            if (self::nodo($d, $ruta . '.Password')) {
                return $ruta;
            }
        }

        return null;
    }

    /** La IP por la que el ACS le habla al equipo (su dirección de pedido de conexión). */
    private static function ipDeGestion(array $d): ?string
    {
        $url = (string) (self::nodo($d, 'InternetGatewayDevice.ManagementServer.ConnectionRequestURL')['_value'] ?? '');
        $ip  = parse_url($url, PHP_URL_HOST);

        return is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
    }

    public function __construct(private int $companyId, private ?GenieAcs $acs = null)
    {
        $this->acs ??= GenieAcs::deEmpresa($companyId);
    }

    /** @return array{ok:bool, detalle:string, omitido?:bool} */
    public function asegurar(string $acsId, string $clave): array
    {
        if ($clave === '') {
            return ['ok' => false, 'omitido' => true, 'detalle' => 'La empresa no tiene cargada la clave de administración de las ONU.'];
        }

        $d = $this->acs->dispositivo($acsId);

        if (!$d) {
            return ['ok' => false, 'omitido' => true, 'detalle' => 'El equipo todavía no está en el servidor TR-069.'];
        }

        $ruta = self::rutaDeLaCuenta($d);

        // El equipo informa ese objeto sólo si se le pregunta. Se prueban las
        // dos formas conocidas antes de darlo por imposible.
        if (!$ruta) {
            foreach (self::RUTAS_CDATA as $candidata) {
                try {
                    $this->acs->tarea($acsId, ['name' => 'refreshObject', 'objectName' => $candidata], 20);
                } catch (\Throwable) {
                }
            }

            $d = $this->acs->dispositivo($acsId) ?? $d;
            $ruta = self::rutaDeLaCuenta($d);
        }

        if (!$ruta) {
            return ['ok' => false, 'omitido' => true, 'detalle' => 'Este equipo no permite cambiar su clave de administración por TR-069.'];
        }

        // Por el túnel de la empresa (IP virtual si la red está traducida).
        // Si esa IP es de una red de otra empresa, no se entra: la petición
        // lleva la clave de administración y llegaría a un equipo ajeno.
        $ip  = \App\Services\Vpn\ServidorVpn::ipParaEmpresa(self::ipDeGestion($d), $this->companyId, true);
        $web = $ip ? new Tr069EnPaginaDeOnu($ip) : null;

        // Si la página ya acepta la clave de la empresa, no hay nada que cambiar.
        if ($web?->aceptaCuenta(self::USUARIO, $clave)) {
            return ['ok' => true, 'detalle' => 'El equipo ya tiene la clave de administración de la empresa (confirmado en su página).'];
        }

        try {
            $r = $this->acs->tarea($acsId, ['name' => 'setParameterValues', 'parameterValues' => [
                [$ruta . '.Password', $clave, 'xsd:string'],
                [$ruta . '.Enable', true, 'xsd:boolean'],
            ]]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'detalle' => 'El servidor TR-069 no aceptó el cambio de clave: ' . mb_substr($e->getMessage(), 0, 150)];
        }

        if (!($r['hecha'] ?? false)) {
            $falla = ($r['id'] ?? null) ? $this->acs->fallaDeTarea($acsId, (string) $r['id']) : null;

            if ($falla) {
                $this->acs->borrarTarea((string) $r['id']);

                return ['ok' => false, 'detalle' => "El equipo rechazó la clave de administración: {$falla}"];
            }

            return ['ok' => true, 'detalle' => 'Clave de administración de la empresa en cola: se aplica en el próximo reporte del equipo.'];
        }

        // ── Confirmar que quedó ───────────────────────────────────────────
        //
        // Dos formas, y la primera es la buena: preguntarle al equipo qué
        // clave tiene ahora. La FD511GW-X-R371 no sirve para esto —la cambia
        // pero sigue informando la de fábrica—, pero la FD512XWX devuelve la
        // nueva, y entonces no hay nada más que comprobar.
        sleep(2);

        if (($this->claveQueInforma($acsId) ?? null) === $clave) {
            return ['ok' => true, 'detalle' => 'Clave de administración de la empresa puesta y confirmada por el propio equipo (usuario adminisp).'];
        }

        // La segunda: entrar a su página. Muchos C-Data no la exponen del lado
        // de la gestión —ningún puerto web abierto—, y eso NO es que la clave
        // haya fallado: es que no hay por dónde mirar. Antes se informaba como
        // un fracaso y mandaba al técnico a revisar un equipo que estaba bien.
        if (!$web || !$web->alcanzable()) {
            return ['ok' => true, 'detalle' => 'Clave de administración puesta por TR-069. No se pudo confirmar: este equipo no publica su página de administración por el túnel.'];
        }

        $ok = $web->aceptaCuenta(self::USUARIO, $clave);

        if (!$ok) {
            Log::warning('[TR-069] La clave de administración de la ONU no se pudo confirmar', ['acs_id' => $acsId]);
        }

        return [
            'ok'      => $ok,
            'detalle' => $ok
                ? 'Clave de administración de la empresa puesta y confirmada en la página del equipo (usuario adminisp).'
                : 'Se pidió el cambio de clave pero la página del equipo no acepta la clave nueva.',
        ];
    }

    /** @return array<string,mixed>|null */
    private static function nodo(array $d, string $ruta): ?array
    {
        foreach (explode('.', $ruta) as $parte) {
            if (!is_array($d) || !array_key_exists($parte, $d)) {
                return null;
            }
            $d = $d[$parte];
        }

        return is_array($d) && array_key_exists('_value', $d) ? $d : null;
    }
}

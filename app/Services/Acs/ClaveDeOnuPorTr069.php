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
    private const CDATA = 'InternetGatewayDevice.DeviceInfo.X_CATV_TeleComAccount';

    private const USUARIO = 'adminisp';

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

        // El equipo informa ese objeto sólo si se le pregunta.
        if (!self::nodo($d, self::CDATA . '.Password')) {
            try {
                $this->acs->tarea($acsId, ['name' => 'refreshObject', 'objectName' => self::CDATA]);
            } catch (\Throwable) {
            }

            $d = $this->acs->dispositivo($acsId) ?? $d;
        }

        $nodo = self::nodo($d, self::CDATA . '.Password');

        if (!$nodo) {
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
                [self::CDATA . '.Password', $clave, 'xsd:string'],
                [self::CDATA . '.Enable', true, 'xsd:boolean'],
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

        // Se confirma en la página: la clave nueva tiene que entrar.
        if (!$web) {
            return ['ok' => false, 'detalle' => 'Clave de administración puesta por TR-069 pero sin confirmar: el servidor no llega a la página del equipo por el túnel de la empresa.'];
        }

        sleep(2);
        $ok = $web->aceptaCuenta(self::USUARIO, $clave);

        if (!$ok) {
            Log::error('[TR-069] La clave de administración de la ONU no quedó', ['acs_id' => $acsId]);
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

<?php

namespace App\Services\Acs;

use Illuminate\Support\Facades\Log;

/**
 * Enciende el cliente TR-069 de una ONU desde su página web.
 *
 * Hay equipos que toman la conexión de gestión desde la OLT pero ignoran la
 * dirección del ACS que les llega por DHCP (opción 43): su cliente TR-069 queda
 * apagado y nunca se reportan. Las C-Data FD5xx (Realtek, servidor Boa) son
 * así. Como la plataforma llega a la ONU por la red de gestión, entra a su
 * página y lo enciende, igual que lo haría un técnico.
 *
 * Verificado contra una C-Data FD511GW-X-R371 (SW V2.1.15):
 *   login   POST /boaform/admin/formLogin        username, psd
 *   leer    GET  /net_tr069.asp
 *   guardar POST /boaform/admin/formTR069Config  (campos del formulario)
 *   salir   POST /boaform/admin/formLogout
 * La sesión va por IP, no por cookie.
 */
class Tr069EnPaginaDeOnu
{
    /** Cuenta de fábrica de las ONU C-Data, si la de la empresa no entra. */
    private const CUENTAS_DE_FABRICA = [['adminisp', 'adminisp']];

    public function __construct(private string $ip) {}

    /**
     * @param  list<array{0:string,1:string}>  $cuentas  la de la empresa primero
     * @return array{ok:bool, detalle:string, ya_estaba?:bool}
     */
    public function encender(array $cuentas, string $url, int $intervalo = 300, ?string $usuarioAcs = null, ?string $claveAcs = null): array
    {
        if (!filter_var($this->ip, FILTER_VALIDATE_IP)) {
            return ['ok' => false, 'detalle' => 'Sin IP de gestión para entrar a la página del equipo.'];
        }

        // En C-Data el usuario del proveedor es fijo (adminisp): con la clave de
        // la empresa puesta por TR-069, se entra con ese usuario y esa clave.
        $conUsuarioFijo = array_map(fn ($c) => ['adminisp', (string) ($c[1] ?? '')], $cuentas);

        $cuentas = array_values(array_unique(array_filter(
            array_merge($cuentas, $conUsuarioFijo, self::CUENTAS_DE_FABRICA),
            fn ($c) => ($c[0] ?? '') !== '' && ($c[1] ?? '') !== ''
        ), SORT_REGULAR));

        $pagina = null;

        foreach ($cuentas as [$usuario, $clave]) {
            $this->pedir('/boaform/admin/formLogin', ['username' => $usuario, 'psd' => (string) $clave]);
            [$codigo, $html] = $this->pedir('/net_tr069.asp');

            if ($codigo === 200 && str_contains($html, 'formTR069Config')) {
                $pagina = $html;
                break;
            }
        }

        if ($pagina === null) {
            // No es lo mismo «la clave está mal» que «no hay página». Muchas
            // ONU cierran todo lo que entra por el lado de la gestión: el
            // equipo pide su IP por DHCP y la renueva —o sea que está vivo—
            // pero no contesta ni un ping. Decirle al operador que cargue la
            // clave correcta lo manda a buscar una que jamás habría servido.
            if (!$this->alcanzable()) {
                return [
                    'ok'         => false,
                    'sin_pagina' => true,
                    'detalle'    => 'El equipo tomó su IP de gestión pero no publica su página: cierra todo lo que entra. '
                        . 'No es la clave, y no hay nada que cargar. La dirección del TR-069 le llega igual por DHCP; '
                        . 'para que la tome hay que reiniciarlo.',
                ];
            }

            return [
                'ok'      => false,
                'detalle' => 'Se llega a la página del equipo pero no se pudo entrar, ni con la cuenta de administrador de ONU '
                    . 'de la empresa ni con la de fábrica. Cargue la correcta en Acceso remoto → Aprovisionamiento.',
            ];
        }

        $actual = self::leer($pagina);

        if ($actual['encendido'] && $actual['acsURL'] === $url && $actual['inform'] === '1' && (int) $actual['informInterval'] <= $intervalo) {
            $this->salir();

            return ['ok' => true, 'ya_estaba' => true, 'detalle' => 'El TR-069 del equipo ya estaba encendido con la dirección correcta.'];
        }

        // Se conserva lo que el equipo ya tenía y no hace falta cambiar
        // (usuario y clave de conexión: el ACS los ajusta después).
        $this->pedir('/boaform/admin/formTR069Config', [
            'enable'            => '1',
            'acsURL'            => $url,
            'acsUser'           => $usuarioAcs ?? ($actual['acsUser'] !== '' ? $actual['acsUser'] : 'acs'),
            'acsPwd'            => $claveAcs ?? ($actual['acsPwd'] !== '' ? $actual['acsPwd'] : 'acs'),
            'certauth'          => '0',
            'inform'            => '1',
            'informInterval'    => (string) $intervalo,
            'connReqUser'       => $actual['connReqUser'] !== '' ? $actual['connReqUser'] : 'cpe',
            'connReqPwd'        => $actual['connReqPwd'] !== '' ? $actual['connReqPwd'] : 'cpe',
            'stunserveraddr'    => $actual['stunserveraddr'],
            'stunserverport'    => $actual['stunserverport'] !== '' ? $actual['stunserverport'] : '3478',
            'applyTr069Config'  => 'applyTr069Config',
            'action'            => 'sv',
            'submit-url'        => '/net_tr069.asp',
            'cwmp_enabled1'     => '1',
        ]);

        // Sólo cuenta lo que la página muestra después.
        [, $despues] = $this->pedir('/net_tr069.asp');
        $quedo = self::leer($despues);
        $this->salir();

        $ok = $quedo['encendido'] && $quedo['acsURL'] === $url && $quedo['inform'] === '1';

        if (!$ok) {
            Log::error('[TR-069 en ONU] La página no quedó como se pidió', ['ip' => $this->ip, 'leido' => array_diff_key($quedo, array_flip(['acsPwd', 'connReqPwd']))]);
        }

        return [
            'ok'      => $ok,
            'detalle' => $ok
                ? "TR-069 encendido en el equipo: {$url}, reporte cada {$intervalo} s."
                : 'Se entró a la página del equipo pero el TR-069 no quedó encendido.',
        ];
    }

    /** @return array<string,mixed> */
    private static function leer(string $html): array
    {
        $valor = function (string $nombre) use ($html): string {
            return preg_match("/name=['\"]?{$nombre}['\"]?[^>]*value=['\"]([^'\"]*)['\"]/i", $html, $m) ? html_entity_decode($m[1]) : '';
        };

        return [
            'encendido'       => (bool) preg_match('/var\s+cwmp_enabled\s*=\s*1\s*;/', $html),
            'acsURL'          => $valor('acsURL'),
            'acsUser'         => $valor('acsUser'),
            'acsPwd'          => $valor('acsPwd'),
            'inform'          => preg_match("/name=['\"]?inform['\"]?\s+value=['\"]?(\d)['\"]?[^>]*checked/i", $html, $m) ? $m[1] : '',
            'informInterval'  => $valor('informInterval'),
            'connReqUser'     => $valor('connReqUser'),
            'connReqPwd'      => $valor('connReqPwd'),
            'stunserveraddr'  => $valor('stunserveraddr'),
            'stunserverport'  => $valor('stunserverport'),
        ];
    }

    /** ¿La página del equipo acepta esta cuenta? Entra, mira la página de TR-069 y sale. */
    /**
     * ¿Este equipo publica siquiera su página de administración?
     *
     * Muchos C-Data la cierran del lado de la gestión: ningún puerto web
     * abierto. Saberlo evita informar como fallada una clave que sí quedó,
     * sólo porque no hay por dónde mirarla.
     */
    public function alcanzable(int $segundos = 3): bool
    {
        foreach ([80, 8080, 443] as $puerto) {
            $con = @fsockopen($this->ip, $puerto, $errno, $error, $segundos);

            if ($con) {
                fclose($con);

                return true;
            }
        }

        return false;
    }

    public function aceptaCuenta(string $usuario, string $clave): bool
    {
        if (!filter_var($this->ip, FILTER_VALIDATE_IP) || $clave === '') {
            return false;
        }

        $this->salir();
        $this->pedir('/boaform/admin/formLogin', ['username' => $usuario, 'psd' => $clave]);
        [$codigo, $html] = $this->pedir('/net_tr069.asp');
        $this->salir();

        return $codigo === 200 && str_contains($html, 'formTR069Config');
    }

    private function salir(): void
    {
        $this->pedir('/boaform/admin/formLogout', []);
    }

    /** @return array{0:int, 1:string} */
    private function pedir(string $ruta, ?array $post = null): array
    {
        $c = curl_init("http://{$this->ip}{$ruta}");

        curl_setopt_array($c, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        if ($post !== null) {
            curl_setopt($c, CURLOPT_POST, true);
            curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($post));
        }

        $cuerpo = curl_exec($c);
        $codigo = (int) curl_getinfo($c, CURLINFO_HTTP_CODE);
        curl_close($c);

        return [$codigo, is_string($cuerpo) ? $cuerpo : ''];
    }
}

<?php

namespace App\Services\Importador\Fuentes;

use App\Services\Importador\Normalizador;

/**
 * Mikrowisp: cada empresa lo tiene en su propio servidor. Todos los comandos
 * son POST JSON a https://<servidor>/api/v1/<Comando> con el "token" en el
 * cuerpo, y responden {"estado":"exito", ...}.
 *
 * - GetClientsDetails: datos[] con servicios[] (idperfil, perfil, nodo, costo,
 *   ip, mac, pppuser, ppppass) y facturacion (facturas_nopagadas, total_facturas).
 * - GetRouters {id:-1}: routers[] (id, nombre, ip). El "nodo" del servicio es
 *   el id del router.
 *
 * La documentación no dice si GetClientsDetails sin filtro lista a todos. Se
 * prueba así y, si no, se recorren los id de cliente uno por uno.
 *
 * Referencia: https://mikrowisp.docs.apiary.io/ (API v1.1).
 */
class Mikrowisp extends FuenteApi
{
    /** Id de cliente seguidos sin resultado para dar por terminado el recorrido. */
    private const HUECO_MAXIMO = 300;

    private const ID_MAXIMO = 100000;

    private function base(): string
    {
        $u = rtrim($this->url, '/');
        $u = preg_replace('#/api/v1$#', '', $u);

        return $u . '/api/v1';
    }

    /** @return array<string,mixed> */
    private function comando(string $comando, array $params = [], bool $toleraError = false): array
    {
        $r = $this->pedir('POST', $this->base() . '/' . $comando, [], ['token' => $this->token] + $params)['json'];

        if (!is_array($r)) {
            throw new \RuntimeException('La dirección responde, pero no parece la API de Mikrowisp.');
        }

        if (strtolower((string) ($r['estado'] ?? '')) !== 'exito') {
            $mensaje = (string) ($r['mensaje'] ?? $r['message'] ?? 'error desconocido');

            if ($toleraError) {
                return $r;
            }
            if (preg_match('/token/i', $mensaje)) {
                throw new \RuntimeException('Mikrowisp rechazó el token de la API. Revisá que esté bien copiado y que la API esté activa.');
            }

            throw new \RuntimeException("Mikrowisp respondió: {$mensaje}");
        }

        return $r;
    }

    public function probar(): string
    {
        $routers = $this->routers();

        return 'Conectado a Mikrowisp: ' . count($routers) . ' router(s).';
    }

    /** @return array<int,string> id => nombre */
    public function routers(): array
    {
        $r = $this->comando('GetRouters', ['id' => -1]);
        $routers = [];

        foreach ((array) ($r['routers'] ?? []) as $x) {
            if (is_array($x) && isset($x['id'])) {
                $routers[(int) $x['id']] = (string) ($x['nombre'] ?? '');
            }
        }

        return $routers;
    }

    public function clientes(callable $avance, callable $debeParar): array
    {
        $avance('Leyendo los routers de Mikrowisp…');
        $routers = $this->routers();

        $avance('Leyendo clientes de Mikrowisp…');
        $todos = $this->comando('GetClientsDetails', [], true);
        $datos = strtolower((string) ($todos['estado'] ?? '')) === 'exito' ? (array) ($todos['datos'] ?? []) : [];

        $crudos = [];

        if (count($datos) > 1) {
            $crudos = $datos;
        } else {
            // Sin listado completo: de a un id.
            $hueco = 0;
            for ($id = 1; $id <= self::ID_MAXIMO && $hueco < self::HUECO_MAXIMO; $id++) {
                if ($debeParar()) {
                    throw new \RuntimeException('Lectura cancelada.');
                }

                $r = $this->comando('GetClientsDetails', ['idcliente' => $id], true);
                $encontrados = strtolower((string) ($r['estado'] ?? '')) === 'exito' ? (array) ($r['datos'] ?? []) : [];

                if ($encontrados) {
                    array_push($crudos, ...$encontrados);
                    $hueco = 0;
                } else {
                    $hueco++;
                }

                if ($id % 25 === 0) {
                    $avance("Leyendo clientes de Mikrowisp uno por uno: " . count($crudos) . " encontrados (id {$id})…");
                }
            }
        }

        $clientes = [];
        foreach ($crudos as $c) {
            if (is_array($c)) {
                $clientes[] = self::normalizar($c, $routers);
            }
        }

        return $clientes;
    }

    /**
     * @param  array<string,mixed> $c
     * @param  array<int,string>   $routers
     * @return array<string,mixed>
     */
    public static function normalizar(array $c, array $routers): array
    {
        $servicios = array_values(array_filter((array) ($c['servicios'] ?? []), 'is_array'));
        $internet = array_values(array_filter($servicios, fn ($s) => in_array(strtolower((string) ($s['tiposervicio'] ?? 'internet')), ['internet', ''], true)));
        $s = $internet[0] ?? $servicios[0] ?? [];

        $avisos = [];
        if (count($internet ?: $servicios) > 1) {
            $avisos[] = 'Tiene ' . count($internet ?: $servicios) . ' servicios en Mikrowisp: se importa el primero.';
        }
        if (!$s) {
            $avisos[] = 'No tiene servicios en Mikrowisp.';
        }

        $pppUser = trim((string) ($s['pppuser'] ?? ''));
        $nodo = (int) ($s['nodo'] ?? 0);

        $facturacion = is_array($c['facturacion'] ?? null) ? $c['facturacion'] : [];
        $saldo = (int) ($facturacion['facturas_nopagadas'] ?? 0) > 0 ? ($facturacion['total_facturas'] ?? null) : 0;

        return Normalizador::cliente([
            'external_id'   => (string) ($c['id'] ?? ''),
            'nombres'       => (string) ($c['nombre'] ?? ''),
            'dni'           => (string) ($c['cedula'] ?? ''),
            'email'         => (string) ($c['correo'] ?? ''),
            'telefono'      => (string) (($c['movil'] ?? '') ?: ($c['telefono'] ?? '')),
            'direccion'     => (string) (($c['direccion_principal'] ?? '') ?: ($s['direccion'] ?? '')),
            'plan'          => (string) ($s['perfil'] ?? ''),
            'plan_precio'   => $s['costo'] ?? null,
            'tipo_conexion' => $pppUser !== '' ? 'pppoe' : 'static',
            'pppoe_usuario' => $pppUser,
            'pppoe_clave'   => (string) ($s['ppppass'] ?? ''),
            'ip'            => (string) ($s['ip'] ?? ''),
            'mac'           => (string) ($s['mac'] ?? ''),
            'router'        => $nodo ? ($routers[$nodo] ?? "Router #{$nodo}") : '',
            'estado'        => (string) ($c['estado'] ?? ''),
            'saldo'         => $saldo,
            'avisos_origen' => $avisos,
        ]);
    }
}

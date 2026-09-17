<?php

namespace App\Services\Importador\Fuentes;

use App\Services\Importador\Normalizador;

/**
 * WispHub: API REST (Django REST Framework) con "Authorization: Api-Key …" y
 * paginado limit/offset ({count, next, previous, results}).
 *
 * - /api/clientes/ trae un registro por servicio (id_servicio). El usuario
 *   PPPoE es cliente_rb y la contraseña password_servicio.
 * - /api/plan-internet/ trae id, nombre y tipo; la velocidad y el precio
 *   están en /plan-internet/pppoe/{id}/ o /plan-internet/queue/{id}/.
 *
 * Referencia: https://wisphub.net/api-docs/ (spec OpenAPI 1.2.0).
 */
class WispHub extends FuenteApi
{
    public const URL_POR_DEFECTO = 'https://api.wisphub.net';

    private const POR_PAGINA = 100;

    private function base(): string
    {
        $u = rtrim($this->url ?: self::URL_POR_DEFECTO, '/');

        return str_ends_with($u, '/api') ? $u : $u . '/api';
    }

    private function get(string $ruta): mixed
    {
        return $this->pedir('GET', $this->base() . $ruta, ['Authorization' => 'Api-Key ' . $this->token])['json'];
    }

    public function probar(): string
    {
        $r = $this->get('/clientes/?limit=1&offset=0');

        if (!is_array($r) || !array_key_exists('count', $r)) {
            throw new \RuntimeException('La dirección responde, pero no parece la API de WispHub.');
        }

        return "Conectado a WispHub: {$r['count']} servicios.";
    }

    public function clientes(callable $avance, callable $debeParar): array
    {
        $avance('Leyendo los planes de WispHub…');
        $planes = $this->planes();

        $clientes = [];
        $offset = 0;
        $total = null;

        do {
            if ($debeParar()) {
                throw new \RuntimeException('Lectura cancelada.');
            }

            $r = $this->get('/clientes/?limit=' . self::POR_PAGINA . '&offset=' . $offset);

            if (!is_array($r) || !isset($r['results']) || !is_array($r['results'])) {
                throw new \RuntimeException('WispHub devolvió una respuesta inesperada al listar clientes.');
            }

            $total ??= (int) ($r['count'] ?? 0);

            foreach ($r['results'] as $c) {
                if (is_array($c)) {
                    $clientes[] = self::normalizar($c, $planes);
                }
            }

            $offset += self::POR_PAGINA;
            $avance('Leyendo clientes de WispHub: ' . count($clientes) . ' de ' . $total . '…');
        } while (!empty($r['next']) && count($r['results']) > 0 && $offset < 100000);

        return $clientes;
    }

    /** @return array<int, array{nombre:string,tipo:string,precio:?float,bajada:?int,subida:?int,perfil:string}> */
    public function planes(): array
    {
        $planes = [];
        $offset = 0;

        do {
            $r = $this->get('/plan-internet/?limit=' . self::POR_PAGINA . '&offset=' . $offset);
            $lista = is_array($r) ? ($r['results'] ?? (array_is_list($r) ? $r : [])) : [];

            foreach ($lista as $p) {
                if (!is_array($p) || !isset($p['id'])) {
                    continue;
                }

                $tipo = (string) ($p['tipo'] ?? '');
                $detalle = $this->detallePlan((int) $p['id'], $tipo);

                $planes[(int) $p['id']] = [
                    'nombre' => (string) ($p['nombre'] ?? ''),
                    'tipo'   => $tipo,
                    'precio' => Normalizador::dinero($detalle['precio'] ?? null),
                    'bajada' => Normalizador::velocidad($detalle['bajada'] ?? null),
                    'subida' => Normalizador::velocidad($detalle['subida'] ?? null),
                    'perfil' => (string) ($detalle['perfil'] ?? ''),
                ];
            }

            $offset += self::POR_PAGINA;
        } while (is_array($r) && !empty($r['next']) && $offset < 5000);

        return $planes;
    }

    /** El detalle con velocidad y precio. Si no se puede leer, el plan queda sin esos datos. */
    private function detallePlan(int $id, string $tipo): array
    {
        $rutas = str_contains(strtolower($tipo), 'ppp')
            ? ["/plan-internet/pppoe/{$id}/", "/plan-internet/queue/{$id}/"]
            : ["/plan-internet/queue/{$id}/", "/plan-internet/pppoe/{$id}/"];

        foreach ($rutas as $ruta) {
            try {
                $d = $this->get($ruta);
                if (is_array($d) && $d) {
                    return $d;
                }
            } catch (\RuntimeException) {
                // El otro tipo de plan: se prueba la otra ruta.
            }
        }

        return [];
    }

    /**
     * @param  array<string,mixed> $c
     * @param  array<int, array<string,mixed>> $planes
     * @return array<string,mixed>
     */
    public static function normalizar(array $c, array $planes): array
    {
        $planId = is_array($c['plan_internet'] ?? null) ? (int) ($c['plan_internet']['id'] ?? 0) : 0;
        $plan = $planes[$planId] ?? null;
        $planNombre = is_array($c['plan_internet'] ?? null) ? (string) ($c['plan_internet']['nombre'] ?? '') : '';

        $tipoPlan = (string) ($plan['tipo'] ?? '');
        $usuarioRb = trim((string) ($c['cliente_rb'] ?? $c['usuario_rb'] ?? ''));
        $clave = (string) ($c['password_servicio'] ?? '');

        // En un plan de colas cliente_rb es el nombre de la cola, no un usuario PPPoE.
        $esPppoe = $tipoPlan !== ''
            ? str_contains(strtolower($tipoPlan), 'ppp')
            : ($usuarioRb !== '' && $clave !== '');

        $direccion = trim((string) ($c['direccion'] ?? ''));
        $localidad = trim((string) ($c['localidad'] ?? ''));
        if ($localidad !== '' && !str_contains(mb_strtolower($direccion), mb_strtolower($localidad))) {
            $direccion = trim($direccion . ', ' . $localidad, ', ');
        }

        $router = is_array($c['router'] ?? null) ? (string) ($c['router']['nombre'] ?? '') : '';
        $zona = is_array($c['zona'] ?? null) ? (string) ($c['zona']['nombre'] ?? '') : '';

        return Normalizador::cliente([
            'external_id'   => (string) ($c['id_servicio'] ?? ''),
            'nombres'       => (string) ($c['nombre'] ?? ''),
            'apellidos'     => (string) ($c['apellidos'] ?? ''),
            'dni'           => (string) ($c['cedula'] ?? ''),
            'email'         => (string) ($c['email'] ?? ''),
            'telefono'      => (string) ($c['telefono'] ?? ''),
            'direccion'     => $direccion,
            'plan'          => $planNombre ?: (string) ($plan['nombre'] ?? ''),
            'plan_precio'   => $c['precio_plan'] ?? ($plan['precio'] ?? null),
            'plan_bajada'   => $plan['bajada'] ?? null,
            'plan_subida'   => $plan['subida'] ?? null,
            'tipo_conexion' => $esPppoe ? 'pppoe' : 'static',
            'pppoe_usuario' => $esPppoe ? $usuarioRb : '',
            'pppoe_clave'   => $esPppoe ? $clave : '',
            'pppoe_perfil'  => $esPppoe ? (string) ($plan['perfil'] ?? '') : '',
            'ip'            => (string) ($c['ip'] ?? ''),
            'mac'           => (string) ($c['mac_cpe'] ?? ''),
            'router'        => $router ?: $zona,
            'estado'        => (string) ($c['estado'] ?? ''),
            'dia_pago'      => $c['fecha_corte'] ?? null,
            'saldo'         => $c['saldo'] ?? null,
        ]);
    }
}

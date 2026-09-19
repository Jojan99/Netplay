<?php

namespace App\Services\Red;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cómo se reconoce a un cliente dentro del MikroTik.
 *
 * La plataforma siempre lo buscó por el documento en el comment, porque así lo
 * escribe ella misma. Pero los clientes que vienen importados de otra
 * plataforma ya estaban configurados en el router con el comment que usaba
 * aquélla —en WispHub, el nombre del servicio: "juliethye", "brayanca"— y
 * quedaban invisibles: no se los podía suspender, reactivar, ni ver su estado.
 *
 * Acá vive el único criterio, en orden de confianza:
 *
 *   1. El documento (comment con los mismos dígitos que la cédula).
 *   2. El nombre que tenía en la plataforma de origen (clientes_externos).
 *   3. La IP fija de su ficha.
 *
 * La IP va última y con cuidado: si esa entrada del router lleva el nombre o el
 * documento de otro cliente de la empresa, no se la queda nadie.
 *
 * Lo que se ESCRIBE en el router no cambia: las entradas nuevas siguen
 * llevando el documento en el comment.
 */
class IdentidadEnElRouter
{
    /** @var array<int, array<int, array<string,mixed>>> empresa => (user_id => identidad) */
    private static array $porEmpresa = [];

    /** @var array<int, array<string, int|false>> empresa => (clave => user_id, o false si la clave es ambigua) */
    private static array $indices = [];

    /**
     * Todos los clientes de la empresa con sus identificadores.
     *
     * Se arma una sola vez por petición: las rutinas que recorren el router
     * consultan esto cientos de veces.
     *
     * @return array<int, array<string,mixed>>
     */
    public static function deEmpresa(int $companyId): array
    {
        if (isset(self::$porEmpresa[$companyId])) {
            return self::$porEmpresa[$companyId];
        }

        $filas = DB::table('user_data as ud')
            ->join('users as u', 'u.id', '=', 'ud.user_id')
            ->leftJoin('tabla_ips as t', 't.id', '=', 'ud.ip_assignment_id')
            ->where('u.company_id', $companyId)
            ->where('ud.company_id', $companyId)
            ->get([
                'ud.user_id', 'ud.dni', 'ud.names', 'ud.lastname', 'ud.active', 'ud.status',
                'ud.status_internet_id', 'ud.connection_type', 'ud.pppoe_user', 'ud.router_id',
                'u.username', 't.ip',
            ]);

        // El nombre que tenía en la plataforma de origen. Puede haber más de uno
        // (WispHub y Mikrowisp) y es lo que el router trae en el comment.
        $origenes = [];

        if (Schema::hasTable('clientes_externos')) {
            foreach (DB::table('clientes_externos')->where('company_id', $companyId)->get(['user_id', 'external_id']) as $x) {
                $valor = trim((string) $x->external_id);

                if ($valor !== '') {
                    $origenes[(int) $x->user_id][] = $valor;
                }
            }
        }

        $identidades = [];

        foreach ($filas as $f) {
            $userId = (int) $f->user_id;

            $identidades[$userId] = [
                'user_id'         => $userId,
                'dni'             => (string) $f->dni,
                'documento'       => self::digitos((string) $f->dni),
                'nombre'          => trim("{$f->names} {$f->lastname}"),
                'username'        => (string) $f->username,
                'origen'          => array_values(array_unique($origenes[$userId] ?? [])),
                'ip'              => $f->ip ? trim((string) $f->ip) : null,
                'pppoe_user'      => $f->pppoe_user ? trim((string) $f->pppoe_user) : null,
                'connection_type' => (string) ($f->connection_type ?: 'static'),
                'router_id'       => $f->router_id ? (int) $f->router_id : null,
                'activo'          => (bool) $f->active,
                'suspendido'      => (int) $f->status === 1,
                'status_internet_id' => (int) $f->status_internet_id,
            ];
        }

        return self::$porEmpresa[$companyId] = $identidades;
    }

    /** @return array<string,mixed>|null */
    public static function deUsuario(int $userId, int $companyId): ?array
    {
        return self::deEmpresa($companyId)[$userId] ?? null;
    }

    /** @return array<string,mixed>|null */
    public static function deDocumento(string $dni, int $companyId): ?array
    {
        $buscado = self::digitos($dni);

        if ($buscado === '') {
            return null;
        }

        foreach (self::deEmpresa($companyId) as $i) {
            if ($i['documento'] === $buscado) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Índice para el camino inverso: una entrada del router → de quién es.
     *
     * Las claves repetidas entre dos clientes quedan en false: con dos
     * candidatos no se puede saber cuál es y es mejor no adivinar.
     *
     * @return array<string, int|false>
     */
    public static function indice(int $companyId): array
    {
        if (isset(self::$indices[$companyId])) {
            return self::$indices[$companyId];
        }

        $indice = [];

        $poner = function (string $clave, int $userId) use (&$indice): void {
            if (!array_key_exists($clave, $indice)) {
                $indice[$clave] = $userId;
            } elseif ($indice[$clave] !== $userId) {
                $indice[$clave] = false;
            }
        };

        foreach (self::deEmpresa($companyId) as $i) {
            if ($i['documento'] !== '') {
                $poner('doc:' . $i['documento'], $i['user_id']);
            }

            foreach ($i['origen'] as $nombre) {
                $poner('nom:' . mb_strtolower($nombre), $i['user_id']);
            }

            if ($i['ip']) {
                $poner('ip:' . $i['ip'], $i['user_id']);
            }

            if ($i['pppoe_user']) {
                $poner('ppp:' . mb_strtolower($i['pppoe_user']), $i['user_id']);
            }
        }

        return self::$indices[$companyId] = $indice;
    }

    /**
     * Por qué vía una entrada del router es de ese cliente: 'documento',
     * 'nombre', 'pppoe', 'ip', o null si no es suya.
     *
     * @param  array<string,mixed> $identidad
     * @param  array<string,mixed> $entrada    fila del router (ARP, address-list o secret)
     * @param  bool  $porIp  Si se acepta reconocerlo por la IP de su ficha.
     */
    public static function via(array $identidad, array $entrada, bool $porIp = true): ?string
    {
        $comment = trim((string) ($entrada['comment'] ?? ''));
        $nombre = trim((string) ($entrada['name'] ?? ''));
        $direccion = trim((string) ($entrada['address'] ?? ''));

        if ($identidad['documento'] !== '' && $comment !== '' && self::digitos($comment) === $identidad['documento']) {
            return 'documento';
        }

        if ($nombre !== '' && $identidad['pppoe_user'] && mb_strtolower($nombre) === mb_strtolower($identidad['pppoe_user'])) {
            return 'pppoe';
        }

        foreach ($identidad['origen'] as $origen) {
            $origen = mb_strtolower($origen);

            if (($comment !== '' && mb_strtolower($comment) === $origen) || ($nombre !== '' && mb_strtolower($nombre) === $origen)) {
                return 'nombre';
            }
        }

        if ($porIp && $identidad['ip'] && $direccion !== '' && $direccion === $identidad['ip']) {
            return 'ip';
        }

        return null;
    }

    /**
     * Las entradas del router que son de ese cliente, en orden de confianza.
     *
     * Primero las que lo nombran (documento, usuario PPPoE o nombre de origen).
     * Sólo si ninguna lo nombra se acepta la que tiene su IP, y siempre que esa
     * entrada no esté a nombre de otro cliente de la empresa.
     *
     * @param  array<int, array<string,mixed>> $entradas
     * @param  array<string,mixed>             $identidad
     * @return array<int, array<string,mixed>> las entradas, cada una con 'via'
     */
    public static function suyas(array $entradas, array $identidad, ?int $companyId = null): array
    {
        $porNombre = [];
        $porIp = [];

        foreach ($entradas as $e) {
            $via = self::via($identidad, $e, false);

            if ($via !== null) {
                $porNombre[] = $e + ['via' => $via];
                continue;
            }

            if (self::via($identidad, $e, true) === 'ip' && !self::esDeOtro($e, $identidad, $companyId)) {
                $porIp[] = $e + ['via' => 'ip'];
            }
        }

        return $porNombre ?: $porIp;
    }

    /** ¿La entrada lleva el nombre o el documento de otro cliente de la empresa? */
    private static function esDeOtro(array $entrada, array $identidad, ?int $companyId): bool
    {
        if (!$companyId) {
            return false;
        }

        $otro = self::clienteDeEntrada($companyId, $entrada, false);

        return $otro !== null && $otro['user_id'] !== $identidad['user_id'];
    }

    /**
     * A quién le corresponde una entrada del router, distinguiendo el caso en
     * que dos clientes se disputan la misma clave.
     *
     * @return array{estado:'cliente'|'ambiguo'|'desconocido', identidad:?array, clave:?string, via:?string}
     */
    public static function resolver(int $companyId, array $entrada, bool $porIp = true): array
    {
        $indice = self::indice($companyId);

        foreach (self::clavesDe($entrada, $porIp) as $via => $clave) {
            if (!array_key_exists($clave, $indice)) {
                continue;
            }

            $userId = $indice[$clave];

            if ($userId === false) {
                return ['estado' => 'ambiguo', 'identidad' => null, 'clave' => $clave, 'via' => (string) $via];
            }

            return [
                'estado'    => 'cliente',
                'identidad' => self::deEmpresa($companyId)[$userId] ?? null,
                'clave'     => $clave,
                'via'       => (string) $via,
            ];
        }

        return ['estado' => 'desconocido', 'identidad' => null, 'clave' => null, 'via' => null];
    }

    /**
     * Las claves del índice con las que se puede reconocer una entrada, en
     * orden de confianza.
     *
     * @return array<string,string> via => clave
     */
    private static function clavesDe(array $entrada, bool $porIp): array
    {
        $comment = trim((string) ($entrada['comment'] ?? ''));
        $nombre = trim((string) ($entrada['name'] ?? ''));
        $direccion = trim((string) ($entrada['address'] ?? ''));

        $claves = [];

        if ($comment !== '') {
            $digitos = self::digitos($comment);

            if ($digitos !== '') {
                $claves['documento'] = 'doc:' . $digitos;
            }

            $claves['nombre'] = 'nom:' . mb_strtolower($comment);
        }

        if ($nombre !== '') {
            $claves['pppoe'] = 'ppp:' . mb_strtolower($nombre);

            if (!isset($claves['nombre'])) {
                $claves['nombre'] = 'nom:' . mb_strtolower($nombre);
            }
        }

        if ($porIp && $direccion !== '') {
            $claves['ip'] = 'ip:' . $direccion;
        }

        return $claves;
    }

    /**
     * De quién es una entrada del router, si es de alguien.
     *
     * @param  array<string,mixed> $entrada
     * @return array<string,mixed>|null  la identidad del cliente
     */
    public static function clienteDeEntrada(int $companyId, array $entrada, bool $porIp = true): ?array
    {
        return self::resolver($companyId, $entrada, $porIp)['identidad'];
    }

    /**
     * Los comentarios con los que el cliente puede estar en el router, para
     * buscarlo con una consulta por cada uno cuando no conviene leerlo todo.
     *
     * @param  array<string,mixed> $identidad
     * @return array<int,string>
     */
    public static function comentarios(array $identidad): array
    {
        $valores = [];

        if (($identidad['dni'] ?? '') !== '') {
            $valores[] = (string) $identidad['dni'];
        }

        foreach ($identidad['origen'] ?? [] as $origen) {
            $valores[] = $origen;
        }

        // El username del sistema es el documento, pero algunas altas viejas
        // dejaron el comment con ese valor.
        if (($identidad['username'] ?? '') !== '' && !in_array($identidad['username'], $valores, true)) {
            $valores[] = (string) $identidad['username'];
        }

        return array_values(array_unique(array_filter($valores)));
    }

    /**
     * Cómo se identifica hoy a ese cliente, para explicárselo a quien mira la
     * pantalla ("por su documento", "por su nombre en WispHub"…).
     *
     * @param  array<string,mixed> $identidad
     */
    public static function explicar(array $identidad): string
    {
        $partes = [];

        if ($identidad['documento'] !== '') {
            $partes[] = 'documento ' . $identidad['dni'];
        }
        if ($identidad['origen']) {
            $partes[] = 'nombre de origen ' . implode(' / ', $identidad['origen']);
        }
        if ($identidad['ip']) {
            $partes[] = 'IP ' . $identidad['ip'];
        }

        return $partes ? implode(', ', $partes) : 'sin datos para buscarlo en el router';
    }

    /** Sólo los dígitos: el comment puede venir con puntos, espacios o guiones. */
    public static function digitos(string $texto): string
    {
        return (string) preg_replace('/\D/', '', $texto);
    }

    /** Vacía la memoria (después de cambiar fichas, o entre pruebas). */
    public static function olvidar(?int $companyId = null): void
    {
        if ($companyId === null) {
            self::$porEmpresa = [];
            self::$indices = [];

            return;
        }

        unset(self::$porEmpresa[$companyId], self::$indices[$companyId]);
    }
}

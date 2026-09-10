<?php

namespace App\Services\Red;

use App\Managers\Interfaces\ConectionRouterManagerInterface;
use App\Models\UserData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RouterOS\Query;

/**
 * Pasa un cliente de IP fija a PPPoE o al revés.
 *
 * Hasta ahora el tipo de conexión se decidía al dar de alta y quedaba fijo,
 * así que no había forma de migrar a nadie: un ISP que arranca con IP fija y
 * después monta PPPoE tenía que borrar y volver a crear cada cliente.
 *
 * Las dos formas de conectarse viven en lugares distintos del router —una en
 * el ARP, la otra en los secrets—, así que migrar es sacar de uno y poner en
 * el otro. Se hace en ese orden a propósito: si algo falla al crear lo nuevo,
 * el cliente queda sin servicio, que se nota enseguida y se arregla; al revés
 * quedaría con dos accesos a la vez, que es peor y no se ve.
 */
class CambiarConexionCliente
{
    public function __construct(
        private ConectionRouterManagerInterface $conexion,
        private int $companyId,
    ) {}

    /**
     * @param  array{connection_type:string, pppoe_user?:?string, pppoe_password?:?string,
     *               pppoe_profile?:?string, ip?:?string, vlan?:?string}  $datos
     * @return array{ok:bool, mensaje:string}
     */
    public function aplicar(int $userId, array $datos): array
    {
        $cliente = UserData::where('user_id', $userId)
            ->where('company_id', $this->companyId)
            ->first();

        if (!$cliente) {
            return ['ok' => false, 'mensaje' => 'No se encontró el cliente.'];
        }

        $nuevo  = ($datos['connection_type'] ?? 'static') === 'pppoe' ? 'pppoe' : 'static';
        $actual = $cliente->connection_type ?? 'static';

        $token = DB::table('conection_routers')
            ->where('company_id', $this->companyId)
            ->when($cliente->router_id, fn ($q) => $q->where('id', $cliente->router_id))
            ->value('token');

        if (!$token) {
            return ['ok' => false, 'mensaje' => 'El cliente no tiene un router configurado.'];
        }

        try {
            return $nuevo === 'pppoe'
                ? $this->aPppoe($cliente, $datos, $token, $actual)
                : $this->aIpFija($cliente, $datos, $token, $actual);
        } catch (\Throwable $e) {
            Log::error('[Conexión] No se pudo cambiar el tipo', [
                'user_id' => $userId, 'de' => $actual, 'a' => $nuevo, 'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'mensaje' => 'No se pudo aplicar el cambio en el router: ' . $e->getMessage()];
        }
    }

    /* ── Hacia PPPoE ──────────────────────────────────────────────────────── */

    private function aPppoe(UserData $cliente, array $datos, string $token, string $actual): array
    {
        $usuario = trim((string) ($datos['pppoe_user'] ?? '')) ?: (string) $cliente->dni;
        $clave   = (string) ($datos['pppoe_password'] ?? '');
        $perfil = trim((string) ($datos['pppoe_profile'] ?? ''));

        if ($perfil === '') {
            // El del plan del cliente: es el que tiene su velocidad.
            $perfil = (string) DB::table('internet_plans')
                ->where('id', $cliente->internet_plans_id)
                ->value('pppoe_profile');
        }

        $perfil = $perfil ?: ($cliente->pppoe_profile ?: 'default');

        // Al editar un cliente que ya es PPPoE se puede dejar la contraseña en
        // blanco para no cambiarla.
        if ($clave === '') {
            $clave = (string) ($cliente->pppoe_password ?? '');
        }

        if ($clave === '') {
            return ['ok' => false, 'mensaje' => 'Falta la contraseña PPPoE.'];
        }

        if ($this->usuarioTomado($usuario, (int) $cliente->user_id)) {
            return ['ok' => false, 'mensaje' => "El usuario PPPoE «{$usuario}» ya está en uso por otro cliente."];
        }

        $pppoe = new ServicioPppoe($this->conexion, $token);

        // Si le cambiaron el usuario, el viejo secret queda suelto en el
        // router y seguiría dando acceso.
        $anterior = trim((string) ($cliente->pppoe_user ?? ''));

        if ($actual === 'pppoe' && $anterior !== '' && $anterior !== $usuario) {
            $pppoe->eliminar($anterior);
        }

        if ($actual === 'static') {
            $this->borrarArp($token, (string) $cliente->dni);
        }

        $pppoe->crear($usuario, $clave, $perfil, (string) $cliente->dni);

        $cliente->connection_type = 'pppoe';
        $cliente->pppoe_user      = $usuario;
        $cliente->pppoe_password  = $clave;
        $cliente->pppoe_profile   = $perfil;

        // La IP fija se libera: con PPPoE la asigna el pool.
        if ($actual === 'static') {
            $cliente->ip_assignment_id = null;
        }

        $cliente->save();

        Log::info('[Conexión] Cliente pasado a PPPoE', [
            'user_id' => $cliente->user_id, 'usuario' => $usuario, 'venia_de' => $actual,
        ]);

        return [
            'ok' => true,
            'mensaje' => $actual === 'pppoe'
                ? 'Credenciales PPPoE actualizadas.'
                : 'El cliente ahora se conecta por PPPoE. Tiene que reconectar con el usuario y la contraseña nuevos.',
        ];
    }

    /* ── Hacia IP fija ────────────────────────────────────────────────────── */

    private function aIpFija(UserData $cliente, array $datos, string $token, string $actual): array
    {
        $ip   = trim((string) ($datos['ip'] ?? ''));
        $vlan = trim((string) ($datos['vlan'] ?? ''));

        if ($actual === 'static') {
            return ['ok' => false, 'mensaje' => 'El cliente ya se conecta con IP fija. Para cambiarle la IP usá la migración.'];
        }

        if ($ip === '' || $vlan === '') {
            return ['ok' => false, 'mensaje' => 'Para pasar a IP fija hacen falta la VLAN y la IP.'];
        }

        // Primero se le saca la credencial: si quedara, tendría los dos
        // accesos a la vez y nadie lo notaría.
        $usuario = trim((string) ($cliente->pppoe_user ?? ''));

        if ($usuario !== '') {
            (new ServicioPppoe($this->conexion, $token))->eliminar($usuario);
        }

        $this->crearArp($token, $ip, $vlan, (string) $cliente->dni);

        $cliente->connection_type   = 'static';
        $cliente->pppoe_user        = null;
        $cliente->pppoe_password    = null;
        $cliente->pppoe_profile     = null;
        $cliente->ip_assignment_id  = $this->fichaDeIp((int) $cliente->user_id, $ip);
        $cliente->save();

        Log::info('[Conexión] Cliente pasado a IP fija', [
            'user_id' => $cliente->user_id, 'ip' => $ip, 'vlan' => $vlan,
        ]);

        return ['ok' => true, 'mensaje' => "El cliente ahora usa la IP {$ip}. Tiene que reiniciar el equipo para tomarla."];
    }

    /* ── Router ───────────────────────────────────────────────────────────── */

    private function borrarArp(string $token, string $documento): void
    {
        $api = $this->conexion->conection($token);

        $q = new Query('/ip/arp/print');
        $q->where('comment', $documento);
        $q->add('=.proplist=.id');

        foreach ($api->query($q)->read() as $fila) {
            $baja = new Query('/ip/arp/remove');
            $baja->equal('.id', $fila['.id']);
            $api->query($baja)->read();
        }
    }

    private function crearArp(string $token, string $ip, string $vlan, string $documento): void
    {
        $api = $this->conexion->conection($token);

        // Si esa IP ya estaba tomada por otra entrada se saca primero: dos
        // entradas con la misma IP se pelean el ARP.
        $q = new Query('/ip/arp/print');
        $q->where('address', $ip);
        $q->add('=.proplist=.id');

        foreach ($api->query($q)->read() as $fila) {
            $baja = new Query('/ip/arp/remove');
            $baja->equal('.id', $fila['.id']);
            $api->query($baja)->read();
        }

        $alta = new Query('/ip/arp/add');
        $alta->equal('address', $ip);
        $alta->equal('mac-address', '00:00:00:00:00:00');
        $alta->equal('interface', $vlan);
        $alta->equal('comment', $documento);
        $api->query($alta)->read();
    }

    /** El usuario PPPoE no puede repetirse dentro de la empresa. */
    private function usuarioTomado(string $usuario, int $userId): bool
    {
        return UserData::where('company_id', $this->companyId)
            ->where('pppoe_user', $usuario)
            ->where('user_id', '<>', $userId)
            ->exists();
    }

    /** Le arma al cliente su propia ficha de IP, sin pisar la de nadie. */
    private function fichaDeIp(int $userId, string $ip): int
    {
        return (int) AsignacionDeIp::fichaPropia($userId, $ip, $this->companyId);
    }
}

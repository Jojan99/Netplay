<?php

namespace App\Services\Red;

use App\Managers\Interfaces\ConectionRouterManagerInterface;
use App\Models\UserData;
use Illuminate\Support\Facades\Crypt;
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
 *
 * Eso vale sólo cuando la plataforma no maneja el equipo del cliente (sin ONT,
 * o con el aprovisionamiento apagado) o el operador avisa que lo carga él en
 * el equipo ("a_mano"). Con la ONT en el TR-069 el cambio lo lleva
 * CambioDeConexion, en el orden contrario y con vuelta atrás: el 18-09 este
 * orden dejó a DOUGLAS_MENDEZ sin internet porque la ONT no tomó el cambio y
 * el router ya estaba cambiado.
 */
class CambiarConexionCliente
{
    public function __construct(
        private ConectionRouterManagerInterface $conexion,
        private int $companyId,
    ) {}

    /**
     * @param  array{connection_type:string, pppoe_user?:?string, pppoe_password?:?string,
     *               pppoe_profile?:?string, ip?:?string, vlan?:?string, a_mano?:mixed}  $datos
     * @return array{ok:bool, mensaje:string, aprovisionamiento?:?int, requiere_a_mano?:bool, motivo?:string, que_hacer?:?string}
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

        if ($curso = CambioDeConexion::enCurso($this->companyId, $userId)) {
            return ['ok' => false, 'aprovisionamiento' => $curso->id,
                'mensaje' => "Ya hay un cambio de conexión en curso: {$curso->detalle} Espere a que termine o cancelalo."];
        }

        try {
            // Con la ONT en el TR-069 (salvo que el operador lo cargue él en el
            // equipo), el cambio va por CambioDeConexion.
            if (!filter_var($datos['a_mano'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $plan = (new CambioDeConexion($this->companyId))->planear($userId);

                if ($plan['modo'] !== 'sin_ont') {
                    return $this->conOnt($cliente, $datos, $token, $actual, $nuevo, $plan);
                }
            }

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

    /**
     * Cliente de IP fija que cambia de IP, con su ONT en el TR-069: se agrega
     * la IP nueva, se cambia la ONT, se confirma y recién ahí se saca la vieja.
     * Devuelve null si no hay ONT que manejar (o $aMano): sigue la migración
     * de siempre, que es sólo el router.
     *
     * @return array{ok:bool, mensaje:string, aprovisionamiento?:?int, requiere_a_mano?:bool}|null
     */
    public function cambiarIp(int $userId, string $ip, string $interfaz, bool $aMano = false): ?array
    {
        if ($aMano) {
            return null;
        }

        $cliente = UserData::where('user_id', $userId)->where('company_id', $this->companyId)->first();

        if (!$cliente) {
            return ['ok' => false, 'mensaje' => 'No se encontró el cliente.'];
        }

        if ($curso = CambioDeConexion::enCurso($this->companyId, $userId)) {
            return ['ok' => false, 'aprovisionamiento' => $curso->id,
                'mensaje' => "Ya hay un cambio de conexión en curso: {$curso->detalle} Espere a que termine o cancelalo."];
        }

        $plan = (new CambioDeConexion($this->companyId))->planear($userId);

        if ($plan['modo'] === 'sin_ont') {
            return null;
        }

        $token = DB::table('conection_routers')->where('company_id', $this->companyId)
            ->when($cliente->router_id, fn ($q) => $q->where('id', $cliente->router_id))->value('token');

        if (!$token) {
            return ['ok' => false, 'mensaje' => 'El cliente no tiene un router configurado.'];
        }

        try {
            return $this->conOnt($cliente, ['connection_type' => 'static', 'ip' => $ip, 'vlan' => $interfaz], $token, $cliente->connection_type ?? 'static', 'static', $plan);
        } catch (\Throwable $e) {
            Log::error('[Conexión] No se pudo cambiar la IP', ['user_id' => $userId, 'error' => $e->getMessage()]);

            return ['ok' => false, 'mensaje' => 'No se pudo cambiar la IP: ' . $e->getMessage()];
        }
    }

    /* ── Con la ONT en el TR-069 ─────────────────────────────────────────── */

    /**
     * Arma lo de antes y lo de después (router y ONT) y lo deja en marcha.
     * Nada se saca del router hasta que la ONT confirme lo nuevo.
     *
     * @param array<string,mixed> $plan lo que devolvió CambioDeConexion::planear()
     */
    private function conOnt(UserData $cliente, array $datos, string $token, string $actual, string $nuevo, array $plan): array
    {
        if (in_array($plan['modo'], ['no_se_puede', 'no_responde'], true)) {
            return ['ok' => false, 'requiere_a_mano' => true, 'motivo' => $plan['motivo'], 'que_hacer' => $plan['que_hacer'] ?? null,
                'mensaje' => trim($plan['motivo'] . ' ' . ($plan['que_hacer'] ?? ''))];
        }

        $userId = (int) $cliente->user_id;
        $vlan = (int) $plan['vlan'];
        $prov = new AprovisionamientoDeOnt($this->companyId);
        $ficha = $prov->cliente($userId);
        $api = $this->conexion->conection($token);

        // ── Cómo se conecta hoy ──
        if ($actual === 'pppoe') {
            $de = ['tipo' => 'pppoe', 'usuario' => (string) $cliente->pppoe_user, 'perfil' => (string) $cliente->pppoe_profile];
            [$wanAnterior, $aviso] = $prov->wanDelCliente($ficha, $vlan, []);
        } else {
            $ipVieja = (string) ($ficha['ip'] ?? '');
            $red = $ipVieja !== '' ? $prov->redEnElRouter($api, $ipVieja) : null;
            $de = ['tipo' => 'static', 'ip' => $ipVieja, 'interfaz' => $red['interfaz'] ?? null];
            [$wanAnterior, $aviso] = $prov->wanDelCliente($ficha, $vlan, ['gateway' => $red['gateway'] ?? null, 'mascara' => isset($red['bits']) ? "/{$red['bits']}" : null]);
        }

        if (!$wanAnterior) {
            return ['ok' => false, 'mensaje' => 'No se puede armar la conexión que tiene hoy para poder volver atrás si algo falla: ' . $aviso . ' No se tocó nada.'];
        }

        // ── Cómo se va a conectar ──
        if ($nuevo === 'pppoe') {
            $usuario = trim((string) ($datos['pppoe_user'] ?? '')) ?: (string) $cliente->dni;
            $clave   = (string) ($datos['pppoe_password'] ?? '');
            $perfil  = trim((string) ($datos['pppoe_profile'] ?? ''));

            if ($perfil === '') {
                $perfil = (string) DB::table('internet_plans')->where('id', $cliente->internet_plans_id)->value('pppoe_profile');
            }

            $perfil = $perfil ?: ($cliente->pppoe_profile ?: 'default');
            $claveActual = (string) ($cliente->pppoe_password ?? '');
            $clave = $clave !== '' ? $clave : $claveActual;

            if ($clave === '') {
                return ['ok' => false, 'mensaje' => 'Falta la contraseña PPPoE.'];
            }

            if ($this->usuarioTomado($usuario, $userId)) {
                return ['ok' => false, 'mensaje' => "El usuario PPPoE «{$usuario}» ya está en uso por otro cliente."];
            }

            // Ya es PPPoE y el equipo no cambia (mismo usuario y clave): sólo el
            // perfil, que es del router.
            if ($actual === 'pppoe' && $usuario === $de['usuario'] && $clave === $claveActual) {
                return $this->aPppoe($cliente, $datos, $token, $actual);
            }

            $cifrada = Crypt::encryptString($clave);
            $a = ['tipo' => 'pppoe', 'usuario' => $usuario, 'clave_cifrada' => $cifrada, 'perfil' => $perfil];
            $wanNueva = ['tipo' => 'pppoe', 'usuario' => $usuario, 'vlan' => $vlan, 'clave_cifrada' => $cifrada];
        } else {
            $ip       = trim((string) ($datos['ip'] ?? ''));
            $interfaz = trim((string) ($datos['vlan'] ?? ''));

            if ($ip === '' || $interfaz === '') {
                return ['ok' => false, 'mensaje' => 'Para pasar a IP fija hacen falta la VLAN y la IP.'];
            }

            if ($actual === 'static' && $ip === $de['ip']) {
                return ['ok' => false, 'mensaje' => "El cliente ya tiene la IP {$ip}."];
            }

            // La VLAN de la ONT la da su service-port en la OLT: una IP de otra
            // VLAN del router no le llegaría.
            if (preg_match('/^vlan\D*(\d+)$/i', $interfaz, $m) && (int) $m[1] !== $vlan) {
                return ['ok' => false, 'requiere_a_mano' => true,
                    'motivo' => "Esa IP es de {$interfaz} y la ONT sale por la VLAN {$vlan}: pasarlo de VLAN requiere cambiarle el service-port en la OLT.",
                    'que_hacer' => 'Hacelo con un técnico: cambie el service-port y el equipo, y después aplique aquí con «sólo el router».',
                    'mensaje' => "Esa IP es de {$interfaz} y la ONT sale por la VLAN {$vlan}: pasarlo de VLAN requiere cambiarle el service-port en la OLT. No se tocó nada."];
            }

            $red = IpFijaEnElRouter::redDe(IpFijaEnElRouter::redesDe($api, $interfaz), $ip);

            if (!$red) {
                return ['ok' => false, 'mensaje' => "La IP {$ip} no es de ninguna red de {$interfaz}."];
            }

            [$wanNueva, $aviso] = $prov->wanDelCliente(array_merge($ficha ?? [], ['tipo' => 'static', 'ip' => $ip]), $vlan,
                ['gateway' => $red['gateway'], 'mascara' => $red['mask']]);

            if (!$wanNueva) {
                return ['ok' => false, 'mensaje' => $aviso . ' No se tocó nada.'];
            }

            $a = ['tipo' => 'static', 'ip' => $ip, 'interfaz' => $interfaz];
        }

        $routerId = (int) ($cliente->router_id ?: DB::table('conection_routers')->where('company_id', $this->companyId)->where('token', $token)->value('id'));
        $r = (new CambioDeConexion($this->companyId))->iniciar($userId, $routerId, $plan, $de, $a, $wanNueva, $wanAnterior);

        Log::info('[Conexión] Cambio con la ONT en marcha', ['user_id' => $userId, 'de' => CambioDeConexion::texto($de), 'a' => CambioDeConexion::texto($a),
            'modo' => $plan['modo'], 'ok' => $r['ok'], 'aprovisionamiento' => $r['id']]);

        return ['ok' => $r['ok'], 'mensaje' => $r['mensaje'], 'aprovisionamiento' => $r['id']];
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
            $this->borrarArp($token, (int) $cliente->user_id);
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
            return ['ok' => false, 'mensaje' => 'El cliente ya se conecta con IP fija. Para cambiarle la IP use la migración.'];
        }

        if ($ip === '' || $vlan === '') {
            return ['ok' => false, 'mensaje' => 'Para pasar a IP fija hacen falta la VLAN y la IP.'];
        }

        // Antes de tocar nada: que la IP no sea de otro cliente y que sea de
        // alguna red de esa VLAN (puede tener más de una).
        $ipFija   = new IpFijaEnElRouter($this->conexion->conection($token), $this->companyId);
        $revision = $ipFija->revisar($ip, $vlan, (int) $cliente->user_id);

        if (!$revision['ok']) {
            return ['ok' => false, 'mensaje' => $revision['mensaje']];
        }

        // Primero se le saca la credencial: si quedara, tendría los dos
        // accesos a la vez y nadie lo notaría.
        $usuario = trim((string) ($cliente->pppoe_user ?? ''));

        if ($usuario !== '') {
            (new ServicioPppoe($this->conexion, $token))->eliminar($usuario);
        }

        // Si la IP ya estaba en el router sin cliente se reutiliza esa entrada
        // (con su MAC). Antes se borraba cualquier entrada con esa IP, aunque
        // fuera de otro cliente, y se creaba una nueva.
        $arp = $ipFija->aplicar($revision, $ip, $vlan, (string) $cliente->dni);

        $cliente->connection_type   = 'static';
        $cliente->pppoe_user        = null;
        $cliente->pppoe_password    = null;
        $cliente->pppoe_profile     = null;
        $cliente->ip_assignment_id  = $this->fichaDeIp((int) $cliente->user_id, $ip);
        $cliente->save();

        if ($arp['accion'] === 'reutilizada') {
            IpFijaEnElRouter::recordarNombreAnterior($this->companyId, (int) $cliente->user_id, $arp['comment_anterior'], (string) $cliente->dni);
            if ($arp['mac']) {
                DB::table('tabla_ips')->where('id', $cliente->ip_assignment_id)->update(['mac' => $arp['mac']]);
            }
        }

        Log::info('[Conexión] Cliente pasado a IP fija', [
            'user_id' => $cliente->user_id, 'ip' => $ip, 'vlan' => $vlan,
        ]);

        return ['ok' => true, 'mensaje' => "El cliente ahora usa la IP {$ip}. Tiene que reiniciar el equipo para tomarla."
            . ($arp['accion'] === 'reutilizada' ? ' ' . $arp['mensaje'] : '')];
    }

    /* ── Router ───────────────────────────────────────────────────────────── */

    /**
     * Le saca al cliente su entrada de ARP, la tenga a nombre de su documento
     * o del nombre que traía de la plataforma de la que se importó.
     */
    private function borrarArp(string $token, int $userId): void
    {
        $identidad = IdentidadEnElRouter::deUsuario($userId, $this->companyId);

        if (!$identidad) {
            return;
        }

        $api = $this->conexion->conection($token);

        $q = new Query('/ip/arp/print');
        $q->add('=.proplist=.id,address,comment');

        foreach (IdentidadEnElRouter::suyas($api->query($q)->read(), $identidad, $this->companyId) as $fila) {
            $baja = new Query('/ip/arp/remove');
            $baja->equal('.id', $fila['.id']);
            $api->query($baja)->read();
        }
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

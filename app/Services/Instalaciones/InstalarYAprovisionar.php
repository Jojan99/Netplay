<?php

namespace App\Services\Instalaciones;

use App\Models\InstallationOrder;
use App\Models\OltAdmin;
use App\Models\OltOnt;
use App\Services\Red\AprovisionamientoDeOnt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Lo que pasa cuando el técnico termina una instalación en la calle.
 *
 * Hasta ahora esto eran cinco pantallas distintas y alguien en la oficina: dar
 * de alta al cliente, autorizar la ONT en la OLT, asociársela, descontar el
 * equipo del inventario y dejar el WiFi puesto. El técnico hacía la parte
 * física y el resto quedaba para después —o se olvidaba—.
 *
 * Aquí va en un solo paso, con el orden que importa: si algo falla a mitad, lo
 * que ya se hizo queda anotado y se puede retomar, porque deshacer una
 * autorización en la OLT con el cliente esperando es peor que seguir.
 *
 * **Autorizar y aprovisionar no son lo mismo.** La autorización siempre va: es
 * lo que le da línea al equipo. El aprovisionamiento —WiFi, PPPoE, TR-069—
 * sólo si la empresa lo tiene encendido; si no, el técnico lo configura a mano
 * como hace hoy y el resto del flujo funciona igual.
 */
class InstalarYAprovisionar
{
    /**
     * @param  array{fsp: string, ont_id: int, serial: string, inventory_id?: int}  $equipo
     * @return array{ok: bool, message: string, pasos: list<array<string,mixed>>, avisos: list<string>, data: array<string,mixed>}
     */
    public static function hacer(InstallationOrder $orden, array $equipo, ?int $tecnicoId = null): array
    {
        $pasos = [];
        $avisos = [];

        $anotar = function (string $que, bool $ok, ?string $detalle = null) use (&$pasos): void {
            $pasos[] = ['paso' => $que, 'ok' => $ok, 'detalle' => $detalle, 'en' => now()->toDateTimeString()];
        };

        if ($orden->status === 'completed') {
            return self::falla('Esa instalación ya está terminada.', $pasos, $avisos);
        }

        // La orden propone; el técnico, que está mirando el equipo, decide. La
        // instalación se planea en la oficina y cuando llega a la casa el
        // cliente a veces sale por otro nodo o por otra VLAN: si se impusiera
        // lo planeado, la ONT quedaría autorizada donde no navega.
        $real = self::loQueVaAUsar($orden, $equipo);

        $olt = OltAdmin::find($real['olt_id']);

        if (!$olt) {
            return self::falla('No se indicó por qué OLT entra este cliente. Selecciónela antes de instalar.', $pasos, $avisos);
        }

        if ($real['cambios']) {
            $anotar('El técnico corrigió el plan', true, implode(' · ', $real['cambios']));
        }

        // ── 1. El cliente ───────────────────────────────────────────────────
        //
        // Se crea recién ahora y no al tomar el pedido: hasta que el técnico no
        // llega, no se sabe si la instalación se puede hacer.
        try {
            $cliente = ClienteDesdeLaOrden::crearOEncontrar($orden);
        } catch (\Throwable $e) {
            Log::error('[Instalación] No se pudo crear el cliente', ['orden' => $orden->id, 'error' => $e->getMessage()]);

            return self::falla('No se pudo dar de alta al cliente: ' . $e->getMessage(), $pasos, $avisos);
        }

        if (!$cliente['ok']) {
            return self::falla($cliente['message'], $pasos, $avisos);
        }

        $userId = (int) $cliente['user_id'];
        $anotar('Cliente dado de alta', true, $cliente['nuevo'] ? 'Creado desde la orden' : 'Ya existía');

        // ── 2. Autorizar la ONT ─────────────────────────────────────────────
        $serial = strtoupper(trim($equipo['serial']));

        try {
            $alta = app(\App\UseCases\OltAdmin\OltAdminUseCase::class)->registerONT((int) $olt->id, [
                'fsp'         => $equipo['fsp'],
                'serial'      => $serial,
                'description' => \App\UseCases\OltAdmin\OltAdminUseCase::descripcionParaLaOlt(
                    trim(($orden->client_name ?: 'CLIENTE'))
                ),
                'vlan'        => $real['vlan'],
                // Sin esto la OLT usa sus perfiles por defecto, que no siempre
                // son los que van para el plan que se le vendió.
                'line_profile_id' => $real['line_profile_id'],
                'srv_profile_id'  => $real['srv_profile_id'],
                'onu_type'        => $real['onu_type'],
            ]);
        } catch (\Throwable $e) {
            $anotar('Autorizar la ONT', false, $e->getMessage());

            return self::falla('La OLT no pudo autorizar el equipo: ' . $e->getMessage(), $pasos, $avisos);
        }

        if (($alta['status'] ?? 1) !== 0) {
            $anotar('Autorizar la ONT', false, $alta['message'] ?? null);

            return self::falla('La OLT no autorizó el equipo: ' . ($alta['message'] ?? 'sin detalle'), $pasos, $avisos);
        }

        $ontId = (int) ($alta['data']['ont_id'] ?? $equipo['ont_id']);
        $anotar('ONT autorizada', true, "{$equipo['fsp']}:{$ontId} · {$serial}");

        // ── 3. Asociarla al cliente ─────────────────────────────────────────
        OltOnt::where('olt_id', $olt->id)->where('fsp', $equipo['fsp'])->where('ont_id', $ontId)
            ->update(['user_data_id' => $userId]);

        $anotar('Equipo asignado al cliente', true);

        // ── 4. Descontar del inventario ─────────────────────────────────────
        $inv = EquipoDelInventario::descontar(
            (int) $orden->company_id,
            $serial,
            $userId,
            (int) $orden->id,
            $equipo['inventory_id'] ?? null,
            $tecnicoId,
        );

        $anotar('Descontado del inventario', $inv['ok'], $inv['detalle']);

        if (!$inv['ok']) {
            // No frena la instalación: el cliente ya tiene línea y la
            // diferencia de stock se arregla desde la oficina.
            $avisos[] = $inv['detalle'];
        }

        // ── 5. Aprovisionar, si la empresa lo tiene encendido ───────────────
        $aprovisionamiento = null;

        // AprovisionamientoDeOnt::cliente() —y con ella toda la clase, columna
        // «aprovisionamientos.user_id» incluida— busca por el id de «users» (la
        // convención de facturación), no por el de «user_data» que se usa acá para la
        // orden, la OLT y el inventario. Pasarle $userId tal cual no encontraba al
        // cliente y dejaba sin configurar la conexión a internet —sólo entraban WiFi
        // y la cuenta de administración, que no pasan por cliente()—.
        $authUserId = (int) DB::table('user_data')->where('id', $userId)->value('user_id');

        try {
            $aprovisionamiento = AprovisionamientoDeOnt::programar(
                (int) $olt->id,
                (string) $equipo['fsp'],
                $ontId,
                $serial,
                $authUserId ?: null,
                $real['vlan'] ? (int) $real['vlan'] : null,
                self::loQuePidioElCliente($orden),
            );
        } catch (\Throwable $e) {
            Log::warning('[Instalación] No se pudo programar el aprovisionamiento', ['orden' => $orden->id, 'error' => $e->getMessage()]);
            $avisos[] = 'El equipo quedó autorizado pero no se pudo programar la configuración: ' . $e->getMessage();
        }

        $anotar(
            $aprovisionamiento ? 'Configuración programada' : 'Sin configuración automática',
            true,
            $aprovisionamiento
                ? 'Corre sola en los próximos minutos'
                : 'Esta empresa no tiene el aprovisionamiento encendido: configurá el equipo a mano',
        );

        // ── 6. Cerrar la orden ──────────────────────────────────────────────
        $orden->fill([
            'user_data_id'         => $userId,
            'status'               => 'completed',
            // Queda lo que se usó, no lo que se había planeado: si mañana hay
            // que revisar por qué este cliente sale por acá, la orden lo dice.
            'olt_id'               => $real['olt_id'],
            'vlan'                 => $real['vlan'],
            'line_profile_id'      => $real['line_profile_id'],
            'srv_profile_id'       => $real['srv_profile_id'],
            'onu_type'             => $real['onu_type'],
            'ont_serial'           => $serial,
            'ont_fsp'              => $equipo['fsp'],
            'ont_id'               => $ontId,
            'inventory_id'         => $inv['inventory_id'],
            'aprovisionamiento_id' => $aprovisionamiento['aprovisionamiento'] ?? null,
            'provisioned_at'       => now(),
            'provision_detalle'    => ['pasos' => $pasos, 'avisos' => $avisos, 'cambios' => $real['cambios']],
            'finished_at'          => $orden->finished_at ?: now(),
        ])->save();

        return [
            'ok'      => true,
            'message' => $aprovisionamiento
                ? 'Listo. El equipo quedó autorizado y la configuración corre sola en unos minutos.'
                : 'Listo. El equipo quedó autorizado y asignado al cliente.',
            'pasos'   => $pasos,
            'avisos'  => $avisos,
            'data'    => [
                'user_id'            => $userId,
                'cliente_nuevo'      => $cliente['nuevo'],
                'ont'                => ['fsp' => $equipo['fsp'], 'ont_id' => $ontId, 'serial' => $serial],
                'aprovisionamiento'  => $aprovisionamiento['aprovisionamiento'] ?? null,
            ],
        ];
    }

    /**
     * El mismo recorrido de arriba, en una orden de PRÁCTICA: no toca la OLT, no da de alta a nadie y no
     * descuenta inventario. Devuelve los mismos pasos, marcados como simulados, para que el técnico vea
     * exactamente qué pasaría con una instalación de verdad —incluido lo que él corrigió respecto de la
     * orden— y termine la orden como en la vida real.
     *
     * @param  array{fsp: string, ont_id: int, serial: string, inventory_id?: int|null}  $equipo
     * @return array{ok: bool, message: string, pasos: list<array<string,mixed>>, avisos: list<string>, data: array<string,mixed>}
     */
    public static function practica(InstallationOrder $orden, array $equipo, ?int $tecnicoId = null): array
    {
        if ($orden->status === 'completed') {
            return self::falla('Esa práctica ya terminó.', [], []);
        }

        $pasos = [];
        $anotar = function (string $que, ?string $detalle = null) use (&$pasos): void {
            $pasos[] = ['paso' => $que, 'ok' => true, 'detalle' => $detalle ? $detalle . ' (simulado)' : '(simulado)', 'en' => now()->toDateTimeString()];
        };

        $serial = strtoupper(trim($equipo['serial']));

        // Lo que traía la orden y lo que el técnico eligió, con los nombres que vio en pantalla: en una práctica
        // los ids de la OLT y de los perfiles son de mentira y no existen en la base.
        $olts = [-1 => 'OLT Norte (práctica)', -2 => 'OLT Sur (práctica)'];
        $perfiles = [10 => 'Residencial 100M', 11 => 'Residencial 200M', 20 => 'Internet + WiFi', 21 => 'Solo internet'];
        $planeado = ['olt' => -1, 'vlan' => (int) ($orden->vlan ?: 100), 'linea' => 10, 'servicio' => 20];
        $elegido  = ['olt' => (int) ($equipo['olt_id'] ?? -1), 'vlan' => (int) ($equipo['vlan'] ?? $planeado['vlan']),
                     'linea' => (int) ($equipo['line_profile_id'] ?? 10), 'servicio' => (int) ($equipo['srv_profile_id'] ?? 20)];

        $cambios = [];
        if ($elegido['olt'] !== $planeado['olt'])           $cambios[] = 'OLT: ' . $olts[$planeado['olt']] . ' → ' . ($olts[$elegido['olt']] ?? "OLT {$elegido['olt']}");
        if ($elegido['vlan'] !== $planeado['vlan'])         $cambios[] = "VLAN: {$planeado['vlan']} → {$elegido['vlan']}";
        if ($elegido['linea'] !== $planeado['linea'])       $cambios[] = 'perfil de línea: ' . ($perfiles[$planeado['linea']] ?? $planeado['linea']) . ' → ' . ($perfiles[$elegido['linea']] ?? $elegido['linea']);
        if ($elegido['servicio'] !== $planeado['servicio']) $cambios[] = 'perfil de servicio: ' . ($perfiles[$planeado['servicio']] ?? $planeado['servicio']) . ' → ' . ($perfiles[$elegido['servicio']] ?? $elegido['servicio']);

        $anotar('Cliente dado de alta', 'Se crearía a ' . ($orden->client_name ?: 'el cliente') . ' con su plan y su facturación');

        if ($cambios) {
            $anotar('El técnico corrigió el plan', implode(' · ', $cambios));
        }

        $anotar('ONT autorizada', "{$equipo['fsp']}:{$equipo['ont_id']} · {$serial} · " . ($olts[$elegido['olt']] ?? 'OLT') . " · VLAN {$elegido['vlan']}");
        $anotar('Equipo asignado al cliente');
        $anotar('Descontado del inventario', "Saldría 1 unidad, serial {$serial}");
        $anotar('Configuración programada', 'Se cargarían el WiFi' . ($orden->wifi_ssid ? " «{$orden->wifi_ssid}»" : '') . ' y la conexión ' . ($orden->connection_type === 'pppoe' ? 'PPPoE' : 'de IP fija'));

        $orden->fill([
            'status'            => 'completed',
            'ont_serial'        => $serial,
            'ont_fsp'           => $equipo['fsp'],
            'provisioned_at'    => now(),
            'provision_detalle' => ['pasos' => $pasos, 'avisos' => [], 'cambios' => $cambios, 'practica' => true],
            'finished_at'       => $orden->finished_at ?: now(),
        ])->save();

        return [
            'ok'      => true,
            'message' => 'Práctica terminada. Nada de esto tocó la red: el equipo era simulado y no se creó ningún cliente.',
            'pasos'   => $pasos,
            'avisos'  => [],
            'data'    => ['practica' => true, 'ont' => ['fsp' => $equipo['fsp'], 'ont_id' => (int) $equipo['ont_id'], 'serial' => $serial]],
        ];
    }

    /**
     * Con qué se va a autorizar: lo de la orden, salvo lo que el técnico cambió.
     *
     * Devuelve además qué cambió, en palabras, para que quede anotado en la
     * orden. Sin eso, mañana nadie sabe si la VLAN 120 fue una decisión del
     * técnico o un error de carga.
     *
     * @param  array<string,mixed>  $equipo
     * @return array{olt_id: ?int, vlan: ?int, line_profile_id: ?int, srv_profile_id: ?int, onu_type: ?string, cambios: list<string>}
     */
    private static function loQueVaAUsar(InstallationOrder $orden, array $equipo): array
    {
        $cambios = [];

        $elegir = function (string $campo, string $comoSeLlama, ?callable $formato = null) use ($orden, $equipo, &$cambios) {
            $planeado = $orden->$campo;
            $delTecnico = $equipo[$campo] ?? null;

            if ($delTecnico === null || $delTecnico === '') {
                return $planeado;
            }

            if ((string) $delTecnico !== (string) $planeado) {
                $antes = ($planeado === null || $planeado === '')
                    ? 'sin definir'
                    : ($formato ? $formato($planeado) : $planeado);

                $cambios[] = "{$comoSeLlama}: {$antes} → " . ($formato ? $formato($delTecnico) : $delTecnico);
            }

            return $delTecnico;
        };

        $nombreDeOlt = fn ($id) => OltAdmin::where('id', $id)->value('name') ?: "OLT {$id}";

        $olt  = $elegir('olt_id', 'OLT', $nombreDeOlt);
        $vlan = $elegir('vlan', 'VLAN');
        $line = $elegir('line_profile_id', 'perfil de línea');
        $srv  = $elegir('srv_profile_id', 'perfil de servicio');
        $onu  = $elegir('onu_type', 'tipo de ONU');

        return [
            'olt_id'          => $olt ? (int) $olt : null,
            'vlan'            => $vlan ? (int) $vlan : null,
            'line_profile_id' => ($line === null || $line === '') ? null : (int) $line,
            'srv_profile_id'  => ($srv === null || $srv === '') ? null : (int) $srv,
            'onu_type'        => $onu ?: null,
            'cambios'         => $cambios,
        ];
    }

    /**
     * El WiFi y la conexión que se acordaron con el cliente al tomar el pedido.
     *
     * Están en la orden justamente para que el técnico no tenga que
     * inventarlos ni llamar a la oficina desde la casa del cliente.
     *
     * @return array<string,mixed>
     */
    private static function loQuePidioElCliente(InstallationOrder $orden): array
    {
        return array_filter([
            'wifi_ssid'       => $orden->wifi_ssid,
            'wifi_clave'      => $orden->wifi_password,
            'connection_type' => $orden->connection_type,
            'pppoe_user'      => $orden->pppoe_user,
            'pppoe_password'  => $orden->pppoe_password,
            'pppoe_profile'   => $orden->pppoe_profile,
            'ip'              => $orden->ip_asignada,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /**
     * @param  list<array<string,mixed>>  $pasos
     * @param  list<string>  $avisos
     * @return array{ok: bool, message: string, pasos: list<array<string,mixed>>, avisos: list<string>, data: array<string,mixed>}
     */
    private static function falla(string $mensaje, array $pasos, array $avisos): array
    {
        return ['ok' => false, 'message' => $mensaje, 'pasos' => $pasos, 'avisos' => $avisos, 'data' => []];
    }
}

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
 * Acá va en un solo paso, con el orden que importa: si algo falla a mitad, lo
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

        $olt = OltAdmin::find($orden->olt_id);

        if (!$olt) {
            return self::falla('La orden no dice por qué OLT entra este cliente. Completala antes de instalar.', $pasos, $avisos);
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
                'vlan'        => $orden->vlan,
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

        try {
            $aprovisionamiento = AprovisionamientoDeOnt::programar(
                (int) $olt->id,
                (string) $equipo['fsp'],
                $ontId,
                $serial,
                $userId,
                $orden->vlan ? (int) $orden->vlan : null,
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
            'ont_serial'           => $serial,
            'ont_fsp'              => $equipo['fsp'],
            'ont_id'               => $ontId,
            'inventory_id'         => $inv['inventory_id'],
            'aprovisionamiento_id' => $aprovisionamiento['aprovisionamiento'] ?? null,
            'provisioned_at'       => now(),
            'provision_detalle'    => ['pasos' => $pasos, 'avisos' => $avisos],
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

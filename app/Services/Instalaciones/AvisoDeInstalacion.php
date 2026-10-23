<?php

namespace App\Services\Instalaciones;

use App\Models\InstallationOrder;
use App\Models\TvLinea;
use App\Services\Avisos\MensajeDeAviso;
use App\Services\NotificationRouterService;
use App\Services\Tv\LineasDeTv;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * El aviso al grupo de instalaciones cuando el técnico deja un cliente funcionando (21-10-2026):
 * quién instaló, el equipo, por dónde entra a la red, la conexión, el WiFi y, si el plan lleva
 * TV, el usuario, la clave y la lista de canales. Así la oficina tiene todo sin preguntar.
 *
 * Va al destino de «Cliente instalado»; si la empresa no lo ha elegido, al mismo de «Cliente
 * nuevo» (el grupo de instalaciones de siempre).
 */
class AvisoDeInstalacion
{
    public const EVENTO = 'instalacion_terminada';

    public static function enviar(InstallationOrder $orden, ?float $lat = null, ?float $lng = null, ?int $tvLineaId = null): void
    {
        try {
            $empresa = (int) $orden->company_id;
            $evento = NotificationRouterService::destinos($empresa, self::EVENTO) ? self::EVENTO : 'new_user';

            self::mensaje($orden, $lat, $lng, $tvLineaId)->enviar($evento, $empresa);
        } catch (\Throwable $e) {
            // El aviso nunca puede tumbar la instalación.
            Log::warning('[Instalaciones] No se pudo avisar la instalación terminada', ['orden' => $orden->id, 'error' => $e->getMessage()]);
        }
    }

    public static function mensaje(InstallationOrder $orden, ?float $lat = null, ?float $lng = null, ?int $tvLineaId = null): MensajeDeAviso
    {
        $empresa = (int) $orden->company_id;

        $plan = $orden->internet_plan_id ? DB::table('internet_plans')->where('id', $orden->internet_plan_id)->value('plan_name') : null;
        $olt = $orden->olt_id ? DB::table('olt_admins')->where('id', $orden->olt_id)->value('name') : null;
        $equipo = $orden->inventory_id ? DB::table('inventories')->where('id', $orden->inventory_id)->value('name') : null;
        $conexion = $orden->connection_type === 'pppoe'
            ? 'PPPoE ' . ($orden->pppoe_user ?: '—')
            : ($orden->ip_asignada ? 'IP fija ' . $orden->ip_asignada : null);

        $aviso = MensajeDeAviso::nuevo('Cliente instalado', $empresa, '✅')
            ->dato('Orden', "#{$orden->id}")
            ->dato('Cliente', $orden->client_name)
            ->dato('Cédula', $orden->client_dni)
            ->telefono('Teléfono', $orden->client_phone)
            ->dato('Dirección', trim($orden->address . ($orden->neighborhood ? " ({$orden->neighborhood})" : '')))
            ->dato('Plan', $plan)
            ->dato('Instaló', self::tecnico($orden->provisioned_by))
            ->dato('Técnicos asignados', self::asignados($orden, $empresa))
            ->fecha('Terminada', $orden->provisioned_at ?? now())
            ->bloque('Equipo', implode("\n", array_filter([
                $equipo ? "Modelo: {$equipo}" : null,
                $orden->ont_serial ? "Serial: {$orden->ont_serial}" : null,
            ])) ?: null)
            ->bloque('Red', implode("\n", array_filter([
                $olt ? "OLT: {$olt}" : null,
                $orden->ont_fsp !== null ? "Puerto: {$orden->ont_fsp} · ONT {$orden->ont_id}" : null,
                $orden->vlan ? "VLAN: {$orden->vlan}" : null,
                $conexion ? "Conexión: {$conexion}" : null,
            ])) ?: null)
            ->bloque('WiFi', implode("\n", array_filter([
                $orden->wifi_ssid ? "Red: {$orden->wifi_ssid}" : null,
                $orden->wifi_password ? "Clave: {$orden->wifi_password}" : null,
            ])) ?: null);

        $linea = $tvLineaId ? TvLinea::where('company_id', $empresa)->find($tvLineaId) : null;
        if ($linea) {
            $tv = LineasDeTv::deLaEmpresa($empresa);
            $aviso->bloque('Televisión', implode("\n", array_filter([
                "Usuario: {$linea->usuario}",
                "Clave: {$linea->clave}",
                $tv && $linea->estado !== 'pendiente' ? 'Lista: ' . $tv->urls($linea)['m3u'] : 'La línea se crea sola en unos minutos (el panel no respondió).',
            ])));
        }

        if ($lat !== null && $lng !== null) {
            $aviso->dato('Ubicación', "https://maps.google.com/?q={$lat},{$lng}");
        }

        return $aviso;
    }

    /** El nombre de quien aprovisionó (es un usuario de la plataforma con su ficha). */
    public static function tecnico(?int $userId): ?string
    {
        if (!$userId) {
            return null;
        }

        $nombre = DB::table('user_data')->where('user_id', $userId)->selectRaw("TRIM(CONCAT(COALESCE(names,''),' ',COALESCE(lastname,''))) as n")->value('n');

        return $nombre ?: (DB::table('users')->where('id', $userId)->value('username') ?: "Usuario #{$userId}");
    }

    private static function asignados(InstallationOrder $orden, int $empresa): ?string
    {
        $ids = is_array($orden->technician_ids) ? $orden->technician_ids : (json_decode((string) $orden->technician_ids, true) ?: []);

        return $ids ? (DB::table('employees')->whereIn('id', $ids)->where('company_id', $empresa)->get(['first_name', 'last_name'])
            ->map(fn ($e) => trim("{$e->first_name} {$e->last_name}"))->implode(', ') ?: null) : null;
    }
}

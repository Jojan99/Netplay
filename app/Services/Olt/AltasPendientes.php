<?php

namespace App\Services\Olt;

use App\Models\OltAdmin;
use App\Models\OltOnt;
use App\UseCases\OltAdmin\OltAdminUseCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Altas de ONT que la OLT terminó después de que el panel dejó de esperar.
 *
 * La C-Data lee todas las ONU del puerto antes de autorizar una y el worker atiende de a un
 * comando: con la cola ocupada, el alta pasaba de los 120 s. El worker la terminaba igual, pero
 * nadie guardaba el cliente ni aprovisionaba: la ONT aparecía después en la lista, sin dueño, y
 * el cliente sin equipo en su ficha.
 *
 * Aquí se anota el alta al cortarse la espera y, cuando la ONT aparece en la OLT con ese serial,
 * se hace lo que faltaba (lo mismo que tras un alta normal: OltAdminUseCase::trasAutorizar).
 * Lo corre `olt:completar-altas` cada minuto.
 */
class AltasPendientes
{
    private const CLAVE = 'olt:altas_pendientes';

    /** Más que esto sin aparecer en la OLT: el alta no se hizo de verdad. */
    private const HORAS_DE_VIDA = 24;

    /** Cada cuánto se vuelve a leer la lista de la OLT mientras haya algo pendiente. */
    private const SEGUNDOS_ENTRE_LECTURAS = 120;

    public static function anotar(int $oltId, array $data, ?int $vlan, ?int $spIndex, string $desc): void
    {
        Redis::hset(self::CLAVE, self::campo($oltId, (string) $data['serial']), json_encode([
            'olt_id' => $oltId, 'data' => $data, 'vlan' => $vlan, 'sp_index' => $spIndex,
            'desc' => $desc, 'desde' => now()->timestamp, 'autor' => session('user')->id ?? null,
        ]));

        Log::warning('[OLT] Alta cortada por tiempo: queda pendiente para terminarla sola', [
            'olt_id' => $oltId, 'fsp' => $data['fsp'] ?? null, 'serial' => $data['serial'] ?? null, 'cliente' => $data['user_data_id'] ?? null,
        ]);
    }

    public static function olvidar(int $oltId, string $serial): void
    {
        Redis::hdel(self::CLAVE, self::campo($oltId, $serial));
    }

    /** @return array<string, array<string,mixed>> */
    public static function todas(): array
    {
        return array_map(fn ($v) => json_decode($v, true) ?: [], Redis::hgetall(self::CLAVE) ?: []);
    }

    /**
     * Revisa las pendientes y termina las que ya aparecen en la OLT.
     *
     * @return list<string> Lo que pasó con cada una, para el registro del comando.
     */
    public static function completar(OltAdminUseCase $olts): array
    {
        $informe = [];
        $leidas  = [];

        foreach (self::todas() as $campo => $p) {
            $oltId  = (int) ($p['olt_id'] ?? 0);
            $data   = (array) ($p['data'] ?? []);
            $serial = (string) ($data['serial'] ?? '');

            if (!$oltId || $serial === '' || now()->timestamp - (int) ($p['desde'] ?? 0) > self::HORAS_DE_VIDA * 3600) {
                Redis::hdel(self::CLAVE, $campo);
                $informe[] = "{$serial}: no apareció en la OLT en " . self::HORAS_DE_VIDA . ' h; se descarta';
                continue;
            }

            // Se trabaja como la empresa de la OLT (y como quien hizo el alta, si se sabe): las
            // lecturas y el aprovisionamiento van por la empresa de la sesión.
            $empresa = (int) OltAdmin::whereKey($oltId)->value('company_id');
            $autor   = !empty($p['autor']) ? \App\Models\User::where('id', $p['autor'])->where('company_id', $empresa)->first() : null;
            session(['user' => $autor ?: (object) ['company_id' => $empresa]]);

            $fila = self::buscar($oltId, $serial);

            // Sin la ONT en la base todavía: se lee la OLT (por SNMP, sin ocupar al worker),
            // una vez por OLT en cada pasada y no más seguido que SEGUNDOS_ENTRE_LECTURAS.
            if (!$fila && !isset($leidas[$oltId]) && Redis::set("olt:{$oltId}:lectura_por_altas", 1, 'EX', self::SEGUNDOS_ENTRE_LECTURAS, 'NX')) {
                $leidas[$oltId] = true;
                $olts->getAuthorizedONTs($oltId, true);
                $fila = self::buscar($oltId, $serial);
            }

            if (!$fila) {
                $informe[] = "{$serial}: todavía no aparece en la OLT";
                continue;
            }

            $usuario   = !empty($data['user_data_id']) ? (int) $data['user_data_id'] : null;
            $fichaId   = $usuario ? DB::table('user_data')->where('user_id', $usuario)->value('id') : null;

            if ($fila->user_data_id && (int) $fila->user_data_id !== (int) $fichaId) {
                Redis::hdel(self::CLAVE, $campo);
                $informe[] = "{$serial}: ya quedó a nombre de otro cliente; no se toca";
                continue;
            }

            $data['fsp'] = $fila->fsp;
            $resultado = $olts->trasAutorizar($oltId, $data, self::resultado($oltId, $fila), $p['vlan'] ?? null, $p['sp_index'] ?? null, (string) ($p['desc'] ?? ''));
            Redis::hdel(self::CLAVE, $campo);

            if ($usuario) {
                DB::table('user_audit_logs')->insert([
                    'user_id' => $usuario, 'changed_by' => (int) ($p['autor'] ?? 0), 'company_id' => $empresa,
                    'field_changed' => 'datos_generales', 'old_value' => null, 'new_value' => $serial,
                    'description' => "La ONT {$serial} quedó vinculada en {$fila->fsp} · ONT {$fila->ont_id}: la OLT terminó el alta después de que el panel dejó de esperar.",
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            $informe[] = "{$serial}: vinculada en {$fila->fsp}#{$fila->ont_id}" . ($usuario ? " al cliente {$usuario}" : '') . ' · ' . ($resultado['message'] ?? '');
            Log::info('[OLT] Alta pendiente terminada', ['olt_id' => $oltId, 'serial' => $serial, 'cliente' => $usuario, 'resultado' => $resultado['message'] ?? null]);
        }

        return $informe;
    }

    /**
     * Lo que el driver habría devuelto si la espera no se hubiera cortado, leído de lo que
     * quedó en la OLT. En EPON (C-Data) no hay service-port: el worker deja la VLAN en el
     * puerto al terminar el alta.
     */
    private static function resultado(int $oltId, OltOnt $fila): array
    {
        $puertos = (array) ($fila->service_ports ?? []);
        $epon    = (bool) preg_match('/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/i', (string) $fila->serial);

        return [
            'success'              => true,
            'ont_id'               => (int) $fila->ont_id,
            'service_port_created' => $epon || !empty($puertos),
            'service_port_index'   => $puertos[0]['index'] ?? null,
            'vlan_paso'            => $epon ? ['titulo' => 'VLAN en el puerto PON', 'ok' => true, 'detalle' => 'la dejó la OLT al terminar el alta'] : null,
        ];
    }

    private static function buscar(int $oltId, string $serial): ?OltOnt
    {
        $limpio = self::limpio($serial);

        return OltOnt::where('olt_id', $oltId)
            ->whereRaw("UPPER(REPLACE(REPLACE(REPLACE(serial, ':', ''), '-', ''), '.', '')) = ?", [$limpio])
            ->first();
    }

    private static function campo(int $oltId, string $serial): string
    {
        return $oltId . ':' . self::limpio($serial);
    }

    private static function limpio(string $serial): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $serial));
    }
}

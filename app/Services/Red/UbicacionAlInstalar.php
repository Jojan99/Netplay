<?php

namespace App\Services\Red;

use App\Models\InstallationOrder;
use Illuminate\Support\Facades\DB;

/**
 * Pone en el mapa de la red al cliente que se acaba de instalar, con el GPS del técnico.
 *
 * El técnico está en la casa del cliente cuando cierra la instalación: esa es la mejor
 * ubicación que se va a tener. La manda el celular al completar; si no llegó, sirve la
 * última posición del técnico sólo si es reciente (el seguimiento sólo se actualiza con
 * el panel abierto, y una de hace semanas ubicaría al cliente en cualquier parte).
 *
 * No pisa una ubicación que un administrador puso a mano en el mapa.
 */
class UbicacionAlInstalar
{
    /** Más allá de esto el GPS no sirve para ubicar una casa (dentro de un edificio, sin señal…). */
    public const PRECISION_MAXIMA = 150;

    /** La posición guardada del técnico vale si es de hace menos de esto. */
    private const MINUTOS_FRESCA = 15;

    /** Caja que se le asigna sola si está a menos de esto. */
    private const METROS_CAJA = 150;

    /**
     * @return array{ubicado: bool, detalle: string}
     */
    public static function registrar(InstallationOrder $orden, ?float $lat, ?float $lng, ?float $precision): array
    {
        if ($orden->modo_practica) {
            return ['ubicado' => false, 'detalle' => 'Instalación de práctica: no se ubica en el mapa.'];
        }

        $cliente = $orden->user_data_id
            ? DB::table('user_data')->where('id', $orden->user_data_id)->where('company_id', $orden->company_id)->first(['id', 'user_id'])
            : null;

        if (!$cliente) {
            return ['ubicado' => false, 'detalle' => 'La orden no tiene un cliente creado: no se ubicó en el mapa.'];
        }

        $fuente = 'gps del celular';

        if ($lat === null || $lng === null) {
            [$lat, $lng] = self::ultimaDelTecnico($orden);
            $precision = null;
            $fuente = 'última posición del técnico';
        }

        if ($lat === null || $lng === null) {
            return ['ubicado' => false, 'detalle' => 'No llegó la ubicación del técnico: el cliente queda por ubicar en el mapa.'];
        }

        if ($precision !== null && $precision > self::PRECISION_MAXIMA) {
            return ['ubicado' => false, 'detalle' => 'El GPS estaba impreciso (±' . round($precision) . ' m): el cliente queda por ubicar en el mapa.'];
        }

        $actual = DB::table('cliente_ubicaciones')->where('company_id', $orden->company_id)->where('user_id', $cliente->user_id)->first();

        if ($actual && $actual->fuente === 'manual' && $actual->lat !== null) {
            return ['ubicado' => false, 'detalle' => 'Ya estaba ubicado a mano en el mapa: no se cambió.'];
        }

        $caja = self::cajaCercana($orden->company_id, (int) $cliente->id, $lat, $lng) ?? ($actual->red_punto_id ?? null);

        (new MapaDeRed((int) $orden->company_id))->ubicarCliente(
            (int) $cliente->user_id, $lat, $lng, $caja !== null ? (int) $caja : null, (int) (auth()->id() ?? 0), 'tecnico',
        );

        $nombreCaja = $caja ? DB::table('red_puntos')->where('id', $caja)->value('nombre') : null;

        return [
            'ubicado' => true,
            'detalle' => "Ubicado en el mapa con la {$fuente}" . ($precision !== null ? ' (±' . round($precision) . ' m)' : '')
                . ($nombreCaja ? " y colgado de la caja {$nombreCaja}." : '.'),
        ];
    }

    /** @return array{0: ?float, 1: ?float} */
    private static function ultimaDelTecnico(InstallationOrder $orden): array
    {
        $ids = array_filter((array) ($orden->technician_ids ?? []));

        if (!$ids) {
            return [null, null];
        }

        $fila = DB::table('employees')
            ->where('company_id', $orden->company_id)
            ->whereIn('id', $ids)
            ->whereNotNull('latitude')
            ->where('last_location_update', '>=', now()->subMinutes(self::MINUTOS_FRESCA))
            ->orderByDesc('last_location_update')
            ->first(['latitude', 'longitude']);

        return $fila ? [(float) $fila->latitude, (float) $fila->longitude] : [null, null];
    }

    /**
     * La caja NAP más cercana dentro de METROS_CAJA. Si hay alguna en el mismo puerto PON
     * de la ONT del cliente, gana esa aunque haya otra un poco más cerca.
     */
    private static function cajaCercana(int $companyId, int $userDataId, float $lat, float $lng): ?int
    {
        $ont = DB::table('olt_onts')->where('user_data_id', $userDataId)->first(['olt_id', 'fsp']);
        $mejor = null;

        foreach (DB::table('red_puntos')->where('company_id', $companyId)->where('tipo', 'nap')->get() as $caja) {
            $metros = self::metros($lat, $lng, (float) $caja->lat, (float) $caja->lng);

            if ($metros > self::METROS_CAJA) {
                continue;
            }

            $mismoPuerto = $ont && (int) $caja->olt_id === (int) $ont->olt_id && $caja->fsp === $ont->fsp;
            $puntaje = $metros - ($mismoPuerto ? 1000 : 0);

            if ($mejor === null || $puntaje < $mejor[1]) {
                $mejor = [(int) $caja->id, $puntaje];
            }
        }

        return $mejor[0] ?? null;
    }

    private static function metros(float $a1, float $o1, float $a2, float $o2): float
    {
        $r = fn ($x) => deg2rad($x);
        $h = sin($r($a2 - $a1) / 2) ** 2 + cos($r($a1)) * cos($r($a2)) * sin($r($o2 - $o1) / 2) ** 2;

        return 6371000 * 2 * asin(sqrt($h));
    }
}

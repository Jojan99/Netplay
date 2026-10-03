<?php

namespace App\Services\Plataforma;

use App\Services\Acs\EquiposDelAcs;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * El TR-069 como complemento de pago: gestión remota del equipo del cliente
 * (WiFi, reinicio, consumo, acceso remoto) y configuración automática de la
 * ONT al instalar.
 *
 * Sin el complemento la empresa sigue operando: autoriza la ONT en la OLT,
 * crea el cliente en el MikroTik, corta y reactiva. Lo que pierde es lo que
 * pasa por el ACS.
 *
 * Se cobra por tramos según cuántos equipos de la empresa reportan al ACS
 * (config plataforma.complementos.tr069.tramos): quien gestiona más equipos
 * le pesa más al servidor y paga más. No hay tope duro: si la empresa crece,
 * el cobro siguiente sale con el tramo que le corresponda.
 *
 * Hasta la fecha de 'desde' nadie queda bloqueado: es el plazo de aviso de un
 * cambio de precio. Desde ese día, sólo entra quien lo tenga contratado.
 */
class ComplementoTr069
{
    public const CODIGO = 'COMPLEMENTO_NO_ACTIVO';

    /** ¿Ya se exige tenerlo contratado? */
    public static function exigido(): bool
    {
        $desde = (string) config('plataforma.complementos.tr069.desde', '');

        return $desde === '' || now()->toDateString() >= $desde;
    }

    public static function contratado(int $companyId): bool
    {
        // Sin memoria entre llamadas: hay procesos que viven horas y no deben
        // quedarse con la respuesta de antes de que se active el complemento.
        return (bool) DB::table('plataforma_suscripciones')->where('company_id', $companyId)->value('tr069_activo')
            || self::incluidoEnElPlan($companyId);
    }

    /** El precio fijo que le pone su plan al complemento (null = se cobra por tramos). */
    public static function precioDelPlan(int $companyId): ?float
    {
        $clave = DB::table('plataforma_suscripciones as s')->join('plataforma_planes as p', 'p.id', '=', 's.plan_id')
            ->where('s.company_id', $companyId)->value('p.clave');
        $precio = config('plataforma.complementos.tr069.por_plan.' . $clave);

        return $clave && $precio !== null ? (float) $precio : null;
    }

    /** Red completa trae el TR-069 incluido: no hay que activarlo ni se cobra aparte. */
    public static function incluidoEnElPlan(int $companyId): bool
    {
        return self::precioDelPlan($companyId) === 0.0;
    }

    /** ¿Puede usar hoy lo que pasa por el ACS? */
    public static function permitido(int $companyId): bool
    {
        return !self::exigido() || self::contratado($companyId);
    }

    /**
     * Los tramos de precio, de menor a mayor.
     *
     * @return list<array{hasta:int, precio:float}>
     */
    public static function tramos(): array
    {
        $tramos = array_map(
            fn ($t) => ['hasta' => (int) $t[0], 'precio' => (float) $t[1]],
            (array) config('plataforma.complementos.tr069.tramos', [])
        );

        usort($tramos, fn ($a, $b) => $a['hasta'] <=> $b['hasta']);

        return $tramos;
    }

    /** El tramo que le toca a esa cantidad de equipos; por encima del último, el último. */
    public static function tramoPara(int $equipos): ?array
    {
        $tramos = self::tramos();

        foreach ($tramos as $t) {
            if ($equipos <= $t['hasta']) {
                return $t;
            }
        }

        return $tramos ? end($tramos) : null;
    }

    /**
     * Cuántos equipos de la empresa reportan al ACS. Se guarda un rato: la
     * consulta va al ACS y esto se pregunta en cada carga del panel.
     */
    public static function equipos(int $companyId): ?int
    {
        if (!self::loUsa($companyId)) {
            return 0;
        }

        return Cache::remember("tr069:equipos:{$companyId}", 900, function () use ($companyId) {
            try {
                return count((new EquiposDelAcs($companyId))->lista());
            } catch (\Throwable $e) {
                Log::warning('[TR-069] el ACS no respondió al contar equipos', ['company_id' => $companyId, 'error' => $e->getMessage()]);

                return null;
            }
        });
    }

    /** Precio mensual: el pactado con la empresa o el del tramo de sus equipos. */
    public static function precio(int $companyId): float
    {
        $pactado = DB::table('plataforma_suscripciones')->where('company_id', $companyId)->value('tr069_precio');

        if ($pactado !== null) {
            return (float) $pactado;
        }

        $delPlan = self::precioDelPlan($companyId);
        if ($delPlan !== null) {
            return $delPlan;
        }

        return (float) (self::tramoPara((int) self::equipos($companyId))['precio'] ?? 0);
    }

    /** ¿La empresa tiene montada la gestión remota? Es a quien hay que avisarle del cambio. */
    public static function loUsa(int $companyId): bool
    {
        return DB::table('gestion_remota')->where('company_id', $companyId)->exists();
    }

    /** Para el panel: qué mostrar en las pantallas de TR-069 y en el aviso. */
    public static function resumen(int $companyId): array
    {
        $equipos = self::equipos($companyId);

        return [
            'contratado' => self::contratado($companyId),
            'exigido'    => self::exigido(),
            'bloqueado'  => !self::permitido($companyId),
            'desde'      => config('plataforma.complementos.tr069.desde') ?: null,
            'precio'     => self::precio($companyId),
            'equipos'    => $equipos,
            'hasta'      => self::tramoPara((int) $equipos)['hasta'] ?? null,
            'tramos'     => self::tramos(),
            'lo_usa'     => self::loUsa($companyId),
        ];
    }

    public static function mensaje(): string
    {
        return 'La gestión por TR-069 es un complemento de su plan y no está activo. Escríbanos para activarlo.';
    }
}

<?php

namespace App\Services\Plataforma;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El tope de clientes que trae el plan de cada empresa.
 *
 * Cuenta el CLIENTE, no al cliente que anda: los suspendidos siguen ocupando su lugar
 * —tienen ficha, equipo y factura pendiente, y vuelven con un pago—. Sólo el retirado
 * (user_data.active = 0, «eliminado») deja el lugar libre. Es la misma cuenta que le
 * muestra la consola a Netvula (PanoramaDeEmpresas::clientes()['en_plan']).
 *
 * Además de los clientes que ya existen se reservan las instalaciones pendientes que
 * todavía no dieron de alta a su cliente: si no, una empresa con 299 de 300 podría
 * agendar diez instalaciones, y a la novena el técnico se quedaría en la casa del cliente
 * sin poder terminar. El aviso tiene que llegar al que toma el pedido, no al de la calle.
 *
 * Una empresa sin plan, o con un plan sin tope, no tiene límite.
 */
class LimiteDeClientes
{
    /** Órdenes que todavía van a dar de alta a un cliente. */
    private const ORDENES_ABIERTAS = ['pending', 'confirmed', 'in_progress'];

    /** El tope de clientes del plan, o null si no hay. */
    public static function tope(int $companyId): ?int
    {
        if (!Schema::hasTable('plataforma_suscripciones')) return null;

        $t = DB::table('plataforma_suscripciones as s')
            ->join('plataforma_planes as p', 'p.id', '=', 's.plan_id')
            ->where('s.company_id', $companyId)
            ->value('p.clientes');

        return $t === null ? null : (int) $t;
    }

    public static function nombreDelPlan(int $companyId): ?string
    {
        return DB::table('plataforma_suscripciones as s')
            ->join('plataforma_planes as p', 'p.id', '=', 's.plan_id')
            ->where('s.company_id', $companyId)->value('p.nombre');
    }

    /** Clientes que ocupan lugar: activos + suspendidos. */
    public static function enUso(int $companyId): int
    {
        return (int) DB::table('user_data as ud')
            ->join('users as u', 'u.id', '=', 'ud.user_id')
            ->join('profiles as p', 'p.id', '=', 'u.profile_id')
            ->where('ud.company_id', $companyId)
            ->where('p.name', 'USER')
            ->where('ud.active', 1)
            ->count();
    }

    /**
     * Instalaciones abiertas que van a crear un cliente nuevo.
     *
     * @param  int|null  $excluirOrden  la que se está terminando: su lugar ya estaba reservado
     */
    public static function reservados(int $companyId, ?int $excluirOrden = null): int
    {
        if (!Schema::hasTable('installation_orders')) return 0;

        $q = DB::table('installation_orders as o')
            ->where('o.company_id', $companyId)
            ->whereIn('o.status', self::ORDENES_ABIERTAS)
            ->whereNull('o.user_data_id')
            // Si esa cédula ya es un cliente, la orden no suma uno más.
            ->whereNotExists(fn ($s) => $s->select(DB::raw(1))->from('user_data as ud')
                ->whereColumn('ud.dni', 'o.client_dni')->where('ud.company_id', $companyId));

        // Las órdenes de práctica del técnico no ocupan lugar: no dan de alta a nadie.
        if (Schema::hasColumn('installation_orders', 'modo_practica')) $q->where('o.modo_practica', 0);
        if ($excluirOrden) $q->where('o.id', '!=', $excluirOrden);

        return (int) $q->count();
    }

    /**
     * Cómo está la empresa frente a su tope.
     *
     * @return array{tope:?int, en_uso:int, reservados:int, ocupados:int, disponibles:?int, lleno:bool, porcentaje:?int, plan:?string, mensaje:?string}
     */
    public static function estado(int $companyId, ?int $excluirOrden = null): array
    {
        $tope = self::tope($companyId);
        $uso = self::enUso($companyId);

        if ($tope === null) {
            return ['tope' => null, 'en_uso' => $uso, 'reservados' => 0, 'ocupados' => $uso, 'disponibles' => null,
                'lleno' => false, 'porcentaje' => null, 'plan' => self::nombreDelPlan($companyId), 'mensaje' => null];
        }

        $reservados = self::reservados($companyId, $excluirOrden);
        $ocupados = $uso + $reservados;

        return [
            'tope'        => $tope,
            'en_uso'      => $uso,
            'reservados'  => $reservados,
            'ocupados'    => $ocupados,
            'disponibles' => max(0, $tope - $ocupados),
            'lleno'       => $ocupados >= $tope,
            'porcentaje'  => min(999, (int) round($ocupados * 100 / max(1, $tope))),
            'plan'        => self::nombreDelPlan($companyId),
            'mensaje'     => null,
        ];
    }

    /**
     * Deja pasar, o dice por qué no.
     *
     * @throws LimiteDeClientesExcedido
     */
    public static function asegurar(int $companyId, int $cuantos = 1, ?int $excluirOrden = null): void
    {
        $e = self::estado($companyId, $excluirOrden);

        if ($e['tope'] === null || $e['ocupados'] + $cuantos <= $e['tope']) return;

        throw new LimiteDeClientesExcedido($e, self::mensaje($e, $cuantos));
    }

    /** Lo mismo, sin lanzar: para quien prefiere devolver el mensaje. Devuelve null si hay lugar. */
    public static function motivoDeBloqueo(int $companyId, int $cuantos = 1, ?int $excluirOrden = null): ?string
    {
        try {
            self::asegurar($companyId, $cuantos, $excluirOrden);
        } catch (LimiteDeClientesExcedido $e) {
            return $e->getMessage();
        }

        return null;
    }

    private static function mensaje(array $e, int $cuantos): string
    {
        $plan = $e['plan'] ? "«{$e['plan']}» " : '';
        $reserva = $e['reservados'] > 0 ? " y {$e['reservados']} instalación(es) pendiente(s) por crear" : '';

        return "Su plan {$plan}incluye {$e['tope']} clientes y ya tiene {$e['en_uso']}{$reserva} "
            . '(cuentan los activos y los suspendidos; los retirados no). '
            . ($cuantos > 1 ? "Para agregar {$cuantos} más, " : 'Para agregar uno nuevo, ')
            . 'retire un cliente o cambie de plan.';
    }
}

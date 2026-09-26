<?php

namespace App\Services\Plataforma;

use App\Services\Acs\EquiposDelAcs;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que la consola sabe de todas las empresas.
 *
 * Es el único lugar del sistema que lee a propósito sin filtrar por la
 * empresa en sesión. Por eso vive aparte: nada de aquí se llama desde los
 * controladores normales del panel, y todo lo que devuelve pasó por el
 * middleware de plataforma.
 *
 * Nunca sale una credencial: de las claves de router, OLT, WhatsApp, Mailjet
 * o ACS sólo se dice si están configuradas.
 */
class PanoramaDeEmpresas
{
    /** Cada cuánto se vuelve a preguntar al ACS por todas las empresas. */
    private const VIGENCIA_ACS = 300;

    // ── Clientes ──────────────────────────────────────────────────────────

    /**
     * Clientes por empresa: activos, suspendidos (cortados) y retirados.
     *
     * Cliente es el usuario con perfil USER; el equipo de la empresa no cuenta.
     *
     * @return array<int, array{total:int, activos:int, suspendidos:int, retirados:int}>
     */
    public static function clientes(): array
    {
        $filas = DB::table('user_data as ud')
            ->join('users as u', 'u.id', '=', 'ud.user_id')
            ->join('profiles as p', 'p.id', '=', 'u.profile_id')
            ->where('p.name', 'USER')
            ->groupBy('ud.company_id')
            ->get([
                'ud.company_id',
                DB::raw('COUNT(*) as total'),
                DB::raw('SUM(ud.active = 1 AND ud.status_internet_id = 1) as activos'),
                DB::raw('SUM(ud.active = 1 AND ud.status_internet_id <> 1) as suspendidos'),
                DB::raw('SUM(ud.active = 0) as retirados'),
            ]);

        $mapa = [];

        foreach ($filas as $f) {
            $mapa[(int) $f->company_id] = [
                'total'       => (int) $f->total,
                'activos'     => (int) $f->activos,
                'suspendidos' => (int) $f->suspendidos,
                'retirados'   => (int) $f->retirados,
            ];
        }

        return $mapa;
    }

    /** Clientes dados de alta por mes, en los últimos N meses. */
    public static function crecimientoDeClientes(int $meses = 12): array
    {
        $desde = now()->startOfMonth()->subMonths($meses - 1);

        $filas = DB::table('user_data as ud')
            ->join('users as u', 'u.id', '=', 'ud.user_id')
            ->join('profiles as p', 'p.id', '=', 'u.profile_id')
            ->where('p.name', 'USER')
            ->where('ud.created_at', '>=', $desde)
            ->groupBy(DB::raw("DATE_FORMAT(ud.created_at, '%Y-%m')"))
            ->orderBy('mes')
            ->get([DB::raw("DATE_FORMAT(ud.created_at, '%Y-%m') as mes"), DB::raw('COUNT(*) as altas')]);

        return self::serieMensual($filas, $meses, 'altas');
    }

    /** Empresas registradas por mes, en los últimos N meses. */
    public static function crecimientoDeEmpresas(int $meses = 12): array
    {
        $desde = now()->startOfMonth()->subMonths($meses - 1);

        $filas = DB::table('companies')
            ->where('created_at', '>=', $desde)
            ->groupBy(DB::raw("DATE_FORMAT(created_at, '%Y-%m')"))
            ->orderBy('mes')
            ->get([DB::raw("DATE_FORMAT(created_at, '%Y-%m') as mes"), DB::raw('COUNT(*) as altas')]);

        return self::serieMensual($filas, $meses, 'altas');
    }

    // ── Red de cada empresa ───────────────────────────────────────────────

    /** @return array<int, list<array{id:int, nombre:string, marca:string, modelo:?string}>> */
    public static function olts(): array
    {
        $mapa = [];

        foreach (DB::table('olt_admins')->orderBy('name')->get(['id', 'company_id', 'name', 'brand', 'model']) as $o) {
            $mapa[(int) $o->company_id][] = [
                'id'     => (int) $o->id,
                'nombre' => $o->name,
                'marca'  => $o->brand,
                'modelo' => $o->model ?: null,
            ];
        }

        return $mapa;
    }

    /** MikroTik por empresa. Nunca la clave ni el token: sólo si están puestos. */
    public static function routers(): array
    {
        $mapa = [];

        foreach (DB::table('conection_routers')->orderBy('name')->get(['id', 'company_id', 'name', 'host', 'port', 'serie']) as $r) {
            $mapa[(int) $r->company_id][] = [
                'id'     => (int) $r->id,
                'nombre' => $r->name ?: $r->host,
                'host'   => $r->host,
                'puerto' => (int) $r->port,
                'serie'  => $r->serie ?: null,
            ];
        }

        return $mapa;
    }

    /** Líneas de WhatsApp por empresa, con su estado. Sin api keys. */
    public static function lineasWhatsapp(): array
    {
        if (!Schema::hasTable('wa_lineas')) {
            return [];
        }

        $mapa = [];

        foreach (DB::table('wa_lineas')->orderBy('principal', 'desc')->orderBy('nombre')->get() as $l) {
            $mapa[(int) $l->company_id][] = [
                'id'          => (int) $l->id,
                'nombre'      => $l->nombre,
                'telefono'    => $l->telefono,
                'estado'      => $l->estado,
                'conectada'   => $l->estado === 'connected',
                'activa'      => (bool) $l->activa,
                'principal'   => (bool) $l->principal,
                'sincronizada'=> $l->sincronizado_en,
            ];
        }

        return $mapa;
    }

    /**
     * Equipos TR-069 atribuidos a cada empresa, según el ACS.
     *
     * Es la parte cara del tablero: pregunta al ACS empresa por empresa. Se
     * guarda en caché unos minutos y, si el ACS no contesta, se devuelve null
     * para esa empresa en vez de romper la pantalla.
     *
     * @return array<int, ?int>
     */
    public static function equiposTr069(array $companyIds, bool $refrescar = false): array
    {
        $clave = 'consola:tr069:' . md5(implode(',', $companyIds));

        if ($refrescar) {
            Cache::forget($clave);
        }

        return Cache::remember($clave, self::VIGENCIA_ACS, function () use ($companyIds) {
            $mapa = [];

            foreach ($companyIds as $id) {
                try {
                    $mapa[$id] = count((new EquiposDelAcs((int) $id))->lista());
                } catch (\Throwable $e) {
                    Log::warning('[Consola] el ACS no respondió por una empresa', ['company_id' => $id, 'error' => $e->getMessage()]);
                    $mapa[$id] = null;
                }
            }

            return $mapa;
        });
    }

    // ── Empresas ──────────────────────────────────────────────────────────

    /**
     * La fila de cada empresa en la tabla de la consola.
     *
     * @return list<array<string,mixed>>
     */
    public static function filas(bool $conTr069 = false, bool $refrescarAcs = false): array
    {
        // Las empresas viejas no tienen suscripción: se les abre aquí, una sola
        // vez, así todas tienen estado y código de referido.
        SuscripcionDeEmpresa::asegurarTodas();

        $empresas = DB::table('companies')->orderBy('name')->get();
        $ids      = $empresas->pluck('id')->map(fn ($i) => (int) $i)->all();

        $clientes = self::clientes();
        $olts     = self::olts();
        $routers  = self::routers();
        $lineas   = self::lineasWhatsapp();
        $tr069    = $conTr069 ? self::equiposTr069($ids, $refrescarAcs) : [];
        $acs      = Schema::hasTable('acs_servidores')
            ? DB::table('acs_servidores')->where('activo', 1)->pluck('modo', 'company_id')
            : collect();

        $suscripciones = SuscripcionDeEmpresa::hayTablas()
            ? DB::table('plataforma_suscripciones as s')
                ->leftJoin('plataforma_planes as p', 'p.id', '=', 's.plan_id')
                ->get(['s.*', 'p.nombre as plan_nombre', 'p.clave as plan_clave', 'p.clientes as plan_clientes',
                       'p.precio_mensual as plan_mensual', 'p.precio_anual as plan_anual'])
                ->keyBy('company_id')
            : collect();

        $ultimoIngreso = self::ultimoIngreso();
        $propios       = array_flip((array) config('plataforma.dominios_propios', []));

        $filas = [];

        foreach ($empresas as $e) {
            $id = (int) $e->id;
            $c  = $clientes[$id] ?? ['total' => 0, 'activos' => 0, 'suspendidos' => 0, 'retirados' => 0];
            $s  = $suscripciones[$id] ?? null;

            $filas[] = [
                'id'             => $id,
                'nombre'         => $e->name,
                'slug'           => $e->slug,
                'subdominio'     => $e->subdomain,
                'dominio_propio' => $propios[$e->subdomain] ?? null,
                'nit'            => $e->nit,
                'email'          => $e->email,
                'telefono'       => $e->phone,
                'registrada'     => $e->created_at,
                'confirmada'     => (bool) $e->email_verified_at,
                'activa'         => (bool) $e->active,
                'suspendida'     => self::suspendida($e),
                'clientes'       => $c,
                'olts'           => count($olts[$id] ?? []),
                'routers'        => count($routers[$id] ?? []),
                'lineas_wa'      => count($lineas[$id] ?? []),
                'lineas_wa_ok'   => count(array_filter($lineas[$id] ?? [], fn ($l) => $l['conectada'])),
                'acs'            => $acs[$id] ?? null,
                'tr069'          => $tr069[$id] ?? null,
                'mailjet'        => (bool) $e->mailjet_activo,
                'ultimo_ingreso' => $ultimoIngreso[$id] ?? null,
                'suscripcion'    => self::resumenSuscripcion($s, $c['activos']),
            ];
        }

        return $filas;
    }

    /**
     * Todo lo que la consola muestra de una empresa.
     *
     * @return array<string,mixed>|null
     */
    public static function detalle(int $companyId): ?array
    {
        $e = DB::table('companies')->where('id', $companyId)->first();

        if (!$e) {
            return null;
        }

        $clientes = (self::clientes()[$companyId] ?? ['total' => 0, 'activos' => 0, 'suspendidos' => 0, 'retirados' => 0]);
        $olts     = self::olts()[$companyId] ?? [];
        $routers  = self::routers()[$companyId] ?? [];
        $lineas   = self::lineasWhatsapp()[$companyId] ?? [];
        $propios  = array_flip((array) config('plataforma.dominios_propios', []));

        $suscripcion = SuscripcionDeEmpresa::asegurar($companyId);
        $plan        = PlanesDeLaPlataforma::buscar($suscripcion && $suscripcion->plan_id ? (int) $suscripcion->plan_id : null);

        try {
            $equipos = count((new EquiposDelAcs($companyId))->lista());
        } catch (\Throwable $ex) {
            Log::warning('[Consola] el ACS no respondió', ['company_id' => $companyId, 'error' => $ex->getMessage()]);
            $equipos = null;
        }

        $equipo = DB::table('users as u')
            ->join('profiles as p', 'p.id', '=', 'u.profile_id')
            ->where('u.company_id', $companyId)
            ->where('p.name', '!=', 'USER')
            ->orderBy('p.name')
            ->get(['u.id', 'u.username', 'u.email', 'u.active', 'p.name as perfil',
                   ...(Schema::hasColumn('users', 'ultimo_ingreso') ? ['u.ultimo_ingreso'] : [])]);

        return [
            'empresa' => [
                'id'             => $companyId,
                'nombre'         => $e->name,
                'slug'           => $e->slug,
                'subdominio'     => $e->subdomain,
                'dominio_propio' => $propios[$e->subdomain] ?? null,
                'direccion_web'  => $e->subdomain . '.' . config('plataforma.dominio'),
                'nit'            => $e->nit,
                'email'          => $e->email,
                'telefono'       => $e->phone,
                'direccion'      => $e->address,
                'registrada'     => $e->created_at,
                'confirmada'     => $e->email_verified_at,
                'activa'         => (bool) $e->active,
                'suspendida'     => self::suspendida($e),
                'motivo_suspension' => Schema::hasColumn('companies', 'plataforma_suspendida_motivo') ? $e->plataforma_suspendida_motivo : null,
                'ultimo_ingreso' => self::ultimoIngreso()[$companyId] ?? null,
            ],
            'clientes'    => $clientes,
            'olts'        => $olts,
            'routers'     => $routers,
            'lineas_wa'   => $lineas,
            'tr069'       => $equipos,
            'equipo'      => $equipo,
            // Nada de credenciales: sólo si están configuradas.
            'integraciones' => [
                'mailjet'   => (bool) $e->mailjet_activo ? 'configurado' : 'no configurado',
                'mailjet_remitente' => $e->mailjet_from_email,
                'whatsapp'  => $e->wa_api_key ? 'configurado' : 'no configurado',
                'wa_proveedor' => $e->wa_provider,
                'pasarela'  => $e->pg_active ? ($e->pg_gateway ?: 'configurada') : 'no configurada',
                'acs'       => Schema::hasTable('acs_servidores')
                    ? (DB::table('acs_servidores')->where('company_id', $companyId)->where('activo', 1)->value('modo') ?: 'no configurado')
                    : 'no configurado',
            ],
            'suscripcion' => self::resumenSuscripcion(
                $suscripcion ? self::conPlan($suscripcion, $plan) : null,
                $clientes['activos']
            ),
        ];
    }

    // ── Auxiliares ────────────────────────────────────────────────────────

    /** Último ingreso de algún usuario de cada empresa. */
    public static function ultimoIngreso(): array
    {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        if (!Schema::hasColumn('users', 'ultimo_ingreso')) {
            return $cache = [];
        }

        $mapa = [];

        $filas = DB::table('users')
            ->whereNotNull('ultimo_ingreso')
            ->groupBy('company_id')
            ->get(['company_id', DB::raw('MAX(ultimo_ingreso) as ultimo')]);

        foreach ($filas as $f) {
            $mapa[(int) $f->company_id] = (string) $f->ultimo;
        }

        return $cache = $mapa;
    }

    private static function suspendida(object $empresa): bool
    {
        return Schema::hasColumn('companies', 'plataforma_suspendida') && (bool) $empresa->plataforma_suspendida;
    }

    /** Le pega al objeto de la suscripción los datos del plan, como en la consulta con join. */
    private static function conPlan(object $s, ?object $plan): object
    {
        $s->plan_nombre   = $plan->nombre ?? null;
        $s->plan_clave    = $plan->clave ?? null;
        $s->plan_clientes = $plan->clientes ?? null;
        $s->plan_mensual  = $plan->precio_mensual ?? null;
        $s->plan_anual    = $plan->precio_anual ?? null;

        return $s;
    }

    /** La suscripción como la lee la consola, con el uso contra el tope del plan. */
    private static function resumenSuscripcion(?object $s, int $clientesActivos): ?array
    {
        if (!$s) {
            return null;
        }

        $ciclo   = $s->ciclo ?: 'mensual';
        $lista   = $ciclo === 'anual' ? $s->plan_anual : $s->plan_mensual;
        $precio  = $s->precio_pactado !== null ? (float) $s->precio_pactado : ($lista === null ? null : (float) $lista);
        $incluye = $s->plan_clientes === null ? null : (int) $s->plan_clientes;

        return [
            'plan_id'        => $s->plan_id ? (int) $s->plan_id : null,
            'plan'           => $s->plan_nombre,
            'plan_clave'     => $s->plan_clave,
            'ciclo'          => $ciclo,
            'precio_lista'   => $lista === null ? null : (float) $lista,
            'precio_pactado' => $s->precio_pactado === null ? null : (float) $s->precio_pactado,
            'precio'         => $precio,
            'estado'         => $s->estado,
            'metodo_pago'    => $s->metodo_pago,
            'inicio'         => $s->inicio,
            'prueba_hasta'   => $s->prueba_hasta,
            'proxima'        => $s->proxima_facturacion,
            'dias_para_vencer' => SuscripcionDeEmpresa::diasParaVencer($s->proxima_facturacion),
            'cupon_id'       => $s->cupon_id ? (int) $s->cupon_id : null,
            'codigo_referido'=> $s->codigo_referido,
            'referida_por'   => $s->referida_por ? (int) $s->referida_por : null,
            'credito'        => SuscripcionDeEmpresa::credito((int) $s->company_id),
            'uso' => [
                'clientes'  => $clientesActivos,
                'incluidos' => $incluye,
                // Sin tope el plan no se "llena": es el de red completa.
                'porcentaje'=> $incluye ? min(999, (int) round($clientesActivos * 100 / max(1, $incluye))) : null,
                'excedido'  => $incluye ? $clientesActivos > $incluye : false,
            ],
            'notas' => $s->notas,
        ];
    }

    /** Rellena los meses sin datos: un gráfico con huecos miente. */
    private static function serieMensual($filas, int $meses, string $campo): array
    {
        $porMes = [];

        foreach ($filas as $f) {
            $porMes[$f->mes] = (int) $f->{$campo};
        }

        $serie = [];
        $cursor = now()->startOfMonth()->subMonths($meses - 1);

        for ($i = 0; $i < $meses; $i++) {
            $clave = $cursor->format('Y-m');
            $serie[] = ['mes' => $clave, 'altas' => $porMes[$clave] ?? 0];
            $cursor->addMonth();
        }

        return $serie;
    }
}

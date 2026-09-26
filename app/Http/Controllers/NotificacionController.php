<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * El centro de notificaciones del encabezado.
 *
 * No guarda nada nuevo: junta lo que la plataforma ya sabe —avisos de la red,
 * clientes esperando en cobranza y aprovisionamientos que se trabaron— y lo
 * muestra en un solo lugar, con su enlace a la pantalla donde se resuelve.
 * Antes esto vivía en tres pantallas distintas y la campana era un adorno.
 */
class NotificacionController extends Controller
{
    private const MAXIMO = 25;

    public function index(): JsonResponse
    {
        $user = JWTAuth::user();
        $companyId = (int) ($user->company_id ?? 0);

        if (!$user || !$companyId) {
            return standardApiReponse('Sin sesión', null, 1, JsonResponse::HTTP_UNAUTHORIZED);
        }

        $modulos = DB::table('profile_modules')->where('profile_id', $user->profile_id)->where('active', true)
            ->pluck('module')->map(fn ($m) => (string) $m)->all();

        $items = collect()
            ->concat($this->red($companyId))
            ->concat(in_array('finanzas', $modulos, true) ? $this->cobranza($companyId) : [])
            ->concat($this->aprovisionamientos($companyId))
            ->sortByDesc('cuando')->take(self::MAXIMO)->values();

        // Sin la tabla (migración pendiente) todo se ve como nuevo, pero la
        // campana igual funciona.
        $visto = Schema::hasTable('notificaciones_vistas')
            ? DB::table('notificaciones_vistas')->where('user_id', $user->id)->value('visto_hasta')
            : null;

        return standardApiReponse('OK', [
            'items'   => $items->map(fn ($i) => $i + ['nueva' => !$visto || $i['cuando'] > $visto])->all(),
            'sin_ver' => $items->filter(fn ($i) => !$visto || $i['cuando'] > $visto)->count(),
        ], 0, JsonResponse::HTTP_OK);
    }

    public function vistas(): JsonResponse
    {
        $user = JWTAuth::user();

        if ($user && Schema::hasTable('notificaciones_vistas')) {
            DB::table('notificaciones_vistas')->updateOrInsert(
                ['user_id' => $user->id],
                ['visto_hasta' => now(), 'updated_at' => now(), 'created_at' => now()]
            );
        }

        return standardApiReponse('OK', null, 0, JsonResponse::HTTP_OK);
    }

    /** Avisos de la red que siguen abiertos: señal, cortes, túneles, OLT. */
    private function red(int $companyId): array
    {
        if (!Schema::hasTable('alertas')) {
            return [];
        }

        return DB::table('alertas')->where('company_id', $companyId)->whereNull('cerrada_en')
            ->orderByRaw("FIELD(nivel, 'critico', 'aviso')")->orderByDesc('abierta_en')->limit(self::MAXIMO)
            ->get(['tipo', 'nivel', 'titulo', 'detalle', 'abierta_en'])
            ->map(fn ($a) => [
                'origen'  => 'red',
                'nivel'   => $a->nivel === 'critico' ? 'critico' : 'aviso',
                'titulo'  => $a->titulo,
                'detalle' => mb_substr((string) $a->detalle, 0, 180),
                'cuando'  => (string) $a->abierta_en,
                'ruta'    => 'olt/alertas',
            ])->all();
    }

    /** Clientes que esperan una decisión en cobranza. */
    private function cobranza(int $companyId): array
    {
        if (!Schema::hasTable('cobranza_casos')) {
            return [];
        }

        $casos = DB::table('cobranza_casos as c')->leftJoin('user_data as u', 'u.user_id', '=', 'c.user_id')
            ->where('c.company_id', $companyId)->whereIn('c.estado', ['detectado', 'escalado'])
            ->orderByDesc('c.updated_at')->limit(10)
            ->get(['c.estado', 'c.deuda', 'c.motivo', 'c.updated_at', 'u.names', 'u.lastname']);

        return $casos->map(fn ($c) => [
            'origen'  => 'cobranza',
            'nivel'   => $c->estado === 'escalado' ? 'critico' : 'aviso',
            'titulo'  => $c->estado === 'escalado'
                ? 'Cobranza: ' . trim("{$c->names} {$c->lastname}") . ' necesita una persona'
                : 'Cobranza: ' . trim("{$c->names} {$c->lastname}") . ' espera su autorización',
            'detalle' => ($c->motivo ?: 'Debe $' . number_format((float) $c->deuda, 0, ',', '.')),
            'cuando'  => (string) $c->updated_at,
            'ruta'    => 'cobranza',
        ])->all();
    }

    /** Equipos que quedaron a medio configurar: alguien tiene que mirarlos. */
    private function aprovisionamientos(int $companyId): array
    {
        if (!Schema::hasTable('aprovisionamientos')) {
            return [];
        }

        return DB::table('aprovisionamientos')->where('company_id', $companyId)
            ->whereIn('estado', ['error', 'con_errores', 'no_aplica'])
            ->where('updated_at', '>=', now()->subDays(3))
            ->orderByDesc('updated_at')->limit(10)
            ->get(['id', 'user_id', 'serial', 'estado', 'detalle', 'datos', 'updated_at'])
            ->map(function ($a) {
                $cliente = (string) (json_decode((string) $a->datos, true)['cliente'] ?? '') ?: $a->serial;

                return [
                    'origen'  => 'equipo',
                    'nivel'   => $a->estado === 'error' ? 'critico' : 'aviso',
                    'titulo'  => "Equipo de {$cliente}: quedó a medias",
                    'detalle' => mb_substr((string) $a->detalle, 0, 180),
                    'cuando'  => (string) $a->updated_at,
                    'ruta'    => $a->user_id ? "usuario?cliente={$a->user_id}" : 'olt/autorizadas',
                ];
            })->all();
    }
}

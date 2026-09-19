<?php

namespace App\Http\Controllers\Consola;

use App\Http\Controllers\Controller;
use App\Services\Plataforma\Bitacora;
use App\Services\Plataforma\Cupones;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Códigos de descuento de la plataforma: alta, edición y quién los usó. */
class ConsolaCuponesController extends Controller
{
    private const REGLAS = [
        'codigo'           => 'required|string|max:40|regex:/^[A-Za-z0-9._-]+$/',
        'descripcion'      => 'nullable|string|max:160',
        'tipo'             => 'required|in:porcentaje,monto',
        'valor'            => 'required|numeric|min:0.01|max:99999999',
        'desde'            => 'nullable|date',
        'hasta'            => 'nullable|date|after_or_equal:desde',
        'usos_maximos'     => 'nullable|integer|min:1|max:1000000',
        'usos_por_empresa' => 'required|integer|min:1|max:100',
        'planes'           => 'nullable|array',
        'planes.*'         => 'string|max:40',
        'ciclos'           => 'nullable|array',
        'ciclos.*'         => 'in:mensual,anual',
        'duracion'         => 'required|in:primer_periodo,n_periodos,permanente',
        'periodos'         => 'nullable|integer|min:1|max:36',
        'activo'           => 'boolean',
        'notas'            => 'nullable|string|max:2000',
    ];

    /** GET /api/consola/cupones */
    public function index(): JsonResponse
    {
        if (!Cupones::hayTabla()) {
            return standardApiReponse('OK', ['cupones' => [], 'migrada' => false], 0, JsonResponse::HTTP_OK);
        }

        $cupones = DB::table('plataforma_cupones')->orderByDesc('id')->get()->map(function ($c) {
            $c->planes = json_decode((string) $c->planes, true) ?: [];
            $c->ciclos = json_decode((string) $c->ciclos, true) ?: [];
            $c->empresas = Schema::hasTable('plataforma_cupon_usos')
                ? DB::table('plataforma_cupon_usos')->where('cupon_id', $c->id)->distinct()->count('company_id')
                : 0;
            $c->vencido = $c->hasta && $c->hasta < now()->toDateString();
            $c->agotado = $c->usos_maximos !== null && (int) $c->usos >= (int) $c->usos_maximos;

            return $c;
        });

        return standardApiReponse('OK', [
            'cupones' => $cupones,
            'planes'  => \App\Services\Plataforma\PlanesDeLaPlataforma::todos(),
            'migrada' => true,
        ], 0, JsonResponse::HTTP_OK);
    }

    /** POST /api/consola/cupones */
    public function store(Request $request): JsonResponse
    {
        if (!Cupones::hayTabla()) {
            return $this->sinMigrar();
        }

        $datos = $request->validate(self::REGLAS);
        $datos['codigo'] = Cupones::normalizar($datos['codigo']);

        if (DB::table('plataforma_cupones')->where('codigo', $datos['codigo'])->exists()) {
            return standardApiReponse('Ya existe un cupón con ese código.', null, 1, JsonResponse::HTTP_CONFLICT);
        }

        if ($datos['tipo'] === 'porcentaje' && $datos['valor'] > 100) {
            return standardApiReponse('Un descuento por porcentaje no puede pasar del 100%.', null, 1, JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $id = DB::table('plataforma_cupones')->insertGetId(
            $this->fila($datos) + ['usos' => 0, 'user_id' => Bitacora::idActual(), 'created_at' => now(), 'updated_at' => now()]
        );

        Bitacora::anotar('cupon.creado', null, $datos, 'cupon', $id);

        return standardApiReponse('Cupón ' . $datos['codigo'] . ' creado.', ['id' => $id], 0, JsonResponse::HTTP_OK);
    }

    /** PUT /api/consola/cupones/{id} */
    public function update(int $id, Request $request): JsonResponse
    {
        if (!Cupones::hayTabla()) {
            return $this->sinMigrar();
        }

        $cupon = DB::table('plataforma_cupones')->where('id', $id)->first();

        if (!$cupon) {
            return standardApiReponse('Ese cupón no existe.', null, 1, JsonResponse::HTTP_NOT_FOUND);
        }

        $datos = $request->validate(self::REGLAS);
        $datos['codigo'] = Cupones::normalizar($datos['codigo']);

        if (DB::table('plataforma_cupones')->where('codigo', $datos['codigo'])->where('id', '!=', $id)->exists()) {
            return standardApiReponse('Ya existe otro cupón con ese código.', null, 1, JsonResponse::HTTP_CONFLICT);
        }

        if ($datos['tipo'] === 'porcentaje' && $datos['valor'] > 100) {
            return standardApiReponse('Un descuento por porcentaje no puede pasar del 100%.', null, 1, JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        DB::table('plataforma_cupones')->where('id', $id)->update($this->fila($datos) + ['updated_at' => now()]);

        Bitacora::anotar('cupon.editado', null, ['antes' => (array) $cupon, 'ahora' => $datos], 'cupon', $id);

        return standardApiReponse('Cupón guardado.', null, 0, JsonResponse::HTTP_OK);
    }

    /** DELETE /api/consola/cupones/{id} */
    public function destroy(int $id): JsonResponse
    {
        if (!Cupones::hayTabla()) {
            return $this->sinMigrar();
        }

        $usos = Schema::hasTable('plataforma_cupon_usos')
            ? DB::table('plataforma_cupon_usos')->where('cupon_id', $id)->count()
            : 0;

        if ($usos > 0) {
            return standardApiReponse(
                'Ese cupón ya se usó ' . $usos . ' vez/veces. Desactivalo en vez de borrarlo, así queda el historial.',
                null, 1, JsonResponse::HTTP_CONFLICT
            );
        }

        $codigo = DB::table('plataforma_cupones')->where('id', $id)->value('codigo');
        DB::table('plataforma_cupones')->where('id', $id)->delete();

        Bitacora::anotar('cupon.borrado', null, ['codigo' => $codigo], 'cupon', $id);

        return standardApiReponse('Cupón borrado.', null, 0, JsonResponse::HTTP_OK);
    }

    /** GET /api/consola/cupones/{id}/usos */
    public function usos(int $id): JsonResponse
    {
        if (!Schema::hasTable('plataforma_cupon_usos')) {
            return standardApiReponse('OK', ['usos' => []], 0, JsonResponse::HTTP_OK);
        }

        return standardApiReponse('OK', [
            'usos' => DB::table('plataforma_cupon_usos as u')
                ->join('companies as e', 'e.id', '=', 'u.company_id')
                ->where('u.cupon_id', $id)
                ->orderByDesc('u.id')
                ->get(['u.id', 'u.company_id', 'e.name as empresa', 'u.origen', 'u.cobro_id', 'u.created_at']),
        ], 0, JsonResponse::HTTP_OK);
    }

    private function fila(array $d): array
    {
        return [
            'codigo'           => $d['codigo'],
            'descripcion'      => $d['descripcion'] ?? null,
            'tipo'             => $d['tipo'],
            'valor'            => $d['valor'],
            'desde'            => $d['desde'] ?? null,
            'hasta'            => $d['hasta'] ?? null,
            'usos_maximos'     => $d['usos_maximos'] ?? null,
            'usos_por_empresa' => $d['usos_por_empresa'],
            'planes'           => !empty($d['planes']) ? json_encode(array_values($d['planes'])) : null,
            'ciclos'           => !empty($d['ciclos']) ? json_encode(array_values($d['ciclos'])) : null,
            'duracion'         => $d['duracion'],
            'periodos'         => $d['duracion'] === 'n_periodos' ? max(1, (int) ($d['periodos'] ?? 1)) : null,
            'activo'           => $d['activo'] ?? true,
            'notas'            => $d['notas'] ?? null,
        ];
    }

    private function sinMigrar(): JsonResponse
    {
        return standardApiReponse('Falta correr la migración de la consola para usar los cupones.', null, 1, JsonResponse::HTTP_CONFLICT);
    }
}

<?php

namespace App\Http\Controllers\Consola;

use App\Http\Controllers\Controller;
use App\Models\Novedad;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Las novedades que Netvula les muestra a las empresas.
 *
 * Se escriben acá, quedan en borrador hasta que se publican, y desde ese
 * momento aparecen en el panel de cada empresa que tenga el módulo.
 */
class ConsolaNovedadesController extends Controller
{
    public function index(): JsonResponse
    {
        return standardApiReponse('OK', Novedad::orderByRaw('publicada_en IS NULL DESC')
            ->orderByDesc('publicada_en')->orderByDesc('id')->limit(200)->get(), 0, JsonResponse::HTTP_OK);
    }

    public function store(Request $request): JsonResponse
    {
        $novedad = Novedad::create($this->validar($request) + ['escrita_por' => $this->quien($request)]);

        return standardApiReponse('Novedad creada.', $novedad, 0, JsonResponse::HTTP_OK);
    }

    public function update(int $id, Request $request): JsonResponse
    {
        $novedad = Novedad::findOrFail($id);
        $novedad->update($this->validar($request));

        return standardApiReponse('Novedad guardada.', $novedad->fresh(), 0, JsonResponse::HTTP_OK);
    }

    /** Publicar o volver a borrador: lo que decide si las empresas la ven. */
    public function publicar(int $id, Request $request): JsonResponse
    {
        $novedad = Novedad::findOrFail($id);
        $publicar = $request->boolean('publicar', true);

        $novedad->update(['publicada_en' => $publicar ? ($novedad->publicada_en ?? now()) : null]);

        return standardApiReponse($publicar ? 'Publicada: ya la ven las empresas.' : 'Vuelta a borrador.', $novedad->fresh(), 0, JsonResponse::HTTP_OK);
    }

    public function destroy(int $id): JsonResponse
    {
        Novedad::findOrFail($id)->delete();

        return standardApiReponse('Novedad borrada.', null, 0, JsonResponse::HTTP_OK);
    }

    /** @return array<string,mixed> */
    private function validar(Request $request): array
    {
        return $request->validate([
            'titulo'  => 'required|string|max:160',
            'detalle' => 'required|string|max:2000',
            'tipo'    => ['required', Rule::in(['nuevo', 'mejora', 'arreglo'])],
            'modulo'  => 'nullable|string|max:60',
            'ruta'    => 'nullable|string|max:160',
        ]);
    }

    private function quien(Request $request): ?string
    {
        $u = $request->attributes->get('consola_usuario');

        return is_object($u) ? ($u->nombre ?? $u->email ?? null) : null;
    }
}

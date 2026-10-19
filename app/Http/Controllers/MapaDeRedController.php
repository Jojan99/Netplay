<?php

namespace App\Http\Controllers;

use App\Services\Red\MapaDeRed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** El mapa de la red (ver MapaDeRed). */
class MapaDeRedController extends Controller
{
    private function mapa(): MapaDeRed
    {
        return new MapaDeRed((int) getSessionCompanyId());
    }

    public function index(): JsonResponse
    {
        return standardApiReponse('Listo', $this->mapa()->datos(), 0, JsonResponse::HTTP_OK);
    }

    public function crearPunto(Request $request): JsonResponse
    {
        return $this->guardar($request, null);
    }

    public function editarPunto(Request $request, int $id): JsonResponse
    {
        return $this->guardar($request, $id);
    }

    public function borrarPunto(int $id): JsonResponse
    {
        try {
            $this->mapa()->borrarPunto($id);
        } catch (\InvalidArgumentException $e) {
            return standardApiReponse($e->getMessage(), null, 1, JsonResponse::HTTP_OK);
        }

        return standardApiReponse('Punto borrado', null, 0, JsonResponse::HTTP_OK);
    }

    public function ubicarCliente(Request $request, int $id): JsonResponse
    {
        $v = $request->validate([
            'lat'    => 'nullable|numeric|between:-90,90|required_with:lng',
            'lng'    => 'nullable|numeric|between:-180,180|required_with:lat',
            'nap_id' => 'nullable|integer',
        ]);

        try {
            $this->mapa()->ubicarCliente($id, $v['lat'] ?? null, $v['lng'] ?? null, $v['nap_id'] ?? null, (int) (getSessionUserId() ?? 0));
        } catch (\InvalidArgumentException $e) {
            return standardApiReponse($e->getMessage(), null, 1, JsonResponse::HTTP_OK);
        }

        return standardApiReponse('Cliente ubicado', null, 0, JsonResponse::HTTP_OK);
    }

    public function quitarCliente(int $id): JsonResponse
    {
        $this->mapa()->quitarCliente($id);

        return standardApiReponse('Cliente quitado del mapa', null, 0, JsonResponse::HTTP_OK);
    }

    private function guardar(Request $request, ?int $id): JsonResponse
    {
        $v = $request->validate([
            'tipo'      => 'required|in:olt,nap',
            'nombre'    => 'required|string|max:120',
            'lat'       => 'required|numeric|between:-90,90',
            'lng'       => 'required|numeric|between:-180,180',
            'olt_id'    => 'nullable|integer',
            'fsp'       => ['nullable', 'string', 'max:20', 'regex:/^\d+\/\d+\/\d+$/'],
            'capacidad' => 'nullable|integer|min:1|max:1024',
            'nota'      => 'nullable|string|max:255',
        ]);

        try {
            $punto = $this->mapa()->guardarPunto($v, $id);
        } catch (\InvalidArgumentException $e) {
            return standardApiReponse($e->getMessage(), null, 1, JsonResponse::HTTP_OK);
        }

        return standardApiReponse($id ? 'Punto actualizado' : 'Punto creado', $punto, 0, JsonResponse::HTTP_OK);
    }
}

<?php

namespace App\Http\Controllers;

use App\Services\Clientes\SuspensionMasiva;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/** Suspensión masiva por grupo de corte: la lista, la orden y su avance. */
class SuspensionMasivaController extends Controller
{
    private function servicio(): SuspensionMasiva
    {
        return new SuspensionMasiva((int) getSessionCompanyId());
    }

    private function bien(string $mensaje, mixed $data = null): JsonResponse
    {
        return response()->json(['message' => $mensaje, 'data' => $data, 'error' => 0]);
    }

    private function mal(string $mensaje, int $http = 200): JsonResponse
    {
        return response()->json(['message' => $mensaje, 'data' => null, 'error' => 1], $http);
    }

    /** GET api/suspension-masiva/opciones */
    public function opciones(): JsonResponse
    {
        return $this->bien('Opciones', $this->servicio()->opciones() + ['historial' => $this->servicio()->historial()]);
    }

    /** GET api/suspension-masiva/candidatos?grupo=&servicio=&min_facturas=&min_dias= */
    public function candidatos(Request $request): JsonResponse
    {
        $filas = $this->servicio()->candidatos([
            'grupo' => (string) $request->query('grupo', 'todos'),
            'servicio' => (string) $request->query('servicio', 'activo'),
            'min_facturas' => (int) $request->query('min_facturas', 1),
            'min_dias' => (int) $request->query('min_dias', 0),
        ]);

        return $this->bien('Clientes con facturas vencidas', ['clientes' => $filas, 'total' => count($filas), 'deuda' => round(array_sum(array_column($filas, 'total')), 2)]);
    }

    /** POST api/suspension-masiva/lotes */
    public function crear(Request $request): JsonResponse
    {
        $d = $request->validate([
            'accion' => 'required|in:avisar,suspender,suspender_y_avisar',
            'clientes' => 'required|array|min:1|max:2000',
            'clientes.*' => 'integer',
            'aviso' => 'nullable|in:suspension,informacion',
            'texto' => 'nullable|string|max:900',
            'fecha_limite' => 'nullable|string|max:20',
            'motivo' => 'nullable|string|max:250',
            'grupo' => 'nullable|string|max:10',
        ]);

        try {
            $id = $this->servicio()->crear($d['accion'], $d['clientes'], $d, getSessionUserId() ? (int) getSessionUserId() : null);
        } catch (\DomainException $e) {
            return $this->mal($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('[Suspensión masiva] No se pudo crear la orden', ['error' => $e->getMessage()]);

            return $this->mal('No se pudo crear la orden. Intente de nuevo.');
        }

        return $this->bien('La orden quedó en marcha.', $this->servicio()->ver($id));
    }

    /** GET api/suspension-masiva/lotes/{id} */
    public function ver(int $id): JsonResponse
    {
        $l = $this->servicio()->ver($id);

        return $l ? $this->bien('Orden', $l) : $this->mal('Esa orden no existe.', 404);
    }

    /** POST api/suspension-masiva/lotes/{id}/cancelar */
    public function cancelar(int $id): JsonResponse
    {
        return $this->servicio()->cancelar($id)
            ? $this->bien('Orden cancelada. Lo que ya se hizo no se deshace.', $this->servicio()->ver($id))
            : $this->mal('Esa orden ya había terminado.');
    }
}

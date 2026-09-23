<?php

namespace App\Http\Controllers;

use App\Rules\ClaveWifi;
use App\Rules\NombreWifi;
use App\Services\Acs\EquiposDelAcs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Router del cliente por TR-069: los equipos del ACS que son de la empresa.
 *
 * Todo pasa por EquiposDelAcs, que sólo deja ver y tocar equipos que se
 * pueden atribuir a un cliente o a una ONT de la empresa en sesión.
 */
class AcsController extends Controller
{
    /** Resumen: cuántos equipos hay, cuántos reportan y la URL para configurarlos. */
    public function estado(): JsonResponse
    {
        return $this->responder(function (EquiposDelAcs $acs) {
            $lista = $acs->lista();
            $ultimo = collect($lista)->max('ultimo_reporte');

            return [
                'equipos'        => count($lista),
                'reportando'     => collect($lista)->where('reportando', true)->count(),
                'ultimo_reporte' => $ultimo,
                'url_acs'        => config('services.genieacs.cwmp_url'),
                'reporta_cada'   => EquiposDelAcs::REPORTA_CADA,
            ];
        });
    }

    /** Clientes con ONT que todavía no reportan al TR-069. */
    public function pendientes(): JsonResponse
    {
        return $this->responder(fn (EquiposDelAcs $acs) => $acs->pendientes());
    }

    public function equipos(): JsonResponse
    {
        return $this->responder(fn (EquiposDelAcs $acs) => $acs->lista());
    }

    /** El id del ACS lleva caracteres codificados: va por query y no en la ruta. */
    public function detalle(Request $request): JsonResponse
    {
        $id = (string) $request->query('id');

        return $this->responder(fn (EquiposDelAcs $acs) => $acs->detalle($id)
            ?? throw new \InvalidArgumentException('Ese equipo no es de tu empresa.'));
    }

    public function deCliente(int $userId): JsonResponse
    {
        return $this->responder(fn (EquiposDelAcs $acs) => $acs->deCliente($userId));
    }

    public function refrescar(Request $request): JsonResponse
    {
        return $this->accion($request, 'refrescar', fn (EquiposDelAcs $acs, string $id) => $acs->refrescar($id));
    }

    public function reiniciar(Request $request): JsonResponse
    {
        return $this->accion($request, 'reiniciar', fn (EquiposDelAcs $acs, string $id) => $acs->reiniciar($id));
    }

    public function wifi(Request $request): JsonResponse
    {
        $request->validate([
            'indice' => 'required|integer|min:1',
            'ssid'   => ['nullable', 'string', 'max:32', new NombreWifi()],
            'clave'  => ['nullable', 'string', new ClaveWifi()],
            'todas'  => 'nullable|boolean',
            // Ocultar la red: el equipo deja de anunciar su nombre.
            'oculta' => 'nullable|boolean',
        ]);

        return $this->accion($request, 'cambiar el WiFi', fn (EquiposDelAcs $acs, string $id) => $acs->cambiarWifi(
            $id,
            (int) $request->input('indice'),
            $request->input('ssid'),
            $request->input('clave'),
            $request->boolean('todas'),
            $request->has('oculta') ? $request->boolean('oculta') : null,
        ));
    }

    // ── Interno ───────────────────────────────────────────────────────────

    private function responder(callable $fn): JsonResponse
    {
        $companyId = (int) getSessionCompanyId();

        if (!$companyId) {
            return standardApiReponse('Sesión sin empresa asociada', null, 1, JsonResponse::HTTP_UNAUTHORIZED);
        }

        try {
            return standardApiReponse('OK', $fn(new EquiposDelAcs($companyId)), 0, JsonResponse::HTTP_OK);
        } catch (\InvalidArgumentException $e) {
            return standardApiReponse($e->getMessage(), null, 1, JsonResponse::HTTP_OK);
        } catch (\Throwable $e) {
            Log::warning('[ACS] Consulta fallida', ['error' => $e->getMessage()]);

            return standardApiReponse('No se pudo consultar el servidor TR-069: ' . $e->getMessage(), null, 1, JsonResponse::HTTP_OK);
        }
    }

    /** Las acciones cambian el equipo del cliente: quedan en el log con quién las pidió. */
    private function accion(Request $request, string $que, callable $fn): JsonResponse
    {
        $id = (string) $request->input('id');

        return $this->responder(function (EquiposDelAcs $acs) use ($fn, $id, $que) {
            $r = $fn($acs, $id);

            Log::info('[ACS] Acción sobre equipo', [
                'accion' => $que, 'equipo' => $id, 'resultado' => $r,
                'usuario' => session('user')->id ?? null, 'empresa' => getSessionCompanyId(),
            ]);

            return $r + [
                'mensaje' => $r['hecha']
                    ? 'El equipo lo hizo en el momento.'
                    : 'Quedó en cola: el equipo lo hace en su próximo reporte al ACS.',
            ];
        });
    }
}

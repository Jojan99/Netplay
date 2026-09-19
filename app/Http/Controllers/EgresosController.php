<?php

namespace App\Http\Controllers;

use App\Http\Requests\Egresos\CreateEgresosRequest;
use App\Repositories\Interfaces\EgresosRepositoryInterface;
use App\UseCases\Egresos\Interfaces\CreateEgresosUseCaseInterface;
use App\UseCases\Egresos\Interfaces\GetEgresosUseCaseInterface;
use App\UseCases\Egresos\Interfaces\GetIngresosDetailedUseCaseInterface;
use App\UseCases\Egresos\Interfaces\GetPriceEgresseUseCaseInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EgresosController extends Controller
{
    public function createEgresos(
        CreateEgresosRequest $createEgresosRequest,
        CreateEgresosUseCaseInterface $createEgresosUseCaseInterface
    ): object {
        $result = $createEgresosUseCaseInterface->createEgresos($createEgresosRequest);
        return standardApiReponse($result['message'], $result['data'], $result['status'], JsonResponse::HTTP_OK);
    }

    public function getEgresosAll(
        GetEgresosUseCaseInterface $getEgresosUseCaseInterface
    ): object {
        $result = $getEgresosUseCaseInterface->getEgresosAll();
        return standardApiReponse($result['message'], $result['data'], $result['status'], JsonResponse::HTTP_OK);
    }

    public function getPriceEgresseAll(
        GetPriceEgresseUseCaseInterface $getPriceEgresseUseCaseInterface
    ): object {
        $result = $getPriceEgresseUseCaseInterface->getPriceEgresseAll();
        return standardApiReponse($result['message'], $result['data'], $result['status'], JsonResponse::HTTP_OK);
    }

    public function getPriceEgresseByRange(
        Request $request,
        GetPriceEgresseUseCaseInterface $getPriceEgresseUseCaseInterface
    ): object {
        $result = $getPriceEgresseUseCaseInterface->getPriceEgresseByRange(
            $request->query('from'),
            $request->query('to')
        );
        return standardApiReponse($result['message'], $result['data'], $result['status'], JsonResponse::HTTP_OK);
    }

    public function getIngresosDetailed(
        Request $request,
        GetIngresosDetailedUseCaseInterface $useCase
    ): object {
        $result = $useCase->getAll(
            $request->query('from'),
            $request->query('to')
        );
        return standardApiReponse($result['message'], $result['data'], $result['status'], JsonResponse::HTTP_OK);
    }

    // ── Egresos v2 ──────────────────────────────────────────────────────────

    /** Los filtros de la pantalla: los mismos para la lista, el tablero y el CSV. */
    private function filtros(Request $request): array
    {
        $ids = $request->query('ids');
        if (is_string($ids)) {
            $ids = array_values(array_filter(explode(',', $ids), fn ($v) => trim($v) !== ''));
        }

        return [
            'search'            => $request->query('search'),
            'from'              => $request->query('from'),
            'to'                => $request->query('to'),
            'category'          => $request->query('category'),
            'payment_method_id' => $request->query('payment_method_id'),
            'sin_categoria'     => $request->boolean('sin_categoria'),
            'sin_metodo'        => $request->boolean('sin_metodo'),
            'sin_fecha'         => $request->boolean('sin_fecha'),
            'ids'               => is_array($ids) ? $ids : null,
            'page'              => (int) $request->query('page', 1),
            'per_page'          => (int) $request->query('per_page', 15),
        ];
    }

    public function listPaginated(Request $request, EgresosRepositoryInterface $repo): object
    {
        return standardApiReponse('OK', $repo->getEgresosPaginated($this->filtros($request)), 0, JsonResponse::HTTP_OK);
    }

    /** Tablero: totales, comparaciones, categorías, evolución y avisos. */
    public function tablero(Request $request, EgresosRepositoryInterface $repo): object
    {
        return standardApiReponse('OK', $repo->getTablero($this->filtros($request)), 0, JsonResponse::HTTP_OK);
    }

    public function createEgresoV2(Request $request, EgresosRepositoryInterface $repo): object
    {
        $request->validate([
            'concept'         => 'required|string|max:200',
            'value'           => 'required|numeric|min:0.01',
            'expense_date'    => 'nullable|date',
            'supplier'        => 'nullable|string|max:160',
            'document_number' => 'nullable|string|max:60',
            'notes'           => 'nullable|string|max:2000',
            'recurrence'      => 'nullable|in:mensual,quincenal',
            'comprobante'     => 'nullable|file|mimes:pdf,jpg,jpeg,png,webp|max:8192',
        ]);

        $datos  = array_merge($request->except('comprobante'), $this->guardarComprobante($request));
        $egreso = $repo->createEgresoV2($datos);

        return standardApiReponse('Egreso registrado.', $egreso, 0, JsonResponse::HTTP_OK);
    }

    public function updateEgreso(Request $request, int $id, EgresosRepositoryInterface $repo): object
    {
        $request->validate([
            'concept'         => 'sometimes|required|string|max:200',
            'value'           => 'sometimes|required|numeric|min:0.01',
            'expense_date'    => 'nullable|date',
            'supplier'        => 'nullable|string|max:160',
            'document_number' => 'nullable|string|max:60',
            'notes'           => 'nullable|string|max:2000',
            'recurrence'      => 'nullable|in:mensual,quincenal',
            'comprobante'     => 'nullable|file|mimes:pdf,jpg,jpeg,png,webp|max:8192',
        ]);

        $datos = $request->except('comprobante');

        // El adjunto viejo se reemplaza sólo si suben uno nuevo.
        $nuevo = $this->guardarComprobante($request);
        if ($nuevo) {
            $this->borrarComprobante($repo->rutaAdjunto($id));
            $datos = array_merge($datos, $nuevo);
        }

        $ok = $repo->updateEgreso($id, $datos);

        return standardApiReponse($ok ? 'Egreso actualizado.' : 'No encontrado', null, $ok ? 0 : 1, JsonResponse::HTTP_OK);
    }

    public function deleteEgreso(int $id, EgresosRepositoryInterface $repo): object
    {
        $adjunto = $repo->rutaAdjunto($id);
        $ok      = $repo->deleteEgreso($id);
        if ($ok) {
            $this->borrarComprobante($adjunto);
        }

        return standardApiReponse($ok ? 'Egreso eliminado.' : 'No encontrado', null, $ok ? 0 : 1, JsonResponse::HTTP_OK);
    }

    /** Crea el siguiente egreso de uno recurrente, sólo cuando el usuario lo pide. */
    public function repetirEgreso(int $id, EgresosRepositoryInterface $repo): object
    {
        $r = $repo->repetirEgreso($id);

        return standardApiReponse($r['mensaje'], $r['ok'] ? ['id' => $r['id']] : null, $r['ok'] ? 0 : 1, JsonResponse::HTTP_OK);
    }

    // ── Comprobante adjunto (carpeta privada, nunca en /storage público) ─────

    /** Guarda el archivo en storage/app/privado/egresos/{empresa} y devuelve sus campos. */
    private function guardarComprobante(Request $request): array
    {
        if (!$request->hasFile('comprobante')) {
            return [];
        }

        $archivo = $request->file('comprobante');
        $dir     = 'privado/egresos/' . (int) getSessionCompanyId();
        $nombre  = 'egreso_' . uniqid() . '.' . strtolower($archivo->getClientOriginalExtension());

        return [
            'attachment_path' => $archivo->storeAs($dir, $nombre, 'local'),
            'attachment_name' => mb_substr($archivo->getClientOriginalName(), 0, 160),
        ];
    }

    private function borrarComprobante(?object $adjunto): void
    {
        $ruta = $this->rutaAbsoluta($adjunto->attachment_path ?? null);
        if ($ruta && is_file($ruta)) {
            @unlink($ruta);
        }
    }

    /** Sólo dentro de la carpeta privada de egresos: nada de "..". */
    private function rutaAbsoluta(?string $relativa): ?string
    {
        $relativa = ltrim((string) $relativa, '/');
        if ($relativa === '' || str_contains($relativa, '..') || !str_starts_with($relativa, 'privado/egresos/')) {
            return null;
        }

        return storage_path('app/' . $relativa);
    }

    /** Entrega el comprobante del egreso, siempre validando la empresa en sesión. */
    public function verComprobante(int $id, EgresosRepositoryInterface $repo)
    {
        $adjunto = $repo->rutaAdjunto($id);
        $ruta    = $this->rutaAbsoluta($adjunto->attachment_path ?? null);

        if (!$ruta || !is_file($ruta)) {
            return standardApiReponse('El comprobante no está disponible.', null, 1, JsonResponse::HTTP_NOT_FOUND);
        }

        return response()->file($ruta, [
            'Content-Disposition' => 'inline; filename="' . ($adjunto->attachment_name ?: basename($ruta)) . '"',
        ]);
    }

    // ── Categorías por empresa ──────────────────────────────────────────────

    public function listarCategorias(EgresosRepositoryInterface $repo): object
    {
        return standardApiReponse('OK', $repo->getCategorias(), 0, JsonResponse::HTTP_OK);
    }

    public function crearCategoria(Request $request, EgresosRepositoryInterface $repo): object
    {
        $request->validate(['name' => 'required|string|max:120', 'color' => 'nullable|string|max:20']);
        $r = $repo->crearCategoria($request->input('name'), $request->input('color'));

        return standardApiReponse($r['mensaje'], $r['ok'] ? ['id' => $r['id']] : null, $r['ok'] ? 0 : 1, JsonResponse::HTTP_OK);
    }

    public function actualizarCategoria(Request $request, int $id, EgresosRepositoryInterface $repo): object
    {
        $request->validate(['name' => 'nullable|string|max:120', 'color' => 'nullable|string|max:20']);
        $r = $repo->actualizarCategoria($id, $request->input('name'), $request->input('color'));

        return standardApiReponse($r['mensaje'], null, $r['ok'] ? 0 : 1, JsonResponse::HTTP_OK);
    }

    public function alternarCategoria(int $id, EgresosRepositoryInterface $repo): object
    {
        $r = $repo->alternarCategoria($id);

        return standardApiReponse($r['mensaje'], $r['ok'] ? ['active' => $r['active']] : null, $r['ok'] ? 0 : 1, JsonResponse::HTTP_OK);
    }

    public function eliminarCategoria(int $id, EgresosRepositoryInterface $repo): object
    {
        $r = $repo->eliminarCategoria($id);

        return standardApiReponse($r['mensaje'], null, $r['ok'] ? 0 : 1, JsonResponse::HTTP_OK);
    }

    // ── Exportar lo que se está viendo, con los filtros puestos ─────────────

    public function exportEgresosCSV(Request $request, EgresosRepositoryInterface $repo)
    {
        $rows = $repo->exportEgresos($this->filtros($request));

        return response()->streamDownload(function () use ($rows) {
            $h = fopen('php://output', 'w');
            // BOM para que Excel abra bien las tildes.
            fwrite($h, "\xEF\xBB\xBF");
            fputcsv($h, ['#ID', 'Fecha', 'Concepto', 'Categoría', 'Proveedor', 'Comprobante', 'Método de pago', 'Valor', 'Recurrencia', 'Notas', 'Registrado']);
            foreach ($rows as $r) {
                $r = (array) $r;
                fputcsv($h, [
                    $r['id']                  ?? '',
                    $r['fecha']               ?? '',
                    $r['concept']             ?? '',
                    $r['category']            ?? '',
                    $r['supplier']            ?? '',
                    $r['document_number']     ?? '',
                    $r['payment_method_name'] ?? '',
                    $r['value']               ?? 0,
                    $r['recurrence']          ?? '',
                    $r['notes']               ?? '',
                    $r['created_at']          ?? '',
                ]);
            }
            fclose($h);
        }, 'egresos_' . date('Y-m-d') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}

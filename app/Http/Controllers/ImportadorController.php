<?php

namespace App\Http\Controllers;

use App\Models\Importacion;
use App\Models\ImportacionCredencial;
use App\Models\ImportacionFila;
use App\Services\Importador\ImportadorDeClientes;
use App\Services\Importador\Normalizador;
use App\Services\Importador\UrlSegura;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Importar clientes desde WispHub o Mikrowisp, por API o con el archivo
 * exportado. Sólo el ADMIN de la empresa (role:admin en la ruta y se vuelve a
 * mirar acá); todo queda atado a la empresa de la sesión.
 */
class ImportadorController extends Controller
{
    private function companyId(): int
    {
        return (int) getSessionCompanyId();
    }

    private function ok(mixed $data, string $msg = 'OK'): JsonResponse
    {
        return standardApiReponse($msg, $data, 0, JsonResponse::HTTP_OK);
    }

    private function err(string $msg, int $code = JsonResponse::HTTP_OK): JsonResponse
    {
        return standardApiReponse($msg, null, 1, $code);
    }

    private function permitido(): bool
    {
        return $this->companyId() > 0 && sessionUserHasProfile('ADMIN');
    }

    private function importacion(int $id): ?Importacion
    {
        return Importacion::where('id', $id)->where('company_id', $this->companyId())->first();
    }

    /**
     * GET /api/importador
     * Lo que necesita la pantalla para empezar: importaciones anteriores,
     * credenciales guardadas y los planes, routers y grupos de la empresa.
     */
    public function index(): JsonResponse
    {
        if (!$this->permitido()) {
            return $this->err('Sólo un administrador puede importar clientes.', JsonResponse::HTTP_FORBIDDEN);
        }

        $c = $this->companyId();

        return $this->ok([
            'origenes'      => ImportadorDeClientes::ORIGENES,
            'campos'        => Normalizador::CAMPOS,
            'importaciones' => Importacion::where('company_id', $c)->orderByDesc('id')->limit(15)
                ->get(['id', 'origen', 'metodo', 'estado', 'nombre_archivo', 'total', 'procesadas', 'creados', 'actualizados', 'omitidos', 'errores', 'detalle', 'created_at', 'terminada_en']),
            'credenciales'  => ImportacionCredencial::where('company_id', $c)->get(['origen', 'api_url', 'updated_at'])
                ->map(fn ($x) => ['origen' => $x->origen, 'api_url' => $x->api_url, 'guardada_en' => $x->updated_at?->format('Y-m-d H:i')]),
            'planes'        => DB::table('internet_plans')->where('company_id', $c)->orderBy('plan_name')
                ->get(['id', 'plan_name', 'download_speed', 'upload_speed', 'monthly_price', 'active']),
            'routers'       => DB::table('conection_routers')->where('company_id', $c)->orderBy('name')->get(['id', 'name']),
            'grupos'        => DB::table('company_billing_schedules')->where('company_id', $c)->where('active', true)
                ->orderBy('grupo')->get(['grupo', 'billing_day']),
        ]);
    }

    /**
     * POST /api/importador/api
     * { origen, url, token?, usar_guardado?, guardar? } — prueba la conexión y
     * lanza la lectura en segundo plano.
     */
    public function desdeApi(Request $request): JsonResponse
    {
        if (!$this->permitido()) {
            return $this->err('Sólo un administrador puede importar clientes.', JsonResponse::HTTP_FORBIDDEN);
        }

        $datos = $request->validate([
            'origen'        => 'required|in:wisphub,mikrowisp',
            'url'           => 'nullable|string|max:255',
            'token'         => 'nullable|string|max:500',
            'usar_guardado' => 'nullable|boolean',
            'guardar'       => 'nullable|boolean',
        ]);

        $c = $this->companyId();
        $guardada = ImportacionCredencial::where('company_id', $c)->where('origen', $datos['origen'])->first();

        $token = trim((string) ($datos['token'] ?? ''));
        if ($token === '' && !empty($datos['usar_guardado']) && $guardada) {
            $token = (string) $guardada->api_token;
        }
        if ($token === '') {
            return $this->err($datos['origen'] === 'wisphub' ? 'Falta la API Key de WispHub.' : 'Falta el token de la API de Mikrowisp.');
        }

        $url = trim((string) ($datos['url'] ?? ''));
        if ($url === '' && $datos['origen'] === 'wisphub') {
            $url = \App\Services\Importador\Fuentes\WispHub::URL_POR_DEFECTO;
        }
        if ($url === '') {
            return $this->err('Falta la dirección de tu Mikrowisp (por ejemplo https://mikrowisp.tuempresa.com).');
        }

        if (Importacion::where('company_id', $c)->whereIn('estado', ['leyendo', 'en_cola', 'importando'])->where('updated_at', '>', now()->subMinutes(15))->exists()) {
            return $this->err('Ya hay una importación en curso. Esperá a que termine.');
        }

        try {
            $url = UrlSegura::validar($url);
            $mensaje = ImportadorDeClientes::fuente($datos['origen'], $url, $token)->probar();
        } catch (\RuntimeException $e) {
            return $this->err($e->getMessage());
        }

        if (!empty($datos['guardar'])) {
            ImportacionCredencial::updateOrCreate(
                ['company_id' => $c, 'origen' => $datos['origen']],
                ['api_url' => $url, 'api_token' => $token]
            );
        }

        $imp = Importacion::create([
            'company_id' => $c,
            'user_id'    => getSessionUserId(),
            'origen'     => $datos['origen'],
            'metodo'     => 'api',
            'estado'     => 'leyendo',
            'api_url'    => $url,
            'api_token'  => $token,
            'detalle'    => $mensaje . ' Leyendo clientes…',
        ]);

        ImportadorDeClientes::lanzar($imp);

        return $this->ok($this->vista($imp), $mensaje);
    }

    /**
     * POST /api/importador/archivo (multipart: origen, archivo)
     * Lee el encabezado y propone qué columna es cada dato.
     */
    public function desdeArchivo(Request $request): JsonResponse
    {
        if (!$this->permitido()) {
            return $this->err('Sólo un administrador puede importar clientes.', JsonResponse::HTTP_FORBIDDEN);
        }

        $request->validate([
            'origen'  => 'required|in:wisphub,mikrowisp',
            'archivo' => 'required|file|max:20480',
        ], [
            'archivo.required' => 'Elegí el archivo exportado.',
            'archivo.max'      => 'El archivo no puede pasar de 20 MB.',
        ]);

        try {
            $imp = ImportadorDeClientes::crearDesdeArchivo($this->companyId(), getSessionUserId(), $request->input('origen'), $request->file('archivo'));
        } catch (\RuntimeException $e) {
            return $this->err($e->getMessage());
        }

        return $this->ok($this->vista($imp) + ['muestra' => ImportadorDeClientes::muestra($imp)], 'Archivo leído.');
    }

    /**
     * POST /api/importador/{id}/mapeo  { mapeo: {campo: índice|null} }
     * Arma la vista previa con las columnas confirmadas.
     */
    public function mapeo(int $id, Request $request): JsonResponse
    {
        if (!$this->permitido()) {
            return $this->err('Sólo un administrador puede importar clientes.', JsonResponse::HTTP_FORBIDDEN);
        }

        $imp = $this->importacion($id);
        if (!$imp || $imp->metodo !== 'archivo') {
            return $this->err('No existe esa importación.', JsonResponse::HTTP_NOT_FOUND);
        }
        if (!in_array($imp->estado, ['mapeo', 'analizado'], true)) {
            return $this->err('Esta importación ya se ejecutó: subí el archivo de nuevo para otra.');
        }

        try {
            ImportadorDeClientes::aplicarMapeo($imp, (array) $request->input('mapeo', []));
        } catch (\RuntimeException $e) {
            return $this->err($e->getMessage());
        }

        return $this->ok($this->vista($imp->fresh()), 'Vista previa lista.');
    }

    /** GET /api/importador/{id} — estado, avance y vista previa. */
    public function ver(int $id): JsonResponse
    {
        if (!$this->permitido()) {
            return $this->err('Sólo un administrador puede importar clientes.', JsonResponse::HTTP_FORBIDDEN);
        }

        $imp = $this->importacion($id);
        if (!$imp) {
            return $this->err('No existe esa importación.', JsonResponse::HTTP_NOT_FOUND);
        }

        $extra = $imp->estado === 'mapeo' ? ['muestra' => ImportadorDeClientes::muestra($imp)] : [];

        return $this->ok($this->vista($imp) + $extra, (string) $imp->detalle);
    }

    /**
     * GET /api/importador/{id}/filas?filtro=&buscar=&pagina=
     * filtro: nuevo | existente | invalido | avisos | creado | actualizado | omitido | error
     */
    public function filas(int $id, Request $request): JsonResponse
    {
        if (!$this->permitido()) {
            return $this->err('Sólo un administrador puede importar clientes.', JsonResponse::HTTP_FORBIDDEN);
        }

        $imp = $this->importacion($id);
        if (!$imp) {
            return $this->err('No existe esa importación.', JsonResponse::HTTP_NOT_FOUND);
        }

        $filtro = (string) $request->query('filtro', '');
        $buscar = trim((string) $request->query('buscar', ''));
        $porPagina = 50;
        $pagina = max(1, (int) $request->query('pagina', 1));

        $q = ImportacionFila::where('importacion_id', $imp->id)
            ->when(in_array($filtro, ['nuevo', 'existente', 'invalido'], true), fn ($q) => $q->where('previo', $filtro))
            ->when(in_array($filtro, ['creado', 'actualizado', 'omitido', 'error'], true), fn ($q) => $q->where('resultado', $filtro))
            ->when($filtro === 'avisos', fn ($q) => $q->whereRaw("JSON_LENGTH(avisos, '$.avisos') > 0"))
            ->when($buscar !== '', fn ($q) => $q->where(fn ($w) => $w->where('nombre', 'like', "%{$buscar}%")->orWhere('dni', 'like', "%{$buscar}%")->orWhere('external_id', $buscar)));

        $total = (clone $q)->count();
        $filas = $q->orderBy('fila')->forPage($pagina, $porPagina)->get()
            ->map(fn ($f) => ImportadorDeClientes::filaParaMostrar($f));

        return $this->ok(['total' => $total, 'pagina' => $pagina, 'por_pagina' => $porPagina, 'filas' => $filas]);
    }

    /**
     * POST /api/importador/{id}/ejecutar
     * { planes: {clave: id|"crear"}, routers: {clave: id|null}, estados: [...],
     *   existentes: omitir|actualizar, grupo, grupo_por_dia, cobro_mes_completo, tipo_plan }
     */
    public function ejecutar(int $id, Request $request): JsonResponse
    {
        if (!$this->permitido()) {
            return $this->err('Sólo un administrador puede importar clientes.', JsonResponse::HTTP_FORBIDDEN);
        }

        $imp = $this->importacion($id);
        if (!$imp) {
            return $this->err('No existe esa importación.', JsonResponse::HTTP_NOT_FOUND);
        }

        $reanudar = in_array($imp->estado, ['cancelada', 'error'], true)
            && ImportacionFila::where('importacion_id', $imp->id)->whereNotNull('resultado')->exists()
            && ImportacionFila::where('importacion_id', $imp->id)->whereNull('resultado')->exists();

        if ($imp->estado !== 'analizado' && !$reanudar) {
            return $this->err('La importación no está lista para ejecutarse.');
        }

        $c = $this->companyId();

        $datos = $request->validate([
            'planes'             => 'required|array',
            'routers'            => 'nullable|array',
            'estados'            => 'required|array|min:1',
            'estados.*'          => 'in:activo,suspendido,retirado',
            'existentes'         => 'required|in:omitir,actualizar',
            'grupo'              => 'required|integer',
            'grupo_por_dia'      => 'nullable|boolean',
            'cobro_mes_completo' => 'nullable|boolean',
            'tipo_plan'          => 'nullable|in:fibra,wireless,cable,dsl,otro',
        ], [
            'estados.required' => 'Elegí qué estados de cliente importar.',
            'grupo.required'   => 'Elegí el grupo de facturación.',
        ]);

        $grupos = DB::table('company_billing_schedules')->where('company_id', $c)->where('active', true)->pluck('grupo')->map(fn ($g) => (int) $g)->all();
        if (!$grupos) {
            return $this->err('La empresa no tiene grupos de facturación. Configuralos en Configuración › Facturación antes de importar.');
        }
        if (!in_array((int) $datos['grupo'], $grupos, true)) {
            return $this->err('El grupo de facturación no es de la empresa.');
        }

        // Planes y routers elegidos: sólo de esta empresa.
        $planesPropios = DB::table('internet_plans')->where('company_id', $c)->pluck('id')->map(fn ($x) => (int) $x)->all();
        $routersPropios = DB::table('conection_routers')->where('company_id', $c)->pluck('id')->map(fn ($x) => (int) $x)->all();

        $planes = [];
        foreach ($datos['planes'] as $clave => $valor) {
            if ($valor === 'crear') {
                $planes[(string) $clave] = 'crear';
            } elseif ($valor !== null && $valor !== '') {
                if (!in_array((int) $valor, $planesPropios, true)) {
                    return $this->err('Uno de los planes elegidos no es de la empresa.');
                }
                $planes[(string) $clave] = (int) $valor;
            }
        }

        $routers = [];
        foreach ((array) ($datos['routers'] ?? []) as $clave => $valor) {
            if ($valor !== null && $valor !== '') {
                if (!in_array((int) $valor, $routersPropios, true)) {
                    return $this->err('Uno de los routers elegidos no es de la empresa.');
                }
                $routers[(string) $clave] = (int) $valor;
            }
        }

        $imp->update([
            'estado'   => 'en_cola',
            'opciones' => [
                'planes'             => $planes,
                'routers'            => $routers,
                'estados'            => array_values(array_unique($datos['estados'])),
                'existentes'         => $datos['existentes'],
                'grupo'              => (int) $datos['grupo'],
                'grupo_por_dia'      => (bool) ($datos['grupo_por_dia'] ?? false),
                'cobro_mes_completo' => (bool) ($datos['cobro_mes_completo'] ?? true),
                'tipo_plan'          => $datos['tipo_plan'] ?? 'fibra',
            ],
            'detalle'  => 'En cola: arrancando la importación…',
        ]);

        ImportadorDeClientes::lanzar($imp);

        return $this->ok($this->vista($imp->fresh()), 'Importación en marcha.');
    }

    /** POST /api/importador/{id}/cancelar */
    public function cancelar(int $id): JsonResponse
    {
        if (!$this->permitido()) {
            return $this->err('Sólo un administrador puede importar clientes.', JsonResponse::HTTP_FORBIDDEN);
        }

        $imp = $this->importacion($id);
        if (!$imp) {
            return $this->err('No existe esa importación.', JsonResponse::HTTP_NOT_FOUND);
        }

        if (in_array($imp->estado, ['mapeo', 'analizado'], true)) {
            $imp->update(['estado' => 'cancelada', 'api_token' => null, 'detalle' => 'Descartada sin importar.']);
        } elseif (in_array($imp->estado, ['leyendo', 'en_cola', 'importando'], true)) {
            // Si el proceso murió (sin avance en 10 minutos) se da por cancelada;
            // si sigue vivo, él mismo se detiene al ver el pedido.
            $colgada = $imp->updated_at && $imp->updated_at->lt(now()->subMinutes(10));
            $imp->update($colgada
                ? ['estado' => $imp->estado === 'leyendo' ? 'error' : 'cancelada', 'api_token' => null, 'detalle' => 'Se detuvo sin terminar.']
                : ['estado' => 'cancelando', 'detalle' => 'Deteniendo…']);
        }

        return $this->ok($this->vista($imp->fresh()), 'Listo.');
    }

    /** GET /api/importador/{id}/reporte — CSV con el resultado de cada fila. */
    public function reporte(int $id): StreamedResponse|JsonResponse
    {
        if (!$this->permitido()) {
            return $this->err('Sólo un administrador puede importar clientes.', JsonResponse::HTTP_FORBIDDEN);
        }

        $imp = $this->importacion($id);
        if (!$imp) {
            return $this->err('No existe esa importación.', JsonResponse::HTTP_NOT_FOUND);
        }

        $nombre = "importacion-{$imp->origen}-{$imp->id}.csv";

        return response()->streamDownload(function () use ($imp) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Fila', 'ID origen', 'Documento', 'Nombre', 'Plan', 'Conexión', 'IP / usuario PPPoE', 'Router', 'Estado', 'Saldo origen', 'Vista previa', 'Resultado', 'Mensaje', 'Errores', 'Avisos', 'ID cliente'], ';');

            ImportacionFila::where('importacion_id', $imp->id)->orderBy('id')->chunkById(500, function ($filas) use ($out) {
                foreach ($filas as $f) {
                    $d = $f->datos ?? [];
                    fputcsv($out, [
                        $f->fila, $f->external_id, $f->dni, $f->nombre, $d['plan'] ?? '',
                        ($d['tipo_conexion'] ?? '') === 'pppoe' ? 'PPPoE' : 'IP fija',
                        ($d['tipo_conexion'] ?? '') === 'pppoe' ? ($d['pppoe_usuario'] ?? '') : ($d['ip'] ?? ''),
                        $d['router'] ?? '', $d['estado_origen'] ?? '', $d['saldo'] ?? '',
                        $f->previo, $f->resultado ?? 'pendiente', $f->mensaje,
                        implode(' | ', $f->avisos['errores'] ?? []), implode(' | ', $f->avisos['avisos'] ?? []),
                        $f->user_id ?? $f->existente_user_id,
                    ], ';');
                }
            });

            fclose($out);
        }, $nombre, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** DELETE /api/importador/credenciales/{origen} */
    public function olvidarCredencial(string $origen): JsonResponse
    {
        if (!$this->permitido()) {
            return $this->err('Sólo un administrador puede importar clientes.', JsonResponse::HTTP_FORBIDDEN);
        }

        ImportacionCredencial::where('company_id', $this->companyId())->where('origen', $origen)->delete();

        return $this->ok(null, 'Credenciales borradas.');
    }

    private function vista(Importacion $imp): array
    {
        return $imp->only([
            'id', 'origen', 'metodo', 'estado', 'api_url', 'nombre_archivo', 'columnas', 'mapeo', 'opciones', 'analisis',
            'total', 'procesadas', 'creados', 'actualizados', 'omitidos', 'errores', 'detalle',
        ]) + [
            'creada_en'     => $imp->created_at?->format('Y-m-d H:i'),
            'actualizada_en' => $imp->updated_at?->toIso8601String(),
            'terminada_en'  => $imp->terminada_en?->format('Y-m-d H:i'),
        ];
    }
}

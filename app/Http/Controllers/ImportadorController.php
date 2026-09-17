<?php

namespace App\Http\Controllers;

use App\Models\Importacion;
use App\Models\ImportacionCredencial;
use App\Models\ImportacionFila;
use App\Models\CompanyBillingSchedule;
use App\Services\Importador\CotejoConElRouter;
use App\Services\Importador\Esquema;
use App\Services\Importador\ImportadorDeClientes;
use App\Services\Importador\Normalizador;
use App\Services\Importador\SeparadorDeNombres;
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
            'grupos'        => $this->grupos($c),
            'reglas_nombre' => SeparadorDeNombres::REGLAS,
            // Si falta correr la migración de la segunda tanda, la pantalla
            // esconde la factura de saldo y la asignación por cliente.
            'falta_migracion' => Esquema::loQueFalta(),
            // Para avisar en pantalla qué se dispara si la factura del saldo
            // queda vencida.
            'avisos_empresa' => $this->avisosDeLaEmpresa($c),
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

        return $this->ok($this->vista($imp) + [
            'muestra'        => ImportadorDeClientes::muestra($imp),
            'mapeo_sugerido' => $imp->mapeo,
        ], 'Archivo leído.');
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

        // La muestra también en la vista previa: desde ahí se puede volver a
        // revisar las columnas sin subir el archivo de nuevo.
        $extra = in_array($imp->estado, ['mapeo', 'analizado'], true) && $imp->archivo
            ? [
                'muestra' => ImportadorDeClientes::muestra($imp),
                // Lo que el sistema detecta solo, por si hay que volver a empezar.
                'mapeo_sugerido' => Normalizador::sugerirMapeo((array) $imp->columnas, $imp->origen),
            ]
            : [];

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

        $q = $this->filtrarFilas(ImportacionFila::where('importacion_id', $imp->id), $filtro, $buscar);

        $total = (clone $q)->count();
        $regla = (string) ($imp->opciones['regla_nombre'] ?? 'auto');
        $filas = $q->orderBy('fila')->forPage($pagina, $porPagina)->get()
            ->map(fn ($f) => ImportadorDeClientes::filaParaMostrar($f, $regla));

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
            'precios'            => 'nullable|array',
            'precios.*'          => 'nullable|numeric|min:0|max:100000000',
            'actualizar_precio'  => 'nullable|array',
            'routers'            => 'nullable|array',
            'router_todos'       => 'nullable|integer',
            'estados'            => 'required|array|min:1',
            'estados.*'          => 'in:activo,suspendido,retirado',
            'existentes'         => 'required|in:omitir,actualizar',
            'grupo'              => 'required|integer',
            'grupo_modo'         => 'nullable|in:todos,plan,router,estado',
            'grupos_por_plan'    => 'nullable|array',
            'grupos_por_router'  => 'nullable|array',
            'grupos_por_estado'  => 'nullable|array',
            'grupo_por_dia'      => 'nullable|boolean',
            'cobro_mes_completo' => 'nullable|boolean',
            'tipo_plan'          => 'nullable|in:fibra,wireless,cable,dsl,otro',
            'regla_nombre'       => 'nullable|string|max:20',
            'saldo'              => 'nullable|array',
            'saldo.crear'        => 'nullable|boolean',
            'saldo.concepto'     => 'nullable|string|max:160',
            'saldo.fecha_modo'   => 'nullable|in:corte,hoy,fecha',
            'saldo.fecha'        => 'nullable|date_format:Y-m-d',
            'saldo.evitar_envio' => 'nullable|boolean',
        ], [
            'estados.required' => 'Elegí qué estados de cliente importar.',
            'grupo.required'   => 'Elegí el grupo de facturación.',
            'precios.*.numeric' => 'El valor del plan tiene que ser un número.',
        ]);

        $grupos = CompanyBillingSchedule::where('company_id', $c)->where('active', true)->pluck('grupo')->map(fn ($g) => (int) $g)->all();
        if (!$grupos) {
            return $this->err('La empresa no tiene grupos de facturación. Creá al menos uno antes de importar.');
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

        $routerTodos = (int) ($datos['router_todos'] ?? 0);
        if ($routerTodos && !in_array($routerTodos, $routersPropios, true)) {
            return $this->err('El router elegido no es de la empresa.');
        }

        // Los grupos de cada regla también tienen que existir en la empresa.
        $porRegla = [];
        foreach (['grupos_por_plan', 'grupos_por_router', 'grupos_por_estado'] as $campo) {
            $porRegla[$campo] = [];

            foreach ((array) ($datos[$campo] ?? []) as $clave => $valor) {
                if ($valor === null || $valor === '') {
                    continue;
                }
                if (!in_array((int) $valor, $grupos, true)) {
                    return $this->err('Uno de los grupos de facturación elegidos no es de la empresa.');
                }
                $porRegla[$campo][(string) $clave] = (int) $valor;
            }
        }

        // Guarda vieja de la base: cab_facturations.group tiene una clave
        // foránea contra company_billing_schedules.id (no contra el número de
        // grupo). Con los grupos 1 a 4 siempre se cumple, pero si alguna vez no,
        // conviene avisarlo antes y no fallar cliente por cliente.
        $usados = array_unique(array_merge([(int) $datos['grupo']], array_values($porRegla['grupos_por_plan'] ?? []), array_values($porRegla['grupos_por_router'] ?? []), array_values($porRegla['grupos_por_estado'] ?? [])));
        $existentes = CompanyBillingSchedule::whereIn('id', $usados)->pluck('id')->map(fn ($x) => (int) $x)->all();

        foreach ($usados as $g) {
            if (!in_array((int) $g, $existentes, true)) {
                return $this->err("El grupo {$g} no se puede usar todavía por cómo está armada la facturación en la base. Elegí otro grupo o avisale al soporte.");
            }
        }

        $precios = [];
        foreach ((array) ($datos['precios'] ?? []) as $clave => $valor) {
            if ($valor !== null && $valor !== '') {
                $precios[(string) $clave] = round((float) $valor, 2);
            }
        }

        $saldo = (array) ($datos['saldo'] ?? []);

        if (!empty($saldo['crear']) && !Esquema::conceptoEnFacturas()) {
            return $this->err(Esquema::loQueFalta());
        }

        $saldo = [
            'crear'        => (bool) ($saldo['crear'] ?? false),
            'concepto'     => trim((string) ($saldo['concepto'] ?? '')) ?: 'Saldo anterior de ' . ucfirst($imp->origen),
            'fecha_modo'   => $saldo['fecha_modo'] ?? 'corte',
            'fecha'        => $saldo['fecha'] ?? null,
            'evitar_envio' => (bool) ($saldo['evitar_envio'] ?? true),
        ];

        $imp->update([
            'estado'   => 'en_cola',
            'opciones' => [
                'planes'             => $planes,
                'precios'            => $precios,
                'actualizar_precio'  => array_map(fn ($v) => (bool) $v, (array) ($datos['actualizar_precio'] ?? [])),
                'routers'            => $routers,
                'router_todos'       => $routerTodos,
                'estados'            => array_values(array_unique($datos['estados'])),
                'existentes'         => $datos['existentes'],
                'grupo'              => (int) $datos['grupo'],
                'grupo_modo'         => $datos['grupo_modo'] ?? 'todos',
                'grupos_por_plan'    => $porRegla['grupos_por_plan'],
                'grupos_por_router'  => $porRegla['grupos_por_router'],
                'grupos_por_estado'  => $porRegla['grupos_por_estado'],
                'grupo_por_dia'      => (bool) ($datos['grupo_por_dia'] ?? false),
                'cobro_mes_completo' => (bool) ($datos['cobro_mes_completo'] ?? true),
                'tipo_plan'          => $datos['tipo_plan'] ?? 'fibra',
                'regla_nombre'       => $datos['regla_nombre'] ?? ($imp->opciones['regla_nombre'] ?? 'auto'),
                'saldo'              => $saldo,
            ],
            'detalle'  => 'En cola: arrancando la importación…',
        ]);

        ImportadorDeClientes::lanzar($imp);

        return $this->ok($this->vista($imp->fresh()), 'Importación en marcha.');
    }

    /**
     * POST /api/importador/{id}/nombres  { regla }
     * Guarda con qué regla se parte el nombre completo y devuelve ejemplos
     * reales del archivo para que se vea el efecto.
     */
    public function nombres(int $id, Request $request): JsonResponse
    {
        if (!$this->permitido()) {
            return $this->err('Sólo un administrador puede importar clientes.', JsonResponse::HTTP_FORBIDDEN);
        }

        $imp = $this->importacion($id);
        if (!$imp) {
            return $this->err('No existe esa importación.', JsonResponse::HTTP_NOT_FOUND);
        }

        $regla = (string) $request->input('regla', 'auto');
        if (!array_key_exists($regla, SeparadorDeNombres::REGLAS)) {
            return $this->err('Esa forma de separar el nombre no existe.');
        }

        if ($imp->estado === 'analizado') {
            $imp->update(['opciones' => array_merge((array) $imp->opciones, ['regla_nombre' => $regla])]);
        }

        return $this->ok([
            'regla'    => $regla,
            'ejemplos' => ImportadorDeClientes::ejemplosDeNombres($imp, $regla),
        ]);
    }

    /**
     * POST /api/importador/{id}/asignar
     * { ids: [], todas: bool, filtro, buscar, grupo: int|null, router: int|null }
     * Le pone el grupo de facturación o el router a los clientes elegidos.
     */
    public function asignar(int $id, Request $request): JsonResponse
    {
        if (!$this->permitido()) {
            return $this->err('Sólo un administrador puede importar clientes.', JsonResponse::HTTP_FORBIDDEN);
        }

        $imp = $this->importacion($id);
        if (!$imp || $imp->estado !== 'analizado') {
            return $this->err('La importación no está en la vista previa.', JsonResponse::HTTP_NOT_FOUND);
        }

        $datos = $request->validate([
            'ids'    => 'nullable|array',
            'ids.*'  => 'integer',
            'todas'  => 'nullable|boolean',
            'filtro' => 'nullable|string|max:20',
            'buscar' => 'nullable|string|max:80',
            'grupo'  => 'nullable|integer',
            'router' => 'nullable|integer',
        ]);

        $c = $this->companyId();
        $cambios = [];

        if ($request->has('grupo')) {
            $grupo = $datos['grupo'] ? (int) $datos['grupo'] : null;

            if ($grupo && !CompanyBillingSchedule::where('company_id', $c)->where('grupo', $grupo)->exists()) {
                return $this->err('El grupo de facturación no es de la empresa.');
            }

            $cambios['grupo_elegido'] = $grupo;
        }

        if ($request->has('router')) {
            $router = $datos['router'] ? (int) $datos['router'] : null;

            if ($router && !DB::table('conection_routers')->where('company_id', $c)->where('id', $router)->exists()) {
                return $this->err('El router no es de la empresa.');
            }

            $cambios['router_elegido'] = $router;
        }

        if (!$cambios) {
            return $this->err('No se indicó qué asignar.');
        }

        if (!Esquema::filasAmpliadas()) {
            return $this->err(Esquema::loQueFalta());
        }

        $q = ImportacionFila::where('importacion_id', $imp->id);

        if (!empty($datos['todas'])) {
            $q = $this->filtrarFilas($q, (string) ($datos['filtro'] ?? ''), trim((string) ($datos['buscar'] ?? '')));
        } elseif (!empty($datos['ids'])) {
            $q->whereIn('id', $datos['ids']);
        } else {
            return $this->err('Elegí al menos un cliente.');
        }

        $cuantas = $q->update($cambios);

        return $this->ok(['filas' => $cuantas], "{$cuantas} cliente(s) actualizados.");
    }

    /**
     * POST /api/importador/grupos  { grupo?, billing_day, billing_hour?, nombre? }
     * Crea (o ajusta) un grupo de facturación de la empresa, sin salir del
     * importador. Usa la misma tabla que Configuración › Facturación.
     */
    public function crearGrupo(Request $request): JsonResponse
    {
        if (!$this->permitido()) {
            return $this->err('Sólo un administrador puede importar clientes.', JsonResponse::HTTP_FORBIDDEN);
        }

        $datos = $request->validate([
            'grupo'        => 'nullable|integer|min:1|max:4',
            'billing_day'  => 'required|integer|min:1|max:28',
            'billing_hour' => 'nullable|integer|min:0|max:23',
            'nombre'       => 'nullable|string|max:60',
        ], [
            'billing_day.required' => 'Elegí el día del mes en que se factura.',
            'billing_day.max'      => 'El día tiene que ser del 1 al 28 (para que exista en todos los meses).',
        ]);

        $c = $this->companyId();
        $grupo = (int) ($datos['grupo'] ?? 0);

        if (!$grupo) {
            // El primer número libre: la facturación admite del 1 al 4.
            $usados = CompanyBillingSchedule::where('company_id', $c)->pluck('grupo')->map(fn ($g) => (int) $g)->all();

            foreach ([1, 2, 3, 4] as $n) {
                if (!in_array($n, $usados, true)) {
                    $grupo = $n;
                    break;
                }
            }

            if (!$grupo) {
                return $this->err('La empresa ya tiene los 4 grupos de facturación. Cambiá el día de alguno en vez de crear otro.');
            }
        }

        CompanyBillingSchedule::updateOrCreate(
            ['company_id' => $c, 'grupo' => $grupo],
            [
                'billing_day'  => (int) $datos['billing_day'],
                'billing_hour' => (int) ($datos['billing_hour'] ?? 1),
                'nombre'       => trim((string) ($datos['nombre'] ?? '')) ?: null,
                'active'       => true,
            ]
        );

        return $this->ok(['grupos' => $this->grupos($c)], "Grupo {$grupo}: se factura el día {$datos['billing_day']}.");
    }

    /**
     * POST /api/importador/{id}/cotejo  { router_id?, amarrar? }
     * Compara los clientes importados con el ARP y los usuarios PPPoE del
     * MikroTik. Sólo lee el router; con amarrar=true anota el router en la
     * ficha de los que calzaron (eso sí se guarda, pero en la plataforma).
     */
    public function cotejo(int $id, Request $request, \App\Managers\Interfaces\ConectionRouterManagerInterface $conexion): JsonResponse
    {
        if (!$this->permitido()) {
            return $this->err('Sólo un administrador puede importar clientes.', JsonResponse::HTTP_FORBIDDEN);
        }

        $imp = $this->importacion($id);
        if (!$imp) {
            return $this->err('No existe esa importación.', JsonResponse::HTTP_NOT_FOUND);
        }

        $c = $this->companyId();
        $routerId = $request->input('router_id') ? (int) $request->input('router_id') : null;

        if ($routerId && !DB::table('conection_routers')->where('company_id', $c)->where('id', $routerId)->exists()) {
            return $this->err('El router no es de la empresa.');
        }

        $cotejo = new CotejoConElRouter($conexion, $c);

        $datos = $request->boolean('amarrar')
            ? $cotejo->amarrar($imp, $routerId)
            : $cotejo->revisar($imp, $routerId);

        $huboError = $datos['errores'] !== [] && ($datos['encontrados'] ?? 0) === 0;

        return $this->ok($datos, $huboError ? implode(' ', $datos['errores']) : 'Comparación lista.');
    }

    /** @param  \Illuminate\Database\Eloquent\Builder $q */
    private function filtrarFilas($q, string $filtro, string $buscar)
    {
        return $q
            ->when(in_array($filtro, ['nuevo', 'existente', 'invalido'], true), fn ($q) => $q->where('previo', $filtro))
            ->when(in_array($filtro, ['creado', 'actualizado', 'omitido', 'error'], true), fn ($q) => $q->where('resultado', $filtro))
            ->when($filtro === 'avisos', fn ($q) => $q->whereRaw("JSON_LENGTH(avisos, '$.avisos') > 0"))
            ->when($filtro === 'saldo', fn ($q) => $q->whereRaw("JSON_LENGTH(avisos, '$.avisos') > 0")->where('avisos', 'like', '%Debe %'))
            ->when($buscar !== '', fn ($q) => $q->where(fn ($w) => $w->where('nombre', 'like', "%{$buscar}%")->orWhere('dni', 'like', "%{$buscar}%")->orWhere('external_id', $buscar)));
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

        // Con qué quedó cada cliente: plan y valor, día de cobro, router y la
        // factura del saldo, que es lo que el dueño va a querer revisar.
        $c = $this->companyId();
        $planes = DB::table('internet_plans')->where('company_id', $c)->get(['id', 'plan_name', 'monthly_price'])->keyBy('id');
        $routers = DB::table('conection_routers')->where('company_id', $c)->pluck('name', 'id');
        $dias = CompanyBillingSchedule::where('company_id', $c)->pluck('billing_day', 'grupo');
        $facturas = DB::table('det_facturations')
            ->whereIn('id', ImportacionFila::where('importacion_id', $imp->id)->whereNotNull('factura_id')->pluck('factura_id'))
            ->get(['id', 'number_facture', 'price_total', 'date_facturation'])->keyBy('id');
        $eleccionPlanes = (array) ($imp->opciones['planes'] ?? []);
        $regla = (string) ($imp->opciones['regla_nombre'] ?? 'auto');

        return response()->streamDownload(function () use ($imp, $planes, $routers, $dias, $facturas, $eleccionPlanes, $regla) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'Fila', 'ID origen', 'Documento', 'Nombres', 'Apellidos', 'Plan en el origen', 'Plan asignado', 'Valor mensual',
                'Conexión', 'IP / usuario PPPoE', 'Router en el origen', 'Router asignado', 'Grupo', 'Día de cobro',
                'Estado', 'Saldo del origen', 'Factura de saldo', 'Valor de la factura', 'Fecha de la factura',
                'Vista previa', 'Resultado', 'Mensaje', 'Errores', 'Avisos', 'ID cliente',
            ], ';');

            ImportacionFila::where('importacion_id', $imp->id)->orderBy('id')->chunkById(500, function ($filas) use ($out, $planes, $routers, $dias, $facturas, $eleccionPlanes, $regla) {
                foreach ($filas as $f) {
                    $d = Normalizador::conRegla($f->datos ?? [], $regla);
                    $planId = $eleccionPlanes[Normalizador::clave($d['plan'] ?? '')] ?? null;
                    $plan = is_numeric($planId) ? ($planes[$planId] ?? null) : null;
                    $factura = $f->factura_id ? ($facturas[$f->factura_id] ?? null) : null;

                    fputcsv($out, [
                        $f->fila, $f->external_id, $f->dni, $d['nombres'] ?? '', $d['apellidos'] ?? '',
                        $d['plan'] ?? '', $plan->plan_name ?? ($planId === 'crear' ? '(se crea)' : ''), $plan->monthly_price ?? '',
                        ($d['tipo_conexion'] ?? '') === 'pppoe' ? 'PPPoE' : 'IP fija',
                        ($d['tipo_conexion'] ?? '') === 'pppoe' ? ($d['pppoe_usuario'] ?? '') : ($d['ip'] ?? ''),
                        $d['router'] ?? '', $f->router_elegido ? ($routers[$f->router_elegido] ?? '') : '',
                        $f->grupo_elegido ?: '', $f->grupo_elegido ? ($dias[$f->grupo_elegido] ?? '') : '',
                        $d['estado_origen'] ?? '', $d['saldo'] ?? '',
                        $factura->number_facture ?? '', $factura->price_total ?? '', $factura->date_facturation ?? '',
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

    /** Los grupos de facturación de la empresa, con cuántos clientes tiene cada uno. */
    private function grupos(int $companyId): array
    {
        $conteos = DB::table('cab_facturations')->where('company_id', $companyId)
            ->select('group', DB::raw('COUNT(DISTINCT user_id) as clientes'))
            ->groupBy('group')->pluck('clientes', 'group');

        return CompanyBillingSchedule::where('company_id', $companyId)
            ->orderBy('grupo')
            ->get(['grupo', 'nombre', 'billing_day', 'billing_hour', 'active'])
            ->map(fn ($g) => [
                'grupo'       => (int) $g->grupo,
                'nombre'      => $g->nombre,
                'billing_day' => (int) $g->billing_day,
                'billing_hour' => (int) $g->billing_hour,
                'active'      => (bool) $g->active,
                'clientes'    => (int) ($conteos[$g->grupo] ?? 0),
            ])->all();
    }

    /**
     * Qué tiene prendido la empresa que se dispara con una factura sin pagar:
     * recordatorios por WhatsApp, envío por correo y corte automático.
     */
    private function avisosDeLaEmpresa(int $companyId): array
    {
        $empresa = DB::table('companies')->where('id', $companyId)->first(['email_enabled', 'email_daily_limit']);
        $corte = DB::table('auto_suspend_configs')->where('company_id', $companyId)->first(['enabled', 'days_overdue', 'suspension_day']);

        $wa = DB::table('wa_template_bindings')
            ->where('company_id', $companyId)
            ->whereIn('event', ['recordatorio_pago', 'suspension_mora'])
            ->where('enabled', true)
            ->pluck('event')->all();

        return [
            'whatsapp'       => $wa,
            'email'          => (bool) ($empresa->email_enabled ?? false),
            'auto_suspende'  => (bool) ($corte->enabled ?? false),
            'facturas_corte' => (int) ($corte->days_overdue ?? 0),
            'dia_corte'      => (int) ($corte->suspension_day ?? 0),
        ];
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

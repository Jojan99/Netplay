<?php

namespace App\Http\Controllers;

use App\Models\FacturaElectronica;
use App\Models\FacturaElectronicaConfig;
use App\Services\FacturaElectronica\FacturacionElectronica;
use App\Services\FacturaElectronica\Proveedores\ErrorDelProveedor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Facturación electrónica (DIAN) de la empresa en sesión.
 *
 * Conectar el proveedor y sus ajustes es del administrador. Emitir, reintentar
 * y hacer notas crédito, de quien maneja la plata: administrador y contador.
 */
class FacturaElectronicaController extends Controller
{
    private function servicio(): FacturacionElectronica
    {
        return new FacturacionElectronica((int) getSessionCompanyId());
    }

    private function puedeEmitir(): bool
    {
        $perfil = strtoupper((string) DB::table('profiles')->where('id', getSessionUserProfileId())->value('name'));

        return in_array($perfil, ['ADMIN', 'CONTADOR'], true);
    }

    private function responder(string $mensaje, mixed $data = null, int $error = 0, int $http = 200): JsonResponse
    {
        return response()->json(['message' => $mensaje, 'data' => $data, 'error' => $error], $http);
    }

    /** GET /api/factura-electronica */
    public function estado(): JsonResponse
    {
        $s      = $this->servicio();
        $config = $s->config();

        $proveedores = [];
        foreach (FacturacionElectronica::PROVEEDORES as $clave => $p) {
            $proveedores[] = ['id' => $clave, 'nombre' => $p['nombre'], 'campos' => $p['clase']::campos()];
        }

        $empresa = (int) getSessionCompanyId();
        $conteo  = FacturaElectronica::where('company_id', $empresa)->selectRaw('estado, COUNT(*) n')->groupBy('estado')->pluck('n', 'estado');

        return $this->responder('OK', [
            'proveedores' => $proveedores,
            'config'      => $config ? [
                'proveedor'     => $config->proveedor,
                'activa'        => $config->activa,
                'automatica'    => $config->automatica,
                'emitir_desde'  => $config->emitir_desde?->toDateString(),
                'ajustes'       => (object) ($config->ajustes ?? []),
                // Las credenciales no viajan: sólo cuáles están cargadas.
                'credenciales'  => array_map(fn ($v) => $v !== null && $v !== '', (array) ($config->credenciales ?? [])),
                'verificada_en' => $config->verificada_en?->toDateTimeString(),
            ] : null,
            'faltantes' => $config ? $s->faltantes($config) : [],
            'resumen'   => [
                'emitidas'   => (int) ($conteo['emitida'] ?? 0),
                'rechazadas' => (int) ($conteo['rechazada'] ?? 0),
                'errores'    => (int) ($conteo['error'] ?? 0),
                'pendientes' => (int) ($conteo['pendiente'] ?? 0),
                'por_emitir' => $config ? $this->porEmitirQuery($config)->count() : 0,
            ],
            // Cuántos clientes tienen la marca «factura electrónica»: a ellos se les factura ante la DIAN.
            'clientes' => [
                'marcados' => DB::table('cab_facturations')->where('company_id', $empresa)->where('billing_electronic', 1)->count(),
                'total'    => DB::table('cab_facturations')->where('company_id', $empresa)->count(),
            ],
        ]);
    }

    /** PUT /api/factura-electronica */
    public function guardar(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'proveedor'      => 'required|in:' . implode(',', array_keys(FacturacionElectronica::PROVEEDORES)),
            'activa'         => 'boolean',
            'automatica'     => 'boolean',
            'emitir_desde'   => 'nullable|date',
            'credenciales'   => 'nullable|array',
            'credenciales.*' => 'nullable|string|max:500',
            'ajustes'        => 'nullable|array',
        ]);

        $empresa = (int) getSessionCompanyId();
        $config  = FacturaElectronicaConfig::firstOrNew(['company_id' => $empresa]);
        $cambio  = $config->exists && $config->proveedor !== $datos['proveedor'];

        // Sólo se pisan las credenciales que llegan con valor: el panel nunca las muestra.
        $campos       = array_keys(FacturacionElectronica::PROVEEDORES[$datos['proveedor']]['clase']::campos());
        $credenciales = $cambio ? [] : (array) ($config->credenciales ?? []);

        foreach ($campos as $campo) {
            $valor = trim((string) ($datos['credenciales'][$campo] ?? ''));
            if ($valor !== '') {
                $credenciales[$campo] = $valor;
            }
        }

        $permitidos = ['numeracion_id', 'numeracion_nc_id', 'vendedor_id', 'forma_pago_id', 'producto_id', 'impuesto_id', 'impuesto_porcentaje',
            'centro_costo_id', 'cuenta_id', 'medio_pago', 'alcance', 'iva', 'estratos_excluidos', 'municipio', 'ciudad', 'departamento', 'enviar_correo', 'nota_credito_automatica'];
        $ajustes = array_intersect_key((array) ($datos['ajustes'] ?? []), array_flip($permitidos));
        // Al cambiar de proveedor los identificadores del anterior no sirven.
        $ajustes = $cambio ? $ajustes : array_merge((array) ($config->ajustes ?? []), $ajustes);

        $config->fill([
            'proveedor'    => $datos['proveedor'],
            'credenciales' => $credenciales,
            'ajustes'      => $ajustes,
            'activa'       => (bool) ($datos['activa'] ?? $config->activa ?? false),
            'automatica'   => (bool) ($datos['automatica'] ?? $config->automatica ?? false),
            // La primera vez arranca desde hoy: no se manda a la DIAN la historia entera.
            'emitir_desde' => $datos['emitir_desde'] ?? ($config->emitir_desde?->toDateString() ?? now()->toDateString()),
        ]);

        $faltantes = (new FacturacionElectronica($empresa))->faltantes($config);

        if ($config->activa && array_filter($campos, fn ($c) => empty($credenciales[$c]))) {
            return $this->responder('Para activarla, complete las credenciales del proveedor.', null, 1, 422);
        }
        if ($config->automatica && $faltantes) {
            return $this->responder('Para emitir automáticamente falta: ' . implode(' ', $faltantes), null, 1, 422);
        }

        $config->save();

        return $this->responder('Configuración guardada.', ['faltantes' => $faltantes]);
    }

    /** POST /api/factura-electronica/probar */
    public function probar(Request $request): JsonResponse
    {
        $request->validate(['proveedor' => 'nullable|string', 'credenciales' => 'nullable|array']);

        $s      = $this->servicio();
        $config = $s->config();
        $cual   = $request->input('proveedor') ?: $config?->proveedor;
        $base   = ($config && $config->proveedor === $cual) ? (array) ($config->credenciales ?? []) : [];
        $creds  = array_merge($base, array_filter(array_map(fn ($v) => trim((string) $v), (array) $request->input('credenciales', [])), fn ($v) => $v !== ''));

        try {
            $r = $s->proveedor($config, $creds, $cual)->probar();
        } catch (ErrorDelProveedor $e) {
            $r = ['ok' => false, 'detalle' => $e->getMessage()];
        }

        if ($r['ok'] && $config && $config->proveedor === $cual && !$request->filled('credenciales')) {
            $config->update(['verificada_en' => now()]);
        }

        return $this->responder($r['detalle'], null, $r['ok'] ? 0 : 1);
    }

    /** GET /api/factura-electronica/catalogos */
    public function catalogos(): JsonResponse
    {
        try {
            return $this->responder('OK', $this->servicio()->proveedor()->catalogos());
        } catch (ErrorDelProveedor $e) {
            return $this->responder($e->getMessage(), null, 1);
        }
    }

    /** GET /api/factura-electronica/documentos?estado=&q=&pagina= */
    public function documentos(Request $request): JsonResponse
    {
        $empresa = (int) getSessionCompanyId();

        $q = FacturaElectronica::query()->from('facturas_electronicas as fe')
            ->leftJoin('det_facturations as d', 'd.id', '=', 'fe.det_facturation_id')
            ->leftJoin('user_data as u', fn ($j) => $j->on('u.user_id', '=', 'fe.user_id')->where('u.company_id', $empresa))
            ->where('fe.company_id', $empresa)
            ->when($request->input('estado'), fn ($x, $e) => $x->where('fe.estado', $e))
            ->when(trim((string) $request->input('q')), fn ($x, $t) => $x->where(fn ($y) => $y
                ->where('fe.numero', 'like', "%{$t}%")->orWhere('d.number_facture', 'like', "%{$t}%")
                ->orWhere('u.dni', 'like', "%{$t}%")->orWhere(DB::raw("CONCAT(u.names, ' ', u.lastname)"), 'like', "%{$t}%")));

        $total = (clone $q)->count();
        $filas = $q->orderByDesc('fe.id')->forPage(max(1, (int) $request->input('pagina', 1)), 25)
            ->get(['fe.id', 'fe.tipo', 'fe.estado', 'fe.numero', 'fe.cufe', 'fe.estado_dian', 'fe.pdf_url', 'fe.total', 'fe.impuesto', 'fe.error',
                'fe.intentos', 'fe.externo_id', 'fe.emitida_en', 'fe.created_at', 'fe.origen_id', 'fe.det_facturation_id',
                'd.number_facture', 'd.paid', 'd.anulada_en', 'u.dni',
                // La nota crédito aceptada que anuló esta factura, si la hay.
                DB::raw("(SELECT COALESCE(nc.numero, 'nota crédito') FROM facturas_electronicas nc WHERE nc.origen_id = fe.id AND nc.tipo = 'nota_credito' AND nc.estado = 'emitida' ORDER BY nc.id DESC LIMIT 1) as anulada_con"),
                DB::raw("TRIM(CONCAT(COALESCE(u.names,''), ' ', COALESCE(u.lastname,''))) as cliente")]);

        return $this->responder('OK', ['total' => $total, 'por_pagina' => 25, 'documentos' => $filas]);
    }

    /** GET /api/factura-electronica/por-emitir — pagadas que aún no tienen factura electrónica. */
    public function porEmitir(): JsonResponse
    {
        $config = $this->servicio()->config();

        if (!$config) {
            return $this->responder('OK', ['total' => 0, 'facturas' => []]);
        }

        $q = $this->porEmitirQuery($config);

        return $this->responder('OK', [
            'total'    => (clone $q)->count(),
            'facturas' => $q->orderByDesc('d.paid_at')->limit(100)->get(['d.id', 'd.number_facture', 'd.paid_at', 'u.dni',
                DB::raw('(d.price_total - COALESCE(d.price_discount, 0)) as total'),
                DB::raw("TRIM(CONCAT(COALESCE(u.names,''), ' ', COALESCE(u.lastname,''))) as cliente")]),
        ]);
    }

    private function porEmitirQuery(FacturaElectronicaConfig $config)
    {
        $empresa = (int) getSessionCompanyId();

        return DB::table('det_facturations as d')
            ->join('cab_facturations as c', 'c.id', '=', 'd.cab_id')
            ->leftJoin('user_data as u', fn ($j) => $j->on('u.user_id', '=', 'c.user_id')->where('u.company_id', $empresa))
            ->where('c.company_id', $empresa)->where('d.paid', 1)->whereNull('d.anulada_en')
            ->when(FacturacionElectronica::soloMarcados($config), fn ($x) => $x->where('c.billing_electronic', 1))
            ->whereRaw('DATE(COALESCE(d.paid_at, d.updated_at)) >= ?', [$config->emitir_desde?->toDateString() ?? now()->toDateString()])
            ->whereRaw('(d.price_total - COALESCE(d.price_discount, 0)) > 0')
            ->whereNotExists(fn ($x) => FacturacionElectronica::sinAnular($x));
    }

    /** GET /api/factura-electronica/cliente/{userId} — lo que la DIAN pide del adquiriente. */
    public function clienteFiscal(int $userId): JsonResponse
    {
        $c = DB::table('user_data')->where('company_id', getSessionCompanyId())->where('user_id', $userId)
            ->first(['fiscal_tipo_documento', 'fiscal_dv', 'fiscal_tipo_persona', 'fiscal_municipio', 'estrato', 'barrio', 'ciudad', 'departamento', 'pais', 'prefijo_telefono']);

        if (!$c) {
            return $this->responder('Cliente no encontrado.', null, 1, 404);
        }

        return $this->responder('OK', [
            'tipo_documento' => $c->fiscal_tipo_documento ?: 'CC',
            'dv'             => $c->fiscal_dv,
            'tipo_persona'   => $c->fiscal_tipo_persona ?: (($c->fiscal_tipo_documento ?? '') === 'NIT' ? 'juridica' : 'natural'),
            'municipio'      => $c->fiscal_municipio,
            'estrato'        => $c->estrato,
            'barrio'         => $c->barrio,
            'ciudad'         => $c->ciudad,
            'departamento'   => $c->departamento,
            'pais'           => $c->pais,
            'prefijo_telefono' => $c->prefijo_telefono,
            // Si el cliente no los tiene, lo que la pantalla propone: la ciudad de la empresa.
            'sugeridos'      => \App\Support\DatosDelCliente::porDefecto((int) getSessionCompanyId()),
            // Falta definir el tipo: la pantalla lo resalta en vez de dar «CC» por bueno.
            'tipo_sin_definir' => !$c->fiscal_tipo_documento,
        ]);
    }

    /** GET /api/factura-electronica/cliente-por-defecto — lo que se propone en el alta de un cliente. */
    public function clientePorDefecto(): JsonResponse
    {
        return $this->responder('OK', [
            'sugeridos' => \App\Support\DatosDelCliente::porDefecto((int) getSessionCompanyId()),
            'tipos'     => \App\Support\DatosDelCliente::TIPOS_DE_DOCUMENTO,
        ]);
    }

    /** PUT /api/factura-electronica/cliente/{userId} */
    public function guardarClienteFiscal(int $userId, Request $request): JsonResponse
    {
        $d = $request->validate([
            'tipo_documento' => 'required|in:' . implode(',', array_keys(\App\Support\DatosDelCliente::TIPOS_DE_DOCUMENTO)),
            'dv'             => 'nullable|digits:1|required_if:tipo_documento,NIT',
            'tipo_persona'   => 'required|in:natural,juridica',
        ] + \App\Support\DatosDelCliente::reglas(), ['dv.required_if' => 'Con NIT hace falta el dígito de verificación.']);

        // La pantalla manda todos los campos: lo que llega vacío se guarda vacío.
        $n = DB::table('user_data')->where('company_id', getSessionCompanyId())->where('user_id', $userId)
            ->update(\App\Support\DatosDelCliente::columnas($d + array_fill_keys(array_keys(\App\Support\DatosDelCliente::reglas()), null)) + ['updated_at' => now()]);

        return $n ? $this->responder('Datos fiscales guardados.') : $this->responder('Cliente no encontrado.', null, 1, 404);
    }

    /** POST /api/factura-electronica/emitir  { det_ids: [] } */
    public function emitir(Request $request): JsonResponse
    {
        if (!$this->puedeEmitir()) {
            return $this->responder('Sólo el administrador o el contador pueden emitir facturas electrónicas.', null, 1, 403);
        }

        // De a pocas: cada una espera la respuesta de la DIAN.
        $ids = $request->validate(['det_ids' => 'required|array|min:1|max:10', 'det_ids.*' => 'integer'])['det_ids'];
        $s   = $this->servicio();
        $r   = ['emitidas' => 0, 'fallidas' => [], 'documentos' => []];

        foreach (array_unique($ids) as $detId) {
            try {
                $fe = $s->emitir((int) $detId, (int) getSessionUserId());
                $r['documentos'][] = $fe->only(['id', 'estado', 'numero', 'error', 'det_facturation_id']);
                $fe->estado === FacturaElectronica::EMITIDA ? $r['emitidas']++ : $r['fallidas'][] = $fe->error ?: 'Quedó pendiente de respuesta de la DIAN.';
            } catch (ErrorDelProveedor $e) {
                $r['fallidas'][] = $e->getMessage();
            }
        }

        $mensaje = $r['emitidas'] . ' factura(s) emitida(s)' . ($r['fallidas'] ? ' · ' . count($r['fallidas']) . ' con novedad: ' . implode(' ', array_unique($r['fallidas'])) : '.');

        return $this->responder($mensaje, $r, $r['emitidas'] > 0 || !$r['fallidas'] ? 0 : 1);
    }

    /** POST /api/factura-electronica/documentos/{id}/reintentar */
    public function reintentar(int $id): JsonResponse
    {
        if (!$this->puedeEmitir()) {
            return $this->responder('No tiene permiso para esto.', null, 1, 403);
        }

        $fe = FacturaElectronica::where('company_id', getSessionCompanyId())->findOrFail($id);
        $s  = $this->servicio();

        try {
            $fe = $fe->externo_id ? $s->actualizar($fe)
                : ($fe->tipo === 'factura'
                    ? $s->emitir((int) $fe->det_facturation_id, (int) getSessionUserId())
                    : $s->notaCredito(FacturaElectronica::findOrFail($fe->origen_id), (int) getSessionUserId()));
        } catch (ErrorDelProveedor $e) {
            return $this->responder($e->getMessage(), null, 1);
        }

        return $this->responder(
            $fe->estado === FacturaElectronica::EMITIDA ? 'Aceptada por la DIAN: ' . $fe->numero . '.' : ($fe->error ?: 'Sigue pendiente de respuesta.'),
            $fe->only(['id', 'estado', 'numero', 'error']),
            $fe->estado === FacturaElectronica::EMITIDA ? 0 : 1
        );
    }

    /** POST /api/factura-electronica/documentos/{id}/nota-credito */
    public function notaCredito(int $id): JsonResponse
    {
        if (!$this->puedeEmitir()) {
            return $this->responder('No tiene permiso para esto.', null, 1, 403);
        }

        $fe = FacturaElectronica::where('company_id', getSessionCompanyId())->findOrFail($id);

        try {
            $nc = $this->servicio()->notaCredito($fe, (int) getSessionUserId());
        } catch (ErrorDelProveedor $e) {
            return $this->responder($e->getMessage(), null, 1);
        }

        return $this->responder(
            $nc->estado === FacturaElectronica::EMITIDA ? 'Nota crédito ' . $nc->numero . ' aceptada por la DIAN.' : ($nc->error ?: 'La nota crédito quedó pendiente de respuesta.'),
            $nc->only(['id', 'estado', 'numero', 'error']),
            $nc->estado === FacturaElectronica::EMITIDA ? 0 : 1
        );
    }

    /** GET /api/factura-electronica/documentos/{id}/pdf */
    public function pdf(int $id)
    {
        $fe = FacturaElectronica::where('company_id', getSessionCompanyId())->findOrFail($id);

        try {
            $pdf = $this->servicio()->pdf($fe);
        } catch (ErrorDelProveedor $e) {
            return $this->responder($e->getMessage(), null, 1, 502);
        }

        if (!$pdf) {
            return $this->responder('El proveedor no entregó el PDF de este documento.', null, 1, 404);
        }

        return response($pdf, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($fe->numero ?: 'factura-' . $fe->id)) . '.pdf"',
        ]);
    }
}

<?php

namespace App\Services\FacturaElectronica;

use App\Models\FacturaElectronica;
use App\Models\FacturaElectronicaConfig;
use App\Services\FacturaElectronica\Proveedores\Alegra;
use App\Services\FacturaElectronica\Proveedores\ErrorDelProveedor;
use App\Services\FacturaElectronica\Proveedores\ProveedorDeFacturaElectronica;
use App\Services\FacturaElectronica\Proveedores\Siigo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * La facturación electrónica de una empresa ante la DIAN.
 *
 * La factura de la plataforma (NT123) es la cuenta de cobro. La factura
 * electrónica se emite cuando esa cuenta queda PAGADA, por lo efectivamente
 * cobrado: así no se le reporta a la DIAN un servicio que después se corta o
 * no se paga, y casi nunca hace falta una nota crédito.
 *
 * Reglas que protegen de un error caro:
 *  - Una cuenta de cobro tiene, como mucho, una factura electrónica viva.
 *  - Si el proveedor ya creó el documento, nunca se vuelve a crear: se consulta.
 *  - Se valida antes de enviar. Lo que rechaza la DIAN no se reintenta solo.
 *  - Una factura aceptada no se borra: se corrige con nota crédito.
 */
class FacturacionElectronica
{
    public const PROVEEDORES = [
        'siigo'  => ['nombre' => 'Siigo Nube', 'clase' => Siigo::class],
        'alegra' => ['nombre' => 'Alegra',     'clase' => Alegra::class],
    ];

    /** Lo que cada proveedor exige tener elegido antes de emitir. */
    private const AJUSTES_OBLIGATORIOS = [
        'siigo'  => ['numeracion_id' => 'el comprobante de factura electrónica', 'vendedor_id' => 'el vendedor', 'forma_pago_id' => 'la forma de pago', 'producto_id' => 'el producto o servicio'],
        'alegra' => ['numeracion_id' => 'la numeración electrónica', 'producto_id' => 'el ítem o servicio'],
    ];

    public function __construct(private int $companyId) {}

    /**
     * ¿A quién se le factura ante la DIAN? Por defecto, sólo a los clientes que
     * tienen prendida la marca «factura electrónica» (cab_facturations.billing_electronic),
     * que es la misma que ya decide por dónde pagan. Con el alcance en «todos»,
     * a cualquier cliente que pague.
     */
    public static function soloMarcados(?FacturaElectronicaConfig $config): bool
    {
        return ($config?->ajuste('alcance', 'marcados') ?? 'marcados') !== 'todos';
    }

    public function config(): ?FacturaElectronicaConfig
    {
        return FacturaElectronicaConfig::where('company_id', $this->companyId)->first();
    }

    public function proveedor(?FacturaElectronicaConfig $config = null, ?array $credenciales = null, ?string $cual = null): ProveedorDeFacturaElectronica
    {
        $config ??= $this->config();
        $cual   ??= $config?->proveedor;
        $clase    = self::PROVEEDORES[$cual]['clase'] ?? null;

        if (!$clase) {
            throw new ErrorDelProveedor('La empresa no tiene un proveedor de facturación electrónica configurado.');
        }

        return new $clase($credenciales ?? (array) ($config?->credenciales ?? []), $this->companyId);
    }

    /** Lo que falta elegir en la configuración para poder emitir. */
    public function faltantes(?FacturaElectronicaConfig $config = null): array
    {
        $config ??= $this->config();

        if (!$config) {
            return ['Conecte un proveedor de facturación electrónica.'];
        }

        $faltan = [];

        foreach (self::AJUSTES_OBLIGATORIOS[$config->proveedor] ?? [] as $clave => $que) {
            if (!$config->ajuste($clave)) {
                $faltan[] = 'Falta elegir ' . $que . '.';
            }
        }

        if (!preg_match('/^\d{5}$/', (string) $config->ajuste('municipio'))) {
            $faltan[] = 'Falta el código DANE del municipio de la empresa (5 dígitos).';
        }

        if ($config->proveedor === 'alegra' && (!$config->ajuste('ciudad') || !$config->ajuste('departamento'))) {
            $faltan[] = 'Faltan la ciudad y el departamento, como los nombra Alegra.';
        }

        if ($config->ajuste('iva') === 'incluido' && !$config->ajuste('impuesto_id')) {
            $faltan[] = 'Eligió cobrar IVA pero falta escoger el impuesto.';
        }

        return $faltan;
    }

    // ── Armar el documento ───────────────────────────────────────────────────

    /**
     * La cuenta de cobro con su cliente, lista para traducir.
     *
     * @return array{documento: ?array, problemas: list<string>}
     */
    public function armar(int $detId, FacturaElectronicaConfig $config): array
    {
        $f = DB::table('det_facturations as d')
            ->join('cab_facturations as c', 'c.id', '=', 'd.cab_id')
            ->leftJoin('user_data as u', function ($j) {
                $j->on('u.user_id', '=', 'c.user_id')->on('u.company_id', '=', 'c.company_id');
            })
            ->leftJoin('internet_plans as p', 'p.id', '=', 'u.internet_plans_id')
            ->where('d.id', $detId)->where('c.company_id', $this->companyId)
            ->first(['d.id', 'd.number_facture', 'd.concepto', 'd.price_total', 'd.price_discount', 'd.paid', 'd.anulada_en', 'd.date_facturation', 'c.billing_electronic',
                'c.user_id', 'u.names', 'u.lastname', 'u.dni', 'u.address', 'u.phone', 'u.email',
                'u.fiscal_tipo_documento', 'u.fiscal_dv', 'u.fiscal_tipo_persona', 'u.fiscal_municipio', 'u.estrato', 'p.plan_name']);

        if (!$f) {
            return ['documento' => null, 'problemas' => ['No encontramos esa factura.']];
        }

        $problemas = [];
        $total     = round(max(0, (float) $f->price_total - (float) ($f->price_discount ?? 0)), 2);
        $numero    = preg_replace('/[^0-9A-Za-z]/', '', (string) $f->dni);
        $tipoDoc   = strtoupper((string) ($f->fiscal_tipo_documento ?: 'CC'));
        $persona   = $f->fiscal_tipo_persona ?: ($tipoDoc === 'NIT' ? 'juridica' : 'natural');
        $nombres   = trim((string) $f->names);
        $apellidos = trim((string) $f->lastname);
        $telefono  = substr(preg_replace('/\D/', '', (string) $f->phone), -10);
        $municipio = preg_match('/^\d{5}$/', (string) $f->fiscal_municipio) ? $f->fiscal_municipio : (string) $config->ajuste('municipio');
        $email     = filter_var(trim((string) $f->email), FILTER_VALIDATE_EMAIL) ?: null;

        if ($f->anulada_en) {
            $problemas[] = 'La factura está anulada.';
        }
        if (self::soloMarcados($config) && !(int) $f->billing_electronic) {
            $problemas[] = 'El cliente no tiene activada la factura electrónica. Actívela en su fila del listado de clientes.';
        }
        if ($total <= 0) {
            $problemas[] = 'La factura no tiene valor que facturar.';
        }
        if ($numero === '') {
            $problemas[] = 'El cliente no tiene número de documento.';
        }
        if ($nombres === '') {
            $problemas[] = 'El cliente no tiene nombre.';
        }
        if (trim((string) $f->address) === '') {
            $problemas[] = 'El cliente no tiene dirección.';
        }
        if (strlen($telefono) < 7) {
            $problemas[] = 'El cliente no tiene un teléfono válido.';
        }
        if ($tipoDoc === 'NIT' && ($f->fiscal_dv === null || $f->fiscal_dv === '')) {
            $problemas[] = 'El cliente tiene NIT pero falta el dígito de verificación.';
        }

        // IVA: el valor cobrado ya lo trae adentro, o el servicio no lo causa.
        // El internet residencial de estratos 1, 2 y 3 está excluido.
        $excluido   = $config->ajuste('iva', 'excluido') !== 'incluido'
            || ($f->estrato !== null && in_array((int) $f->estrato, array_map('intval', (array) $config->ajuste('estratos_excluidos', [])), true));
        $porcentaje = $excluido ? 0.0 : (float) $config->ajuste('impuesto_porcentaje', 19);
        $base       = $porcentaje > 0 ? round($total / (1 + $porcentaje / 100), 2) : $total;
        // El proveedor recalcula el IVA sobre la base redondeada: se hace igual aquí,
        // o el total y el pago no cuadran por un centavo y rechaza el documento.
        $impuesto   = round($base * $porcentaje / 100, 2);
        $total      = round($base + $impuesto, 2);

        $concepto = trim((string) $f->concepto) ?: ('Servicio de internet' . ($f->plan_name ? ' ' . $f->plan_name : ''));

        return [
            'problemas' => $problemas,
            'documento' => [
                'det_id'        => (int) $f->id,
                'user_id'       => (int) $f->user_id,
                // La DIAN no admite fechas anteriores: la factura lleva la fecha en que se emite.
                'fecha'         => now()->toDateString(),
                'referencia'    => (string) $f->number_facture,
                'concepto'      => $concepto,
                'observaciones' => 'Cuenta de cobro ' . $f->number_facture . '.',
                'total'         => $total,
                'base'          => $base,
                'impuesto'      => $impuesto,
                'porcentaje'    => $porcentaje,
                'cliente'       => [
                    'tipo_documento' => $tipoDoc,
                    'numero'         => $numero,
                    'dv'             => $f->fiscal_dv,
                    'persona'        => $persona,
                    'nombres'        => $nombres,
                    'apellidos'      => $apellidos,
                    'razon_social'   => trim($nombres . ' ' . $apellidos),
                    'direccion'      => trim((string) $f->address),
                    'municipio'      => $municipio,
                    'ciudad'         => (string) $config->ajuste('ciudad'),
                    'departamento'   => (string) $config->ajuste('departamento'),
                    'telefono'       => $telefono,
                    'email'          => $email,
                ],
            ],
        ];
    }

    // ── Emitir ───────────────────────────────────────────────────────────────

    /** Emite la factura electrónica de una cuenta de cobro pagada. */
    public function emitir(int $detId, ?int $usuarioId = null): FacturaElectronica
    {
        $config = $this->config();

        if (!$config || !$config->activa) {
            throw new ErrorDelProveedor('La facturación electrónica no está activa para esta empresa.');
        }

        // Dos procesos sobre la misma cuenta de cobro (el barrido y un clic) no emiten dos facturas.
        $candado = Cache::lock("fe:det:{$detId}", 180);

        if (!$candado->get()) {
            throw new ErrorDelProveedor('Esa factura se está emitiendo en este momento.', true);
        }

        try {
            $fe = FacturaElectronica::where('company_id', $this->companyId)->where('det_facturation_id', $detId)
                ->where('tipo', 'factura')->orderByDesc('id')->first();

            // Anulada con nota crédito aceptada: ya no cuenta. Si el cliente volvió a
            // pagar, ese pago necesita su propia factura.
            if ($fe && $fe->estado === FacturaElectronica::EMITIDA && self::anulada($fe)) {
                $fe = null;
            }

            if ($fe && $fe->estado === FacturaElectronica::EMITIDA) {
                return $fe;
            }

            // El proveedor ya tiene el documento: crear otro sería duplicarlo ante la DIAN.
            if ($fe && $fe->externo_id) {
                return $this->actualizar($fe);
            }

            $armado = $this->armar($detId, $config);
            $doc    = $armado['documento'];

            if ($doc && !DB::table('det_facturations')->where('id', $detId)->value('paid')) {
                $armado['problemas'][] = 'La factura todavía no está pagada.';
            }

            $problemas = array_merge($this->faltantes($config), $armado['problemas']);

            $fe ??= new FacturaElectronica([
                'company_id' => $this->companyId, 'det_facturation_id' => $detId, 'tipo' => 'factura', 'creada_por' => $usuarioId,
            ]);
            $fe->fill([
                'proveedor' => $config->proveedor,
                'user_id'   => $doc['user_id'] ?? null,
                'base'      => $doc['base'] ?? 0, 'impuesto' => $doc['impuesto'] ?? 0, 'total' => $doc['total'] ?? 0,
            ]);

            if ($problemas) {
                $fe->fill(['estado' => FacturaElectronica::RECHAZADA, 'error' => implode(' ', array_unique($problemas))])->save();

                return $fe;
            }

            $fe->estado = FacturaElectronica::PENDIENTE;
            $fe->save();

            $doc['idempotencia'] = 'fe' . $fe->id . 'i' . ((int) $fe->intentos + 1);

            return $this->enviar($fe, fn (ProveedorDeFacturaElectronica $p) => $p->emitir($doc, (array) $config->ajustes), $doc, $config);
        } finally {
            $candado->release();
        }
    }

    /** ¿La factura ya fue anulada ante la DIAN con una nota crédito aceptada? */
    public static function anulada(FacturaElectronica $factura): bool
    {
        return FacturaElectronica::where('origen_id', $factura->id)->where('tipo', 'nota_credito')
            ->where('estado', FacturaElectronica::EMITIDA)->exists();
    }

    /** La factura electrónica viva (aceptada y sin anular) de una cuenta de cobro. */
    public function viva(int $detId): ?FacturaElectronica
    {
        $fe = FacturaElectronica::where('company_id', $this->companyId)->where('det_facturation_id', $detId)
            ->where('tipo', 'factura')->where('estado', FacturaElectronica::EMITIDA)->orderByDesc('id')->first();

        return $fe && !self::anulada($fe) ? $fe : null;
    }

    /**
     * Se revirtió el pago o se anuló la cuenta de cobro. Si ya estaba facturada
     * ante la DIAN, esa factura hay que anularla con nota crédito: se emite en
     * el momento y se devuelve qué pasó, para decírselo a quien hizo el cambio.
     * Nunca lanza: que falle la nota crédito no puede impedir el reverso.
     *
     * @return ?string  null si la cuenta de cobro no tenía factura electrónica
     */
    public static function alDeshacerCobro(int $companyId, int $detId, ?int $usuarioId = null): ?string
    {
        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('facturas_electronicas')) {
                return null;
            }

            $s  = new self($companyId);
            $fe = $s->viva($detId);

            if (!$fe) {
                return null;
            }

            // Quitar un abono de más no deshace el cobro: sólo cuenta si quedó sin pagar o anulada.
            $det = DB::table('det_facturations')->where('id', $detId)->first(['paid', 'anulada_en']);

            if ($det && (int) $det->paid === 1 && !$det->anulada_en) {
                return null;
            }

            $config = $s->config();

            if (!$config || !$config->activa || !$config->ajuste('nota_credito_automatica', true)) {
                return 'OJO: esta cuenta ya tiene la factura electrónica ' . $fe->numero . ' ante la DIAN. Emita la nota crédito desde Factura electrónica.';
            }

            $nc = $s->notaCredito($fe, $usuarioId);

            return $nc->estado === FacturaElectronica::EMITIDA
                ? 'Se emitió la nota crédito ' . $nc->numero . ', que anula la factura electrónica ' . $fe->numero . ' ante la DIAN.'
                : 'OJO: la factura electrónica ' . $fe->numero . ' sigue vigente ante la DIAN; la nota crédito no salió (' . ($nc->error ?: 'sin respuesta') . '). Revísela en Factura electrónica.';
        } catch (\Throwable $e) {
            Log::warning('[Factura electrónica] No se pudo anular al deshacer el cobro', ['det' => $detId, 'error' => $e->getMessage()]);

            return 'OJO: esta cuenta tiene factura electrónica ante la DIAN y la nota crédito no se pudo emitir. Revísela en Factura electrónica.';
        }
    }

    /** Nota crédito que anula una factura electrónica ya aceptada. */
    public function notaCredito(FacturaElectronica $factura, ?int $usuarioId = null): FacturaElectronica
    {
        $config = $this->config();

        if (!$config || !$config->activa) {
            throw new ErrorDelProveedor('La facturación electrónica no está activa para esta empresa.');
        }
        if ($factura->tipo !== 'factura' || $factura->estado !== FacturaElectronica::EMITIDA) {
            throw new ErrorDelProveedor('Sólo se puede hacer nota crédito de una factura electrónica aceptada.');
        }

        $candado = Cache::lock("fe:nc:{$factura->id}", 180);

        if (!$candado->get()) {
            throw new ErrorDelProveedor('La nota crédito se está emitiendo en este momento.', true);
        }

        try {
            $nc = FacturaElectronica::where('origen_id', $factura->id)->where('tipo', 'nota_credito')->orderByDesc('id')->first();

            if ($nc && $nc->estado === FacturaElectronica::EMITIDA) {
                return $nc;
            }
            if ($nc && $nc->externo_id) {
                return $this->actualizar($nc);
            }

            $armado    = $this->armar((int) $factura->det_facturation_id, $config);
            $doc       = $armado['documento'];
            $problemas = $this->faltantes($config);

            if ($config->proveedor === 'siigo' && !$config->ajuste('numeracion_nc_id')) {
                $problemas[] = 'Falta elegir el comprobante de nota crédito.';
            }
            if (!$doc) {
                $problemas[] = 'No encontramos la factura original.';
            }

            $nc ??= new FacturaElectronica([
                'company_id' => $this->companyId, 'det_facturation_id' => $factura->det_facturation_id, 'tipo' => 'nota_credito',
                'origen_id' => $factura->id, 'creada_por' => $usuarioId,
            ]);
            // La nota crédito devuelve exactamente lo que se facturó, no lo que hoy diga la cuenta de cobro.
            $nc->fill(['proveedor' => $config->proveedor, 'user_id' => $factura->user_id,
                'base' => $factura->base, 'impuesto' => $factura->impuesto, 'total' => $factura->total]);

            if ($problemas) {
                $nc->fill(['estado' => FacturaElectronica::RECHAZADA, 'error' => implode(' ', array_unique($problemas))])->save();

                return $nc;
            }

            $doc = array_merge($doc, [
                'total' => (float) $factura->total, 'base' => (float) $factura->base, 'impuesto' => (float) $factura->impuesto,
                'observaciones' => 'Anula la factura electrónica ' . $factura->numero . '.',
            ]);

            $nc->estado = FacturaElectronica::PENDIENTE;
            $nc->save();

            $doc['idempotencia'] = 'nc' . $nc->id . 'i' . ((int) $nc->intentos + 1);
            $original = ['externo_id' => $factura->externo_id, 'numero' => $factura->numero, 'cufe' => $factura->cufe];

            return $this->enviar($nc, fn (ProveedorDeFacturaElectronica $p) => $p->notaCredito($doc, $original, (array) $config->ajustes), $doc, $config);
        } finally {
            $candado->release();
        }
    }

    /** Vuelve a preguntarle al proveedor por un documento que ya creó. */
    public function actualizar(FacturaElectronica $fe): FacturaElectronica
    {
        if (!$fe->externo_id) {
            return $fe;
        }

        try {
            $r = $this->proveedor()->consultar($fe->externo_id, $fe->tipo);
            $this->guardar($fe, $r);
        } catch (ErrorDelProveedor $e) {
            $fe->fill(['error' => $e->getMessage()])->save();
        }

        return $fe;
    }

    /** El PDF, en binario. */
    public function pdf(FacturaElectronica $fe): ?string
    {
        return $fe->externo_id ? $this->proveedor()->pdf($fe->externo_id, $fe->tipo) : null;
    }

    private function enviar(FacturaElectronica $fe, callable $llamada, array $doc, FacturaElectronicaConfig $config): FacturaElectronica
    {
        $fe->intentos  = (int) $fe->intentos + 1;
        $fe->solicitud = $doc;

        try {
            $this->guardar($fe, $llamada($this->proveedor($config)));
        } catch (ErrorDelProveedor $e) {
            // Temporal (caída, límite de peticiones): queda en «error» y el barrido lo reintenta.
            // Lo demás es un dato por corregir: queda «rechazada» hasta que alguien lo mire.
            $fe->fill([
                'estado'    => $e->temporal ? FacturaElectronica::ERROR : FacturaElectronica::RECHAZADA,
                'error'     => $e->getMessage(),
                'respuesta' => $e->respuesta,
            ])->save();

            Log::warning('[Factura electrónica] No se emitió', [
                'empresa' => $this->companyId, 'fe' => $fe->id, 'det' => $fe->det_facturation_id, 'error' => $e->getMessage(),
            ]);
        }

        return $fe;
    }

    private function guardar(FacturaElectronica $fe, array $r): void
    {
        $fe->fill([
            'estado'      => $r['estado'],
            'externo_id'  => $r['externo_id'] ?? $fe->externo_id,
            'numero'      => $r['numero'] ?? $fe->numero,
            'cufe'        => $r['cufe'] ?? $fe->cufe,
            'estado_dian' => $r['estado_dian'] ?? $fe->estado_dian,
            'pdf_url'     => $r['pdf_url'] ?? $fe->pdf_url,
            'qr'          => $r['qr'] ?? $fe->qr,
            'error'       => $r['error'] ?? null,
            'respuesta'   => $r['respuesta'] ?? null,
            'emitida_en'  => $r['estado'] === FacturaElectronica::EMITIDA ? ($fe->emitida_en ?? now()) : $fe->emitida_en,
        ])->save();
    }

    /**
     * Condición «esta cuenta de cobro ya tiene un documento que cuenta»: cualquier
     * factura electrónica suya que no esté anulada con una nota crédito aceptada.
     * La usan el barrido y la lista «Por emitir».
     */
    public static function sinAnular($q, string $columnaDet = 'd.id')
    {
        return $q->from('facturas_electronicas as fe')->whereColumn('fe.det_facturation_id', $columnaDet)->where('fe.tipo', 'factura')
            ->whereNotExists(fn ($n) => $n->from('facturas_electronicas as nc')->whereColumn('nc.origen_id', 'fe.id')
                ->where('nc.tipo', 'nota_credito')->where('nc.estado', FacturaElectronica::EMITIDA));
    }

    // ── Barrido automático ───────────────────────────────────────────────────

    /**
     * Lo que se hace solo, por tandas cortas para respetar los límites del proveedor:
     * emitir lo pagado, reintentar lo que falló por una caída, preguntar por lo que
     * quedó sin respuesta y anular con nota crédito lo que dejó de estar pagado.
     *
     * @return array{emitidas:int, rechazadas:int, errores:int, notas:int}
     */
    public function barrer(int $tope = 15): array
    {
        $r      = ['emitidas' => 0, 'rechazadas' => 0, 'errores' => 0, 'notas' => 0];
        $config = $this->config();

        if (!$config || !$config->activa || $this->faltantes($config)) {
            return $r;
        }

        $contar = function (FacturaElectronica $fe) use (&$r) {
            match ($fe->estado) {
                FacturaElectronica::EMITIDA   => $fe->tipo === 'nota_credito' ? $r['notas']++ : $r['emitidas']++,
                FacturaElectronica::RECHAZADA => $r['rechazadas']++,
                FacturaElectronica::ERROR     => $r['errores']++,
                default                       => null,
            };
        };

        // 1. Sin respuesta de la DIAN, o caídas del proveedor (hasta seis intentos).
        $pendientes = FacturaElectronica::where('company_id', $this->companyId)
            ->where(fn ($q) => $q->where('estado', FacturaElectronica::ERROR)->where('intentos', '<', 6)
                ->orWhere(fn ($q2) => $q2->where('estado', FacturaElectronica::PENDIENTE)->whereNotNull('externo_id')))
            ->where('updated_at', '<', now()->subMinutes(10))
            ->orderBy('id')->limit(5)->get();

        foreach ($pendientes as $fe) {
            try {
                // Una nota crédito que nunca llegó al proveedor y cuya cuenta de cobro volvió a
                // quedar pagada ya no se insiste: anularía la factura de un pago que sí está en firme.
                if ($fe->tipo === 'nota_credito' && !$fe->externo_id && DB::table('det_facturations')
                    ->where('id', $fe->det_facturation_id)->where('paid', 1)->whereNull('anulada_en')->exists()) {
                    continue;
                }

                $fe = $fe->externo_id ? $this->actualizar($fe)
                    : ($fe->tipo === 'factura' ? $this->emitir((int) $fe->det_facturation_id) : $this->notaCredito(FacturaElectronica::find($fe->origen_id)));
                $contar($fe);
            } catch (\Throwable $e) {
                Log::warning('[Factura electrónica] Reintento fallido', ['fe' => $fe->id, 'error' => $e->getMessage()]);
            }
        }

        // 2. Cuentas de cobro pagadas que todavía no tienen factura electrónica (sólo con la emisión automática).
        $desde = $config->emitir_desde?->toDateString() ?? now()->toDateString();

        $porEmitir = !$config->automatica ? collect() : DB::table('det_facturations as d')
            ->join('cab_facturations as c', 'c.id', '=', 'd.cab_id')
            ->where('c.company_id', $this->companyId)
            ->where('d.paid', 1)->whereNull('d.anulada_en')
            ->when(self::soloMarcados($config), fn ($q) => $q->where('c.billing_electronic', 1))
            ->whereRaw('DATE(COALESCE(d.paid_at, d.updated_at)) >= ?', [$desde])
            ->whereRaw('(d.price_total - COALESCE(d.price_discount, 0)) > 0')
            ->whereNotExists(fn ($q) => self::sinAnular($q))
            ->orderBy('d.paid_at')->limit($tope)->pluck('d.id');

        foreach ($porEmitir as $detId) {
            try {
                $contar($this->emitir((int) $detId));
            } catch (\Throwable $e) {
                $r['errores']++;
                Log::warning('[Factura electrónica] Falló el barrido', ['det' => $detId, 'error' => $e->getMessage()]);
            }
        }

        // 3. Facturas electrónicas cuya cuenta de cobro dejó de estar pagada (pago revertido o anulada).
        //    Va aparte de la emisión automática: cubre los reversos que no pasan por el panel (lotes, pasarela).
        if ($config->ajuste('nota_credito_automatica', true)) {
            $porAnular = FacturaElectronica::query()->from('facturas_electronicas as fe')
                ->join('det_facturations as d', 'd.id', '=', 'fe.det_facturation_id')
                ->where('fe.company_id', $this->companyId)->where('fe.tipo', 'factura')->where('fe.estado', FacturaElectronica::EMITIDA)
                ->where(fn ($q) => $q->where('d.paid', 0)->orWhereNotNull('d.anulada_en'))
                ->whereNotExists(fn ($q) => $q->from('facturas_electronicas as nc')->whereColumn('nc.origen_id', 'fe.id')->where('nc.tipo', 'nota_credito'))
                // Unos minutos de margen: el reverso desde el panel ya emite la suya en el momento.
                ->where(fn ($q) => $q->whereNull('d.updated_at')->orWhere('d.updated_at', '<', now()->subMinutes(3)))
                ->limit(5)->get(['fe.*']);

            foreach ($porAnular as $fe) {
                try {
                    if ($this->notaCredito($fe)->estado === FacturaElectronica::EMITIDA) {
                        $r['notas']++;
                    }
                } catch (\Throwable $e) {
                    Log::warning('[Factura electrónica] Falló la nota crédito automática', ['fe' => $fe->id, 'error' => $e->getMessage()]);
                }
            }
        }

        return $r;
    }
}

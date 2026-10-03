<?php

namespace App\Services\Alegra;

use App\Services\FacturaElectronica\FacturacionElectronica;
use App\Services\FacturaElectronica\Proveedores\Alegra;
use App\Services\FacturaElectronica\Proveedores\ErrorDelProveedor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Trae lo que la empresa tiene en Alegra y lo cruza con Netvula.
 *
 * Sólo LEE de Alegra. Las facturas, los pagos y los contactos se copian a tablas propias
 * (alegra_*) porque Alegra entrega 30 por consulta y no suma ni cruza: con la copia se puede
 * decir cuánto hay por cobrar, qué factura de allá corresponde a qué cuenta de cobro de acá y
 * dónde no coinciden —pagada en Netvula y abierta en Alegra, que es el caso de casi todas—.
 *
 * El cruce: el cliente se reconoce por su documento; la cuenta de cobro, por ser del mismo
 * cliente, con la fecha más cercana a la de la factura (hasta seis días) y, si hay varias, el
 * mismo valor.
 */
class EspejoDeAlegra
{
    /**
     * Alegra corta por exceso de consultas bastante antes del límite que anuncia: con una
     * cada 350 ms devolvió «Too many requests» a la segunda página. A poco más de un segundo
     * pasa sin tropiezos, y una corrida completa (unas 65 consultas) tarda minuto y medio.
     */
    private const PAUSA_US = 1_100_000;
    private const POR_PAGINA = 30;
    private const DIAS_DE_HOLGURA = 6;

    public function __construct(private int $companyId) {}

    // ── Conexión ─────────────────────────────────────────────────────────────

    /** ¿La empresa tiene Alegra conectado (con credenciales cargadas)? */
    public function conectado(): bool
    {
        $config = (new FacturacionElectronica($this->companyId))->config();

        return $config && $config->proveedor === 'alegra' && !empty($config->credenciales['token']);
    }

    private function alegra(): Alegra
    {
        if (!$this->conectado()) {
            throw new ErrorDelProveedor('Alegra no está conectado: cargue el correo y el token en Factura electrónica → Conexión.');
        }

        /** @var Alegra $a */
        $a = (new FacturacionElectronica($this->companyId))->proveedor();

        return $a;
    }

    // ── Estado de la sincronización ──────────────────────────────────────────

    private function claveEstado(): string
    {
        return "alegra:sync:{$this->companyId}";
    }

    /** @return array<string,mixed> */
    public function estado(): array
    {
        $e = Cache::get($this->claveEstado());

        // Una corrida que quedó colgada (se cayó el proceso) no bloquea para siempre.
        if (is_array($e) && ($e['estado'] ?? '') === 'en_curso' && now()->diffInMinutes($e['latido'] ?? $e['inicio'] ?? now()) > 5) {
            $e['estado'] = 'error';
            $e['error']  = 'La sincronización se interrumpió. Vuelva a intentarlo.';
        }

        return is_array($e) ? $e : ['estado' => 'nunca'];
    }

    private function anotar(array $cambios): void
    {
        Cache::forever($this->claveEstado(), array_merge($this->estado(), $cambios, ['latido' => now()->toIso8601String()]));
    }

    // ── Sincronizar ──────────────────────────────────────────────────────────

    /**
     * Copia facturas, pagos y contactos. Sólo consultas de lectura.
     *
     * @return array<string,mixed>  el estado final
     */
    public function sincronizar(): array
    {
        $candado = Cache::lock("alegra:sync:candado:{$this->companyId}", 600);

        if (!$candado->get()) {
            return $this->estado();
        }

        $inicio = now();
        $this->anotar(['estado' => 'en_curso', 'paso' => 'Facturas', 'hechos' => 0, 'total' => null, 'inicio' => $inicio->toIso8601String(), 'fin' => null, 'error' => null]);

        try {
            $alegra = $this->alegra();

            $f = $this->recorrer($alegra, 'invoices', 'Facturas', fn (array $x) => $this->guardarFactura($x, $inicio));
            $p = $this->recorrer($alegra, 'payments', 'Pagos', fn (array $x) => $this->guardarPago($x, $inicio));
            $c = $this->recorrer($alegra, 'contacts', 'Contactos', fn (array $x) => $this->guardarContacto($x, $inicio));
            $n = $this->recorrer($alegra, 'recurring-invoices', 'Facturas recurrentes', fn (array $x) => $this->guardarRecurrente($x, $inicio), false);

            // Lo que ya no está en Alegra (lo borraron allá) se quita de la copia. Sólo si la
            // corrida llegó completa: con una a medias se borraría lo que no se alcanzó a leer.
            foreach (['alegra_facturas' => $f, 'alegra_pagos' => $p, 'alegra_contactos' => $c, 'alegra_recurrentes' => $n] as $tabla => $completa) {
                if ($completa) {
                    DB::table($tabla)->where('company_id', $this->companyId)->where('sincronizada_en', '<', $inicio)->delete();
                }
            }

            $this->anotar(['paso' => 'Cruzando con Netvula']);
            $this->emparejar();

            $this->anotar([
                'estado' => 'listo', 'paso' => null, 'fin' => now()->toIso8601String(),
                'facturas'  => DB::table('alegra_facturas')->where('company_id', $this->companyId)->count(),
                'pagos'     => DB::table('alegra_pagos')->where('company_id', $this->companyId)->count(),
                'contactos' => DB::table('alegra_contactos')->where('company_id', $this->companyId)->count(),
                'recurrentes' => DB::table('alegra_recurrentes')->where('company_id', $this->companyId)->count(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[Alegra] Falló la sincronización', ['empresa' => $this->companyId, 'error' => $e->getMessage()]);
            $this->anotar(['estado' => 'error', 'fin' => now()->toIso8601String(), 'error' => mb_substr($e->getMessage(), 0, 300)]);
        } finally {
            $candado->release();
        }

        return $this->estado();
    }

    /**
     * Recorre una lista de Alegra de 30 en 30. Devuelve si se leyó completa.
     *
     * @param callable(array):void $guardar
     */
    private function recorrer(Alegra $alegra, string $ruta, string $paso, callable $guardar, bool $ordenar = true): bool
    {
        // Ordenado por id la paginación no se salta ni repite filas si entra una nueva a mitad
        // de la corrida. Las recurrentes no dejan ordenar («El campo de ordenación no es válido»).
        $orden = $ordenar ? ['order_field' => 'id', 'order_direction' => 'ASC'] : [];

        $leidos = 0;
        $total  = null;

        for ($inicio = 0; $inicio < 200_000; $inicio += self::POR_PAGINA) {
            $r = $this->leerConPaciencia($alegra, $ruta, ['start' => $inicio, 'limit' => self::POR_PAGINA, 'metadata' => 'true'] + $orden);

            $total ??= isset($r['metadata']['total']) ? (int) $r['metadata']['total'] : null;
            $filas   = array_values(array_filter((array) ($r['data'] ?? $r), 'is_array'));

            foreach ($filas as $fila) {
                $guardar($fila);
            }

            $leidos += count($filas);
            $this->anotar(['paso' => $paso, 'hechos' => $leidos, 'total' => $total]);

            if (count($filas) < self::POR_PAGINA) {
                break;
            }

            usleep(self::PAUSA_US);
        }

        return $total === null || $leidos >= $total;
    }

    /** Si Alegra pide esperar («Too many requests»), se espera y se insiste: hasta seis veces. */
    private function leerConPaciencia(Alegra $alegra, string $ruta, array $filtros): array
    {
        for ($intento = 1; ; $intento++) {
            try {
                return $alegra->leer($ruta, $filtros);
            } catch (ErrorDelProveedor $e) {
                if (!$e->temporal || $intento >= 6) {
                    throw $e;
                }

                $this->anotar(['paso' => 'Alegra pidió esperar un momento…']);
                sleep(min(60, 20 * $intento));
            }
        }
    }

    private static function documento(mixed $v): ?string
    {
        if (is_array($v)) {
            $v = $v['number'] ?? null;
        }

        $d = preg_replace('/[^0-9A-Za-z]/', '', (string) $v);

        return $d === '' ? null : mb_substr($d, 0, 30);
    }

    private function guardarFactura(array $x, \DateTimeInterface $ahora): void
    {
        DB::table('alegra_facturas')->updateOrInsert(
            ['company_id' => $this->companyId, 'alegra_id' => (string) $x['id']],
            [
                'numero'   => mb_substr((string) ($x['numberTemplate']['fullNumber'] ?? $x['numberTemplate']['number'] ?? ''), 0, 40) ?: null,
                'fecha'    => $x['date'] ?? null,
                'vence'    => $x['dueDate'] ?? null,
                'estado'   => $x['status'] ?? null,
                'cliente_alegra_id'      => isset($x['client']['id']) ? (string) $x['client']['id'] : null,
                'cliente_nombre'         => mb_substr((string) ($x['client']['name'] ?? ''), 0, 190) ?: null,
                'cliente_identificacion' => self::documento($x['client']['identification'] ?? null),
                'total'    => (float) ($x['total'] ?? 0),
                'pagado'   => (float) ($x['totalPaid'] ?? 0),
                'saldo'    => (float) ($x['balance'] ?? 0),
                'impuesto' => (float) ($x['tax'] ?? 0),
                'estado_dian' => $x['stamp']['legalStatus'] ?? null,
                'cufe'     => mb_substr((string) ($x['stamp']['cufe'] ?? ''), 0, 120) ?: null,
                'concepto' => mb_substr((string) ($x['items'][0]['name'] ?? ''), 0, 190) ?: null,
                'sincronizada_en' => $ahora,
                'updated_at' => $ahora,
            ]
        );
    }

    private function guardarPago(array $x, \DateTimeInterface $ahora): void
    {
        DB::table('alegra_pagos')->updateOrInsert(
            ['company_id' => $this->companyId, 'alegra_id' => (string) $x['id']],
            [
                'numero' => isset($x['number']) ? (string) $x['number'] : null,
                'fecha'  => $x['date'] ?? null,
                'monto'  => (float) ($x['amount'] ?? 0),
                'tipo'   => $x['type'] ?? null,
                'metodo' => $x['paymentMethod'] ?? null,
                'banco'  => mb_substr((string) ($x['bankAccount']['name'] ?? ''), 0, 120) ?: null,
                'estado' => $x['status'] ?? null,
                'cliente_nombre'         => mb_substr((string) ($x['client']['name'] ?? ''), 0, 190) ?: null,
                'cliente_identificacion' => self::documento($x['client']['identification'] ?? null),
                'facturas' => json_encode(array_map(fn ($i) => ['id' => (string) ($i['id'] ?? ''), 'numero' => $i['number'] ?? null, 'monto' => (float) ($i['amount'] ?? 0)], (array) ($x['invoices'] ?? []))),
                'sincronizada_en' => $ahora,
                'updated_at' => $ahora,
            ]
        );
    }

    private function guardarContacto(array $x, \DateTimeInterface $ahora): void
    {
        DB::table('alegra_contactos')->updateOrInsert(
            ['company_id' => $this->companyId, 'alegra_id' => (string) $x['id']],
            [
                'nombre'         => mb_substr((string) ($x['name'] ?? ''), 0, 190) ?: null,
                'identificacion' => self::documento($x['identificationObject'] ?? $x['identification'] ?? null) ?? self::documento($x['identification'] ?? null),
                'email'          => mb_substr((string) ($x['email'] ?? ''), 0, 190) ?: null,
                'telefono'       => mb_substr((string) ($x['mobile'] ?? $x['phonePrimary'] ?? ''), 0, 40) ?: null,
                'tipo_documento' => mb_substr((string) ($x['identificationObject']['type'] ?? ''), 0, 10) ?: null,
                'ciudad'         => mb_substr((string) ($x['address']['city'] ?? ''), 0, 80) ?: null,
                'departamento'   => mb_substr((string) ($x['address']['department'] ?? ''), 0, 80) ?: null,
                'estado'         => $x['status'] ?? null,
                'sincronizada_en' => $ahora,
                'updated_at' => $ahora,
            ]
        );
    }

    private function guardarRecurrente(array $x, \DateTimeInterface $ahora): void
    {
        DB::table('alegra_recurrentes')->updateOrInsert(
            ['company_id' => $this->companyId, 'alegra_id' => (string) $x['id']],
            [
                'cliente_alegra_id' => isset($x['client']['id']) ? (string) $x['client']['id'] : null,
                'cliente_nombre'    => mb_substr((string) ($x['client']['name'] ?? ''), 0, 190) ?: null,
                'inicio'     => self::dia($x['startDate'] ?? null),
                'fin'        => self::dia($x['endDate'] ?? null),
                'ultima'     => self::dia($x['lastCreation'] ?? null),
                'proxima'    => self::dia($x['nextCreation'] ?? null),
                'cada_meses' => isset($x['repeatEvery']) ? (int) $x['repeatEvery'] : null,
                'total'      => (float) ($x['total'] ?? 0),
                'concepto'   => mb_substr((string) ($x['items'][0]['name'] ?? ''), 0, 190) ?: null,
                'sincronizada_en' => $ahora,
                'updated_at' => $ahora,
            ]
        );
    }

    /**
     * Las recurrentes traen la fecha con hora y en UTC («2026-09-29T05:00:00.000Z» es la
     * medianoche del 29 en Colombia): se pasa a la zona de la plataforma y se deja el día.
     */
    private static function dia(mixed $v): ?string
    {
        if (!$v) {
            return null;
        }

        try {
            return \Carbon\Carbon::parse((string) $v)->setTimezone(config('app.timezone'))->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /** Sólo los contactos (unas veinte consultas), para traer su tipo de documento y ciudad. */
    public function sincronizarContactos(): int
    {
        $inicio = now();

        if ($this->recorrer($this->alegra(), 'contacts', 'Contactos', fn (array $x) => $this->guardarContacto($x, $inicio))) {
            DB::table('alegra_contactos')->where('company_id', $this->companyId)->where('sincronizada_en', '<', $inicio)->delete();
        }

        $this->emparejar();

        return DB::table('alegra_contactos')->where('company_id', $this->companyId)->count();
    }

    /** Sólo las recurrentes: son seis consultas, para refrescarlas sin la corrida completa. */
    public function sincronizarRecurrentes(): int
    {
        $inicio = now();

        if ($this->recorrer($this->alegra(), 'recurring-invoices', 'Facturas recurrentes', fn (array $x) => $this->guardarRecurrente($x, $inicio), false)) {
            DB::table('alegra_recurrentes')->where('company_id', $this->companyId)->where('sincronizada_en', '<', $inicio)->delete();
        }

        $this->emparejarRecurrentes();

        return DB::table('alegra_recurrentes')->where('company_id', $this->companyId)->count();
    }

    /** La recurrente no trae el documento del cliente: se llega a él por su contacto de Alegra. */
    private function emparejarRecurrentes(): void
    {
        $porContacto = DB::table('alegra_contactos')->where('company_id', $this->companyId)->whereNotNull('user_id')->pluck('user_id', 'alegra_id');

        foreach (DB::table('alegra_recurrentes')->where('company_id', $this->companyId)->get(['id', 'cliente_alegra_id', 'user_id']) as $r) {
            $uid = isset($porContacto[$r->cliente_alegra_id]) ? (int) $porContacto[$r->cliente_alegra_id] : null;

            if ($uid !== ($r->user_id !== null ? (int) $r->user_id : null)) {
                DB::table('alegra_recurrentes')->where('id', $r->id)->update(['user_id' => $uid]);
            }
        }
    }

    // ── Cruce con Netvula ────────────────────────────────────────────────────

    /** Le pone a cada factura y contacto de Alegra su cliente y su cuenta de cobro de Netvula. */
    public function emparejar(): void
    {
        // Clientes por documento (sin puntos ni espacios). Si un documento se repite, gana el vigente.
        $porDocumento = [];

        foreach (DB::table('user_data as ud')->join('users as u', 'u.id', '=', 'ud.user_id')
            ->where('u.company_id', $this->companyId)->orderBy('ud.active')
            ->get(['ud.user_id', 'ud.dni']) as $c) {
            if ($d = self::documento($c->dni)) {
                $porDocumento[$d] = (int) $c->user_id;
            }
        }

        foreach (DB::table('alegra_contactos')->where('company_id', $this->companyId)->get(['id', 'identificacion', 'user_id']) as $c) {
            $uid = $porDocumento[$c->identificacion ?? ''] ?? null;

            if ($uid !== ($c->user_id !== null ? (int) $c->user_id : null)) {
                DB::table('alegra_contactos')->where('id', $c->id)->update(['user_id' => $uid]);
            }
        }

        $this->emparejarRecurrentes();

        // Las que Netvula emitió hacia Alegra ya saben cuál es su cuenta de cobro.
        $emitidas = DB::table('facturas_electronicas')->where('company_id', $this->companyId)->where('proveedor', 'alegra')
            ->where('tipo', 'factura')->whereNotNull('externo_id')->pluck('det_facturation_id', 'externo_id');

        // Todas las cuentas de cobro por cliente, incluidas las anuladas: una anulada en Netvula
        // que sigue abierta en Alegra es justo lo que hay que ver.
        $cuentas = [];

        foreach (DB::table('det_facturations as d')->join('cab_facturations as c', 'c.id', '=', 'd.cab_id')
            ->where('c.company_id', $this->companyId)
            ->get(['d.id', 'c.user_id', 'd.date_facturation', 'd.price_total', 'd.price_discount', 'd.anulada_en']) as $d) {
            $cuentas[(int) $d->user_id][] = $d;
        }

        $usadas = [];

        foreach (DB::table('alegra_facturas')->where('company_id', $this->companyId)->orderBy('fecha')->orderBy('id')
            ->get(['id', 'alegra_id', 'cliente_identificacion', 'fecha', 'total', 'user_id', 'det_facturation_id']) as $f) {
            $uid = $porDocumento[$f->cliente_identificacion ?? ''] ?? null;
            $det = isset($emitidas[$f->alegra_id]) ? (int) $emitidas[$f->alegra_id] : null;

            if ($det === null && $uid !== null && $f->fecha) {
                $mejor = null;

                foreach ($cuentas[$uid] ?? [] as $d) {
                    if (isset($usadas[$d->id]) || !$d->date_facturation) {
                        continue;
                    }

                    $dias = abs((strtotime(substr((string) $d->date_facturation, 0, 10)) - strtotime((string) $f->fecha)) / 86400);

                    if ($dias > self::DIAS_DE_HOLGURA) {
                        continue;
                    }

                    $mismoValor = abs(((float) $d->price_total - (float) ($d->price_discount ?? 0)) - (float) $f->total) < 1
                        || abs((float) $d->price_total - (float) $f->total) < 1;
                    // Gana la no anulada, luego el mismo valor, luego la fecha más cercana.
                    $puntaje = [$d->anulada_en ? 1 : 0, $mismoValor ? 0 : 1, $dias];

                    if ($mejor === null || $puntaje < $mejor[0]) {
                        $mejor = [$puntaje, (int) $d->id];
                    }
                }

                $det = $mejor[1] ?? null;
            }

            if ($det !== null) {
                $usadas[$det] = true;
            }

            if ($uid !== ($f->user_id !== null ? (int) $f->user_id : null) || $det !== ($f->det_facturation_id !== null ? (int) $f->det_facturation_id : null)) {
                DB::table('alegra_facturas')->where('id', $f->id)->update(['user_id' => $uid, 'det_facturation_id' => $det]);
            }
        }
    }

    // ── Consultas en vivo (pocas filas, con caché corta) ─────────────────────

    /** Datos de la cuenta: empresa, bancos, numeraciones, impuestos, ítems y notas crédito. */
    public function cuenta(bool $refrescar = false): array
    {
        $clave = "alegra:cuenta:{$this->companyId}";

        if ($refrescar) {
            Cache::forget($clave);
        }

        return Cache::remember($clave, now()->addMinutes(15), function () {
            $a = $this->alegra();
            $seguro = function (callable $fn) { try { return $fn(); } catch (\Throwable) { return []; } };
            $empresa = $seguro(fn () => $a->leer('company'));

            return [
                'empresa' => [
                    'nombre' => $empresa['name'] ?? null, 'identificacion' => $empresa['identification'] ?? null,
                    'regimen' => $empresa['regime'] ?? null, 'correo' => $empresa['email'] ?? null,
                    'ciudad' => $empresa['address']['city'] ?? null, 'departamento' => $empresa['address']['department'] ?? null,
                    'direccion' => $empresa['address']['address'] ?? null, 'moneda' => $empresa['currency']['code'] ?? null,
                ],
                'bancos' => array_map(fn ($b) => ['id' => $b['id'] ?? null, 'nombre' => $b['name'] ?? null, 'tipo' => $b['type'] ?? null, 'estado' => $b['status'] ?? null],
                    array_values(array_filter($seguro(fn () => $a->leer('bank-accounts')), 'is_array'))),
                'numeraciones' => array_map(fn ($n) => [
                    'id' => $n['id'] ?? null, 'nombre' => $n['name'] ?? null, 'prefijo' => $n['prefix'] ?? null, 'tipo' => $n['documentType'] ?? null,
                    'electronica' => (bool) ($n['isElectronic'] ?? false), 'siguiente' => $n['nextInvoiceNumber'] ?? null, 'hasta' => $n['maxInvoiceNumber'] ?? null,
                    'vence' => $n['endDate'] ?? null, 'resolucion' => $n['resolutionNumber'] ?? null, 'estado' => $n['status'] ?? null,
                ], array_values(array_filter($seguro(fn () => $a->leer('number-templates')), 'is_array'))),
                'impuestos' => array_map(fn ($i) => ['id' => $i['id'] ?? null, 'nombre' => $i['name'] ?? null, 'porcentaje' => $i['percentage'] ?? null, 'estado' => $i['status'] ?? null],
                    array_values(array_filter($seguro(fn () => $a->leer('taxes')), 'is_array'))),
                'items' => array_map(fn ($i) => ['id' => $i['id'] ?? null, 'nombre' => $i['name'] ?? null, 'precio' => $i['price'][0]['price'] ?? null, 'estado' => $i['status'] ?? null],
                    array_values(array_filter($seguro(fn () => $a->leer('items', ['limit' => 30])), 'is_array'))),
                'notas_credito' => array_map(fn ($n) => [
                    'id' => $n['id'] ?? null, 'numero' => $n['numberTemplate']['fullNumber'] ?? $n['numberTemplate']['number'] ?? null, 'fecha' => $n['date'] ?? null,
                    'cliente' => $n['client']['name'] ?? null, 'total' => $n['total'] ?? null, 'estado' => $n['status'] ?? null,
                    'estado_dian' => $n['stamp']['legalStatus'] ?? null, 'motivo' => $n['cause'] ?? null,
                    'facturas' => array_map(fn ($f) => $f['number'] ?? ($f['id'] ?? null), (array) ($n['invoices'] ?? [])),
                ], array_values(array_filter($seguro(fn () => $a->leer('credit-notes', ['limit' => 30, 'order_field' => 'id', 'order_direction' => 'DESC'])), 'is_array'))),
            ];
        });
    }
}

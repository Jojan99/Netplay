<?php

namespace App\Services\FacturaElectronica\Proveedores;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Alegra Contabilidad: https://developer.alegra.com
 *
 * Cada empresa conecta su propia cuenta con el correo y el token que genera en
 * Alegra → Configuración → API. La factura queda también en su contabilidad.
 *
 * Lo que hay que saber de esta API, que no es obvio:
 *  - Los identificadores son texto (están migrando a UUID): nunca se tratan como enteros.
 *  - «price» va ANTES de impuestos; Alegra suma el IVA.
 *  - Si el timbrado ante la DIAN falla, Alegra responde 400 pero DEJA la factura
 *    creada en borrador. Ese 400 trae el id: hay que guardarlo, o al reintentar
 *    se crea una segunda factura.
 *  - No hay aviso cuando la DIAN contesta: se consulta la factura.
 *  - Los errores llegan con tres formas distintas.
 *  - La búsqueda de contactos por identificación es por «contiene», no exacta.
 */
class Alegra implements ProveedorDeFacturaElectronica
{
    private const BASE = 'https://api.alegra.com/api/v1';

    public function __construct(private array $credenciales, private int $companyId) {}

    public static function campos(): array
    {
        return [
            'email' => ['etiqueta' => 'Correo de la cuenta', 'secreto' => false, 'ayuda' => 'El correo con el que entra a Alegra.'],
            'token' => ['etiqueta' => 'Token de la API', 'secreto' => true, 'ayuda' => 'En Alegra → Configuración → API - Integraciones con otros sistemas.'],
        ];
    }

    public function probar(): array
    {
        try {
            $empresa = $this->pedir('GET', '/company');

            if (strtolower((string) ($empresa['applicationVersion'] ?? 'colombia')) !== 'colombia') {
                return ['ok' => false, 'detalle' => 'La cuenta de Alegra no es de Colombia: no sirve para facturar ante la DIAN.'];
            }

            $electronicas = array_filter($this->lista('/number-templates', ['documentType' => 'invoice']), fn ($n) => self::activa($n) && !empty($n['isElectronic']));

            return [
                'ok'      => true,
                'detalle' => $electronicas
                    ? 'Conectado a Alegra (' . ($empresa['name'] ?? 'cuenta') . '). Tiene ' . count($electronicas) . ' numeración(es) electrónica(s) activa(s).'
                    : 'Conectado a Alegra, pero no hay ninguna numeración de factura electrónica activa. Habilítela en Alegra antes de emitir.',
            ];
        } catch (ErrorDelProveedor $e) {
            return ['ok' => false, 'detalle' => $e->getMessage()];
        }
    }

    public function catalogos(): array
    {
        $numeracion = fn (array $n) => [
            'id'          => (string) ($n['id'] ?? ''),
            'nombre'      => trim(($n['prefix'] ?? '') . ' · ' . ($n['name'] ?? 'Numeración')),
            'electronica' => !empty($n['isElectronic']),
        ];

        return [
            'numeraciones'    => array_values(array_map($numeracion, array_filter($this->lista('/number-templates', ['documentType' => 'invoice']), [self::class, 'activa']))),
            'numeraciones_nc' => array_values(array_map($numeracion, array_filter($this->lista('/number-templates', ['documentType' => 'creditNote']), [self::class, 'activa']))),
            'impuestos' => array_values(array_map(
                fn ($t) => ['id' => (string) $t['id'], 'nombre' => ($t['name'] ?? 'Impuesto') . ' (' . ($t['percentage'] ?? 0) . '%)', 'porcentaje' => (float) ($t['percentage'] ?? 0)],
                array_filter($this->lista('/taxes'), fn ($t) => self::activa($t) && strtoupper((string) ($t['type'] ?? 'IVA')) === 'IVA')
            )),
            'productos' => array_values(array_map(
                fn ($i) => ['id' => (string) $i['id'], 'nombre' => trim((string) ($i['name'] ?? 'Ítem'))],
                array_filter($this->lista('/items', ['order_field' => 'name'], 4), [self::class, 'activa'])
            )),
            'cuentas' => array_values(array_map(
                fn ($c) => ['id' => (string) $c['id'], 'nombre' => trim((string) ($c['name'] ?? 'Cuenta'))],
                array_filter($this->lista('/bank-accounts'), [self::class, 'activa'])
            )),
        ];
    }

    public function emitir(array $documento, array $ajustes): array
    {
        $cuerpo = [
            'date'        => $documento['fecha'],
            'dueDate'     => $documento['fecha'],
            'client'      => ['id' => $this->cliente($documento['cliente'])],
            'items'       => [$this->item($documento, $ajustes)],
            'status'      => 'open',
            'paymentForm' => 'CASH',
            // Medio de pago según el catálogo de la DIAN que usa Alegra.
            'paymentMethod' => (string) ($ajustes['medio_pago'] ?? 'CASH'),
            'stamp'       => ['generateStamp' => true],
            'anotation'   => mb_substr((string) ($documento['observaciones'] ?? ''), 0, 500),
        ];

        if (!empty($ajustes['numeracion_id'])) {
            $cuerpo['numberTemplate'] = ['id' => (string) $ajustes['numeracion_id']];
        }

        // Con cuenta elegida, la factura queda pagada también en Alegra.
        if (!empty($ajustes['cuenta_id'])) {
            $cuerpo['payments'] = [[
                'date' => $documento['fecha'], 'account' => ['id' => (string) $ajustes['cuenta_id']],
                'amount' => $documento['total'], 'paymentMethod' => 'transfer',
            ]];
        }

        return $this->crear('/invoices', $cuerpo);
    }

    public function notaCredito(array $documento, array $original, array $ajustes): array
    {
        $cuerpo = [
            'date'     => $documento['fecha'],
            'client'   => ['id' => $this->cliente($documento['cliente'])],
            'items'    => [$this->item($documento, $ajustes)],
            'type'     => 'VOID_ELECTRONIC_INVOICE',
            'cause'    => mb_substr((string) ($documento['observaciones'] ?? 'Anulación de la factura'), 0, 250),
            'invoices' => [['id' => (string) $original['externo_id'], 'amount' => $documento['total']]],
            'stamp'    => ['generateStamp' => true],
        ];

        if (!empty($ajustes['numeracion_nc_id'])) {
            $cuerpo['numberTemplate'] = ['id' => (string) $ajustes['numeracion_nc_id']];
        }

        return $this->crear('/credit-notes', $cuerpo);
    }

    public function consultar(string $externoId, string $tipo = 'factura'): array
    {
        $ruta = $tipo === 'nota_credito' ? '/credit-notes/' : '/invoices/';

        return $this->resultado($this->pedir('GET', $ruta . rawurlencode($externoId), $tipo === 'factura' ? ['fields' => 'pdf'] : []));
    }

    public function pdf(string $externoId, string $tipo = 'factura'): ?string
    {
        $ruta = $tipo === 'nota_credito' ? '/credit-notes/' : '/invoices/';
        $url  = $this->pedir('GET', $ruta . rawurlencode($externoId), ['fields' => 'pdf'])['pdf'] ?? null;

        if (!is_string($url) || !preg_match('#^https://#i', $url)) {
            return null;
        }

        try {
            $r = Http::timeout(40)->get($url);
        } catch (ConnectionException $e) {
            return null;
        }

        return $r->successful() ? $r->body() : null;
    }

    // ── Traducción a lo que pide Alegra ──────────────────────────────────────

    /** El id del contacto en Alegra; si no existe, lo crea. */
    private function cliente(array $c): string
    {
        $soloDigitos = fn ($v) => preg_replace('/[^0-9A-Za-z]/', '', (string) $v);

        foreach ($this->pedir('GET', '/contacts', ['identification' => $c['numero'], 'limit' => 30]) as $contacto) {
            if (is_array($contacto) && $soloDigitos($contacto['identificationObject']['number'] ?? $contacto['identification'] ?? '') === $soloDigitos($c['numero'])) {
                return (string) $contacto['id'];
            }
        }

        $empresa = $c['persona'] === 'juridica';
        // PPT y PEP no están en la lista de Alegra: van como documento de identificación extranjero.
        $tipos   = ['CC' => 'CC', 'NIT' => 'NIT', 'CE' => 'CE', 'PP' => 'PP', 'TI' => 'TI', 'PPT' => 'DIE', 'PEP' => 'DIE'];

        $identificacion = ['type' => $tipos[$c['tipo_documento']] ?? 'CC', 'number' => $c['numero']];
        if ($c['dv'] !== null && $c['dv'] !== '') {
            $identificacion['dv'] = (int) $c['dv'];
        }

        $nuevo = [
            'identificationObject' => $identificacion,
            'kindOfPerson' => $empresa ? 'LEGAL_ENTITY' : 'PERSON_ENTITY',
            // No responsable de IVA: lo normal en un suscriptor residencial.
            'regime'       => $empresa ? 'COMMON_REGIME' : 'SIMPLIFIED_REGIME',
            'address'      => ['address' => $c['direccion'], 'city' => $c['ciudad'], 'department' => $c['departamento']],
            'phonePrimary' => $c['telefono'],
            'type'         => ['client'],
        ];

        if ($empresa) {
            $nuevo['name'] = $c['razon_social'];
        } else {
            $nuevo['nameObject'] = ['firstName' => $c['nombres'], 'lastName' => $c['apellidos'] ?: $c['nombres']];
        }
        if ($c['email']) {
            $nuevo['email'] = $c['email'];
        }

        return (string) $this->pedir('POST', '/contacts', $nuevo)['id'];
    }

    /** El precio va sin IVA: Alegra lo suma. */
    private function item(array $d, array $ajustes): array
    {
        $item = [
            'id'          => (string) $ajustes['producto_id'],
            'price'       => $d['base'],
            'quantity'    => 1,
            'description' => mb_substr($d['concepto'], 0, 500),
        ];

        if ($d['impuesto'] > 0 && !empty($ajustes['impuesto_id'])) {
            $item['tax'] = [['id' => (string) $ajustes['impuesto_id']]];
        }

        return $item;
    }

    /**
     * Crea el documento. Un 400 con el documento adentro es «creado pero no
     * timbrado»: se devuelve como rechazado CON su id, para no duplicarlo.
     */
    private function crear(string $ruta, array $cuerpo): array
    {
        $r    = $this->enviar('POST', $ruta, $cuerpo);
        $json = (array) $r->json();

        if ($r->successful()) {
            return $this->resultado($json);
        }

        $creado = $this->documentoEnElError($json);

        if ($creado) {
            $resultado = $this->resultado($creado);

            return ['estado' => 'rechazada', 'error' => $this->mensaje($r), 'respuesta' => $json] + $resultado;
        }

        throw new ErrorDelProveedor($this->mensaje($r), $r->status() === 429 || $r->serverError(), $json);
    }

    /** Busca, dentro de una respuesta de error, la factura que Alegra dejó creada. */
    private function documentoEnElError(array $json): ?array
    {
        foreach ([$json, $json['invoice'] ?? null, $json['creditNote'] ?? null, $json['data'] ?? null, $json['error']['invoice'] ?? null] as $posible) {
            if (is_array($posible) && !empty($posible['id']) && (isset($posible['numberTemplate']) || isset($posible['client']) || isset($posible['items']))) {
                return $posible;
            }
        }

        return null;
    }

    /**
     * La respuesta de Alegra, con los nombres de la plataforma. El sello se lee
     * a la defensiva: la documentación no trae un ejemplo colombiano completo.
     */
    private function resultado(array $r): array
    {
        $sello  = (array) ($r['stamp'] ?? []);
        $estado = strtoupper(trim((string) ($sello['legalStatus'] ?? '') . ' ' . (string) ($sello['emissionStatus'] ?? '')));

        $aceptada  = str_contains($estado, 'ACCEPTED');
        $rechazada = str_contains($estado, 'REJECTED');

        // En la creación «numberTemplate» llega como lista; en la consulta, como objeto.
        $n      = (array) ($r['numberTemplate'] ?? []);
        $n      = isset($n[0]) && is_array($n[0]) ? $n[0] : $n;
        $numero = $n['fullNumber'] ?? (isset($n['number']) ? ($n['prefix'] ?? '') . $n['number'] : null);

        $avisos = $sello['warnings'] ?? $sello['entityResponse'] ?? null;
        $avisos = is_array($avisos) ? json_encode($avisos, JSON_UNESCAPED_UNICODE) : (string) $avisos;

        return [
            'estado'      => $aceptada && !$rechazada ? 'emitida' : ($rechazada ? 'rechazada' : 'pendiente'),
            'externo_id'  => isset($r['id']) ? (string) $r['id'] : null,
            'numero'      => $numero !== null ? (string) $numero : null,
            'cufe'        => $sello['cufe'] ?? $sello['uuid'] ?? $sello['hashCode'] ?? null,
            'estado_dian' => trim((string) ($sello['legalStatus'] ?? $sello['emissionStatus'] ?? '')) ?: null,
            'pdf_url'     => is_string($r['pdf'] ?? null) ? $r['pdf'] : null,
            'qr'          => $sello['barCodeContent'] ?? null,
            'error'       => $aceptada && !$rechazada ? null : ($rechazada
                ? ('La DIAN rechazó el documento. ' . $avisos)
                : ($sello ? 'La DIAN todavía no responde. Actualice en unos minutos.' : 'Alegra creó el documento pero no lo timbró. Revíselo en Alegra y actualice aquí.')),
            'respuesta'   => $r,
        ];
    }

    private static function activa(array $x): bool
    {
        return in_array(strtolower((string) ($x['status'] ?? 'active')), ['active', 'activo', ''], true);
    }

    // ── Lectura libre (módulo de Alegra) ─────────────────────────────────────

    /**
     * Una consulta de sólo lectura a Alegra. Es lo que usa el módulo para mostrar lo que hay
     * en la cuenta (facturas, contactos, pagos…): nunca crea ni cambia nada allá.
     *
     * @return array<mixed>
     */
    public function leer(string $ruta, array $filtros = []): array
    {
        return $this->pedir('GET', '/' . ltrim($ruta, '/'), $filtros);
    }

    // ── Escrituras del módulo de Alegra ──────────────────────────────────────
    //
    // Las cuatro pasan siempre por la bandeja de aprobación (OperacionesDeAlegra): nadie las
    // llama directo. Los cuerpos copian la forma que la propia cuenta devuelve al leer un
    // pago, una nota crédito y una recurrente ya existentes.

    /**
     * Registra en Alegra el pago de una factura que allá sigue abierta.
     *
     * Antes de escribir vuelve a mirar la factura: si ya no está abierta o su saldo es
     * menor, no registra nada. Así dos corridas (o un pago que alguien cargó a mano en
     * Alegra entre tanto) no dejan el pago duplicado.
     *
     * @return array{id:?string, numero:?string, factura:array<string,mixed>}
     */
    public function registrarPago(string $facturaId, float $monto, string $fecha, string $bancoId, string $metodo, string $nota = ''): array
    {
        $factura = $this->pedir('GET', '/invoices/' . $facturaId);

        if (($factura['status'] ?? '') !== 'open') {
            throw new ErrorDelProveedor('La factura ya no está abierta en Alegra (' . ($factura['status'] ?? 'sin estado') . '): no se registró el pago.');
        }
        if ((float) ($factura['balance'] ?? 0) + 0.5 < $monto) {
            throw new ErrorDelProveedor('El saldo de la factura en Alegra (' . number_format((float) ($factura['balance'] ?? 0), 0, ',', '.') . ') es menor que el pago: no se registró.');
        }

        $cuerpo = [
            'date'          => $fecha,
            'type'          => 'in',
            'bankAccount'   => ['id' => $bancoId],
            'paymentMethod' => $metodo,
            'client'        => ['id' => (string) ($factura['client']['id'] ?? '')],
            'invoices'      => [['id' => $facturaId, 'amount' => $monto]],
        ];
        if ($nota !== '') {
            $cuerpo['anotation'] = mb_substr($nota, 0, 250);
        }

        $pago = $this->pedir('POST', '/payments', $cuerpo);

        return [
            'id'     => isset($pago['id']) ? (string) $pago['id'] : null,
            'numero' => isset($pago['numberTemplate']['fullNumber']) ? (string) $pago['numberTemplate']['fullNumber'] : (isset($pago['number']) ? (string) $pago['number'] : null),
            'factura' => ['numero' => $factura['numberTemplate']['fullNumber'] ?? null, 'saldo_antes' => (float) ($factura['balance'] ?? 0)],
        ];
    }

    /**
     * Anula ante la DIAN una factura que ya existe en Alegra, con una nota crédito por el total.
     * Los ítems y el cliente se toman de la propia factura, para devolver exactamente lo facturado.
     *
     * @return array<string,mixed>  como emitir(): estado, externo_id, numero, cufe, error…
     */
    public function anularFactura(string $facturaId, string $causa, ?string $numeracionId = null): array
    {
        $factura = $this->pedir('GET', '/invoices/' . $facturaId);

        if (in_array($factura['status'] ?? '', ['void', 'draft'], true)) {
            throw new ErrorDelProveedor('La factura está ' . ($factura['status'] === 'void' ? 'anulada' : 'en borrador') . ' en Alegra: no necesita nota crédito.');
        }
        if ((float) ($factura['totalPaid'] ?? 0) > 0) {
            throw new ErrorDelProveedor('La factura tiene pagos registrados en Alegra: anúlela allá, donde se decide qué hacer con ese pago.');
        }

        $items = [];

        foreach ((array) ($factura['items'] ?? []) as $i) {
            $item = ['id' => (string) $i['id'], 'price' => (float) $i['price'], 'quantity' => (float) ($i['quantity'] ?? 1)];

            if (!empty($i['description'])) {
                $item['description'] = mb_substr((string) $i['description'], 0, 500);
            }
            if (!empty($i['tax'])) {
                $item['tax'] = array_map(fn ($t) => ['id' => (string) $t['id']], (array) $i['tax']);
            }

            $items[] = $item;
        }

        $cuerpo = [
            'date'     => now()->toDateString(),
            'client'   => ['id' => (string) ($factura['client']['id'] ?? '')],
            'items'    => $items,
            'type'     => 'VOID_ELECTRONIC_INVOICE',
            'cause'    => mb_substr($causa, 0, 250),
            'invoices' => [['id' => $facturaId, 'amount' => (float) ($factura['total'] ?? 0)]],
            'stamp'    => ['generateStamp' => true],
        ];
        if ($numeracionId) {
            $cuerpo['numberTemplate'] = ['id' => $numeracionId];
        }

        return $this->crear('/credit-notes', $cuerpo);
    }

    /**
     * Crea en Alegra el contacto de un cliente de Netvula, con las mismas convenciones que ya
     * usa la cuenta (persona natural, no responsable de IVA, nombre partido en cuatro).
     * Si ya existe uno con ese documento no crea nada: devuelve el que hay.
     *
     * @param  array{tipo_documento:string, numero:string, dv:?string, persona:string, nombres:string, apellidos:string, direccion:string, ciudad:?string, departamento:?string, telefono:string, email:?string} $c
     * @return array{id:string, existia:bool}
     */
    public function crearContacto(array $c): array
    {
        $limpio = fn ($v) => preg_replace('/[^0-9A-Za-z]/', '', (string) $v);

        foreach ($this->pedir('GET', '/contacts', ['identification' => $c['numero'], 'limit' => 30]) as $contacto) {
            if (is_array($contacto) && $limpio($contacto['identificationObject']['number'] ?? $contacto['identification'] ?? '') === $limpio($c['numero'])) {
                return ['id' => (string) $contacto['id'], 'existia' => true];
            }
        }

        // PPT y PEP no están en la lista de Alegra: van como documento de identificación extranjero.
        $tipos = ['CC' => 'CC', 'NIT' => 'NIT', 'CE' => 'CE', 'PP' => 'PP', 'TI' => 'TI', 'PPT' => 'DIE', 'PEP' => 'DIE', 'DIE' => 'DIE'];
        $tipo  = $tipos[$c['tipo_documento']] ?? 'CC';
        $empresa = $c['persona'] === 'juridica';

        $identificacion = ['type' => $tipo, 'number' => $c['numero']];
        if (($c['dv'] ?? '') !== '' && $c['dv'] !== null) {
            $identificacion['dv'] = (int) $c['dv'];
        }

        $direccion = ['address' => $c['direccion']];
        if (!empty($c['ciudad']) && !empty($c['departamento'])) {
            $direccion += ['city' => $c['ciudad'], 'department' => $c['departamento']];
        }

        $nuevo = [
            'identificationObject' => $identificacion,
            'kindOfPerson' => $empresa ? 'LEGAL_ENTITY' : 'PERSON_ENTITY',
            'regime'       => $empresa ? 'COMMON_REGIME' : 'SIMPLIFIED_REGIME',
            'address'      => $direccion,
            'mobile'       => $c['telefono'],
            'phonePrimary' => $c['telefono'],
            'type'         => ['client'],
        ];

        if ($empresa) {
            $nuevo['name'] = trim($c['nombres'] . ' ' . $c['apellidos']);
        } else {
            $n = preg_split('/\s+/', trim($c['nombres'])) ?: [];
            $ap = preg_split('/\s+/', trim($c['apellidos'])) ?: [];
            $nuevo['nameObject'] = array_filter([
                'firstName'      => $n[0] ?? '',
                'secondName'     => implode(' ', array_slice($n, 1)),
                'lastName'       => $ap[0] ?? ($n[0] ?? ''),
                'secondLastName' => implode(' ', array_slice($ap, 1)),
            ], fn ($v) => $v !== '');
        }
        if (!empty($c['email'])) {
            $nuevo['email'] = $c['email'];
        }

        return ['id' => (string) $this->pedir('POST', '/contacts', $nuevo)['id'], 'existia' => false];
    }

    /**
     * Quita una factura recurrente: Alegra deja de facturarle a ese cliente.
     * Devuelve la recurrente como estaba, para poder volver a crearla.
     *
     * @return array<string,mixed>
     */
    public function quitarRecurrente(string $recurrenteId): array
    {
        $ruta  = '/recurring-invoices/' . $recurrenteId;
        $antes = $this->pedir('GET', $ruta);

        // Alegra contestó «Ha ocurrido un error inesperado» (código 8097) a un borrado hecho tal
        // como dice su documentación. Es un error de su lado sin explicación, así que se prueba de
        // las dos formas en que un servicio suele aceptar un DELETE —sin cuerpo y con un cuerpo
        // JSON vacío— y después de cada intento se comprueba si la recurrente sigue existiendo:
        // lo que importa es que haya dejado de facturar, no qué contestó.
        $intentos = [];

        foreach ([false, true, false] as $n => $conCuerpo) {
            // Sin pegarse a la lectura anterior: el límite real de Alegra es bajo.
            usleep($n === 0 ? 1200000 : 3000000);

            try {
                $http = Http::withBasicAuth((string) ($this->credenciales['email'] ?? ''), (string) ($this->credenciales['token'] ?? ''))->acceptJson()->timeout(40);
                $r = $conCuerpo ? $http->withBody('{}', 'application/json')->delete(self::BASE . $ruta) : $http->delete(self::BASE . $ruta);
            } catch (ConnectionException $e) {
                $intentos[] = ['forma' => $conCuerpo ? 'con cuerpo' : 'sin cuerpo', 'http' => null, 'respuesta' => 'sin conexión'];

                continue;
            }

            $intentos[] = ['forma' => $conCuerpo ? 'con cuerpo' : 'sin cuerpo', 'http' => $r->status(), 'respuesta' => mb_substr($r->body(), 0, 300)];

            if ($r->successful() || $r->status() === 404 || !$this->existe($ruta)) {
                return $antes + ['_borrado' => $intentos];
            }

            if (in_array($r->status(), [401, 402, 403], true)) {
                throw new ErrorDelProveedor($this->mensaje($r), false, ['intentos' => $intentos]);
            }
        }

        $ultimo = end($intentos);

        throw new ErrorDelProveedor(
            'Alegra no dejó borrar la factura recurrente (respondió «' . (json_decode((string) $ultimo['respuesta'], true)['message'] ?? 'error ' . $ultimo['http']) . '», código HTTP ' . ($ultimo['http'] ?? 'sin conexión')
                . '). La recurrente sigue activa en Alegra: bórrela allá directamente, en Ingresos → Facturas recurrentes.',
            false,
            ['intentos' => $intentos],
        );
    }

    /** ¿Ese recurso sigue existiendo en Alegra? Ante la duda, se dice que sí. */
    private function existe(string $ruta): bool
    {
        usleep(1200000);

        try {
            $r = $this->enviar('GET', $ruta);
        } catch (\Throwable) {
            return true;
        }

        return $r->status() !== 404;
    }

    /**
     * Crea una factura recurrente para un contacto, copiando una que ya funciona en la cuenta
     * (misma numeración, bodega, forma de pago, plazo e ítem) y cambiando cliente, valor y fecha.
     *
     * @param  array<string,mixed> $modelo  una recurrente existente, leída de Alegra
     * @return array{id:?string, proxima:?string}
     */
    public function crearRecurrente(string $clienteId, array $modelo, float $valor, string $inicio): array
    {
        $ejemplo = $modelo['items'][0] ?? null;

        if (!$ejemplo) {
            throw new ErrorDelProveedor('La recurrente que se iba a copiar no tiene ítems.');
        }

        $item = [
            'id'          => (string) ($ejemplo['itemId'] ?? $ejemplo['id']),
            'price'       => $valor,
            'quantity'    => 1,
            'description' => (string) ($ejemplo['description'] ?? ''),
        ];
        $impuestos = (array) ($ejemplo['taxes'] ?? $ejemplo['tax'] ?? []);
        if ($impuestos) {
            $item['tax'] = array_map(fn ($t) => ['id' => (string) $t['id']], $impuestos);
        }

        $cuerpo = array_filter([
            'startDate'     => $inicio,
            'repeatEvery'   => (int) ($modelo['repeatEvery'] ?? 1),
            'term'          => $this->terminoDePago($modelo['term'] ?? null),
            'observations'  => $modelo['observations'] ?? null,
            'anotation'     => $modelo['anotation'] ?? null,
            'paymentForm'   => $modelo['paymentForm'] ?? null,
            'operationType' => $modelo['operationType'] ?? null,
            'type'          => $modelo['type'] ?? null,
            'warehouse'     => isset($modelo['warehouse']['id']) ? ['id' => (string) $modelo['warehouse']['id']] : null,
            'numberTemplate' => isset($modelo['numberTemplate']['id']) ? ['id' => (string) $modelo['numberTemplate']['id']] : null,
            'priceList'     => isset($modelo['priceList']['id']) ? ['id' => (string) $modelo['priceList']['id']] : null,
        ], fn ($v) => $v !== null && $v !== '');

        $cuerpo['client'] = ['id' => $clienteId];
        $cuerpo['items']  = [$item];

        $r = $this->pedir('POST', '/recurring-invoices', $cuerpo);

        return ['id' => isset($r['id']) ? (string) $r['id'] : null, 'proxima' => $r['nextCreation'] ?? null];
    }

    /**
     * El término de pago, como lo pide Alegra al crear.
     *
     * Al LEER una recurrente Alegra devuelve en «term» los DÍAS del plazo («0», «30»), pero al
     * CREARLA espera el ID del término («1» = De contado, «4» = 30 días). Mandar los días tal cual
     * daba «El término de pago no es válido». Se busca en los términos de la cuenta el que tenga
     * esos días; si no hay uno igual, el de contado.
     */
    private function terminoDePago(mixed $delModelo): ?string
    {
        if ($delModelo === null || $delModelo === '') {
            return null;
        }

        // Algunas respuestas lo traen ya como objeto con su id.
        if (is_array($delModelo)) {
            return isset($delModelo['id']) ? (string) $delModelo['id'] : null;
        }

        $terminos = array_values(array_filter($this->leer('terms'), fn ($t) => is_array($t) && ($t['status'] ?? 'active') === 'active'));
        $dias = (int) $delModelo;

        foreach ($terminos as $t) {
            if ((int) ($t['days'] ?? -1) === $dias) {
                return (string) $t['id'];
            }
        }

        foreach ($terminos as $t) {
            if ((int) ($t['days'] ?? -1) === 0) {
                return (string) $t['id'];
            }
        }

        return null;
    }

    // ── Transporte ───────────────────────────────────────────────────────────

    /** Listas paginadas: Alegra entrega 30 por página, como mucho. */
    private function lista(string $ruta, array $filtros = [], int $paginas = 2): array
    {
        $todo = [];

        for ($p = 0; $p < $paginas; $p++) {
            $pagina = array_values(array_filter($this->pedir('GET', $ruta, $filtros + ['start' => $p * 30, 'limit' => 30]), 'is_array'));
            $todo   = array_merge($todo, $pagina);

            if (count($pagina) < 30) {
                break;
            }
        }

        return $todo;
    }

    private function enviar(string $metodo, string $ruta, array $datos = []): Response
    {
        try {
            $http = Http::withBasicAuth((string) ($this->credenciales['email'] ?? ''), (string) ($this->credenciales['token'] ?? ''))
                ->acceptJson()->timeout($metodo === 'POST' ? 120 : 40);

            return match (true) {
                $metodo === 'GET'             => $http->get(self::BASE . $ruta, $datos),
                $metodo === 'DELETE' && !$datos => $http->delete(self::BASE . $ruta),
                default                       => $http->send($metodo, self::BASE . $ruta, ['json' => $datos]),
            };
        } catch (ConnectionException $e) {
            throw new ErrorDelProveedor('No hubo conexión con Alegra. Intente de nuevo en unos minutos.', true);
        }
    }

    private function pedir(string $metodo, string $ruta, array $datos = []): array
    {
        $r = $this->enviar($metodo, $ruta, $datos);

        if (!$r->successful()) {
            // El «Too many requests» de Alegra no siempre llega con 429: a veces viene como 400.
            $limite = $r->status() === 429 || stripos($r->body(), 'too many requests') !== false;

            throw new ErrorDelProveedor($this->mensaje($r), $limite || $r->serverError(), (array) $r->json());
        }

        return (array) $r->json();
    }

    /** Alegra responde errores con tres formas: {code,message}, {error,code} y {error:{message,code}}. */
    private function mensaje(Response $r): string
    {
        if ($r->status() === 401) {
            return 'Alegra no aceptó las credenciales: revise el correo y el token.';
        }
        if ($r->status() === 402) {
            return 'La cuenta de Alegra está suspendida o su plan no incluye esta función.';
        }
        if ($r->status() === 429) {
            return 'Alegra limitó las peticiones por minuto. Se reintenta más tarde.';
        }

        $j     = (array) $r->json();
        $error = $j['error'] ?? null;
        $texto = is_array($error) ? ($error['message'] ?? null) : $error;
        $texto = $texto ?: ($j['message'] ?? null);

        return is_string($texto) && trim($texto) !== '' ? 'Alegra: ' . trim($texto) : 'Alegra respondió con el código ' . $r->status() . '.';
    }
}

<?php

namespace App\Services\FacturaElectronica\Proveedores;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Siigo Nube (Siigo API): https://siigoapi.docs.apiary.io
 *
 * Lo que hay que saber de esta API, que no es obvio:
 *  - Toda llamada lleva «Partner-Id» (el nombre de la aplicación registrada en
 *    Siigo), también la de autenticación.
 *  - El token dura 24 horas; se guarda para no pedir uno por factura.
 *  - Una factura con CUFE no se edita ni se anula: sólo se corrige con nota crédito.
 *  - La DIAN contesta en el mismo POST (stamp.status): Accepted, Rejected o Draft.
 *  - Límite: 100 peticiones por minuto por empresa, y Siigo bloquea al usuario
 *    API si la mayoría de sus peticiones son errores. Por eso se valida antes
 *    de enviar y un rechazo no se reintenta solo.
 */
class Siigo implements ProveedorDeFacturaElectronica
{
    private const BASE = 'https://api.siigo.com';

    public function __construct(private array $credenciales, private int $companyId) {}

    public static function campos(): array
    {
        return [
            'username'   => ['etiqueta' => 'Usuario API', 'secreto' => false, 'ayuda' => 'El correo que aparece en Siigo Nube → Alianzas → Mi credencial API.'],
            'access_key' => ['etiqueta' => 'Access key', 'secreto' => true, 'ayuda' => 'La clave de acceso que genera Siigo en esa misma pantalla.'],
            'partner_id' => ['etiqueta' => 'Partner-Id', 'secreto' => false, 'ayuda' => 'El nombre de la aplicación registrada en Siigo al crear la credencial. Sin espacios.'],
        ];
    }

    public function probar(): array
    {
        try {
            Cache::forget($this->claveDelToken());
            $tipos = $this->pedir('GET', '/v1/document-types', ['type' => 'FV']);
            $electronicos = array_filter((array) $tipos, fn ($t) => self::esElectronico($t));

            return [
                'ok'      => true,
                'detalle' => $electronicos
                    ? 'Conectado a Siigo. Tiene ' . count($electronicos) . ' comprobante(s) de factura electrónica.'
                    : 'Conectado a Siigo, pero no hay ningún comprobante de factura electrónica activo. Habilítelo en Siigo Nube antes de emitir.',
            ];
        } catch (ErrorDelProveedor $e) {
            return ['ok' => false, 'detalle' => $e->getMessage()];
        }
    }

    public function catalogos(): array
    {
        $fila = fn (array $x, string $nombre, array $extra = []) => ['id' => $x['id'] ?? null, 'nombre' => $nombre] + $extra;

        return [
            'numeraciones' => array_values(array_map(
                fn ($t) => $fila($t, trim(($t['code'] ?? '') . ' · ' . ($t['name'] ?? '')), ['electronica' => self::esElectronico($t)]),
                array_filter((array) $this->pedir('GET', '/v1/document-types', ['type' => 'FV']), fn ($t) => $t['active'] ?? true)
            )),
            'numeraciones_nc' => array_values(array_map(
                fn ($t) => $fila($t, trim(($t['code'] ?? '') . ' · ' . ($t['name'] ?? ''))),
                array_filter((array) $this->pedir('GET', '/v1/document-types', ['type' => 'NC']), fn ($t) => $t['active'] ?? true)
            )),
            'impuestos' => array_values(array_map(
                fn ($t) => $fila($t, ($t['name'] ?? 'Impuesto') . ' (' . ($t['percentage'] ?? 0) . '%)', ['porcentaje' => (float) ($t['percentage'] ?? 0)]),
                array_filter((array) $this->pedir('GET', '/v1/taxes'), fn ($t) => ($t['active'] ?? true) && ($t['type'] ?? '') === 'IVA')
            )),
            'formas_pago' => array_values(array_map(
                fn ($t) => $fila($t, (string) ($t['name'] ?? 'Forma de pago'), ['con_vencimiento' => (bool) ($t['due_date'] ?? false)]),
                array_filter((array) $this->pedir('GET', '/v1/payment-types', ['document_type' => 'FV']), fn ($t) => $t['active'] ?? true)
            )),
            'vendedores' => array_values(array_map(
                fn ($u) => $fila($u, trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: (string) ($u['username'] ?? 'Usuario')),
                array_filter((array) $this->pedir('GET', '/v1/users'), fn ($u) => $u['active'] ?? true)
            )),
            'centros_costo' => array_values(array_map(
                fn ($c) => $fila($c, trim(($c['code'] ?? '') . ' · ' . ($c['name'] ?? ''))),
                array_filter((array) $this->pedir('GET', '/v1/cost-centers'), fn ($c) => $c['active'] ?? true)
            )),
            // En Siigo el producto se referencia por su código, no por su id.
            'productos' => array_values(array_map(
                fn ($p) => ['id' => $p['code'] ?? null, 'nombre' => trim(($p['code'] ?? '') . ' · ' . ($p['name'] ?? ''))],
                array_filter((array) ($this->pedir('GET', '/v1/products', ['page' => 1, 'page_size' => 100])['results'] ?? []), fn ($p) => $p['active'] ?? true)
            )),
        ];
    }

    public function emitir(array $documento, array $ajustes): array
    {
        $cuerpo = [
            'document' => ['id' => (int) $ajustes['numeracion_id']],
            'date'     => $documento['fecha'],
            'customer' => $this->cliente($documento['cliente']),
            'seller'   => (int) $ajustes['vendedor_id'],
            'stamp'    => ['send' => true],
            'mail'     => ['send' => (bool) ($ajustes['enviar_correo'] ?? true) && !empty($documento['cliente']['email'])],
            'observations' => mb_substr((string) ($documento['observaciones'] ?? ''), 0, 4000),
            'items'    => [$this->item($documento, $ajustes)],
            'payments' => [$this->pago($documento, $ajustes)],
        ];

        if (!empty($ajustes['centro_costo_id'])) {
            $cuerpo['cost_center'] = (int) $ajustes['centro_costo_id'];
        }

        return $this->resultado(
            $this->pedir('POST', '/v1/invoices', $cuerpo, ['Idempotency-Key' => $documento['idempotencia']]),
            'cufe'
        );
    }

    public function notaCredito(array $documento, array $original, array $ajustes): array
    {
        $cuerpo = [
            'document' => ['id' => (int) $ajustes['numeracion_nc_id']],
            'date'     => $documento['fecha'],
            'invoice'  => $original['externo_id'],
            // 2: anulación de factura electrónica (tabla de motivos de Siigo).
            'reason'   => 2,
            'observations' => mb_substr((string) ($documento['observaciones'] ?? ''), 0, 4000),
            'stamp'    => ['send' => true],
            'mail'     => ['send' => (bool) ($ajustes['enviar_correo'] ?? true) && !empty($documento['cliente']['email'])],
            'items'    => [$this->item($documento, $ajustes)],
            'payments' => [$this->pago($documento, $ajustes)],
        ];

        if (!empty($ajustes['centro_costo_id'])) {
            $cuerpo['cost_center'] = (int) $ajustes['centro_costo_id'];
        }

        return $this->resultado(
            $this->pedir('POST', '/v1/credit-notes', $cuerpo, ['Idempotency-Key' => $documento['idempotencia']]),
            'cude'
        );
    }

    public function consultar(string $externoId, string $tipo = 'factura'): array
    {
        $ruta = $tipo === 'nota_credito' ? '/v1/credit-notes/' : '/v1/invoices/';
        $r    = $this->pedir('GET', $ruta . $externoId);

        // La documentación no garantiza que la consulta traiga el sello: si no
        // viene, lo único que se puede saber es si la DIAN dejó errores.
        if (empty($r['stamp']) && $tipo === 'factura') {
            $errores = (array) ($this->pedir('GET', '/v1/invoices/' . $externoId . '/stamp/errors')['errors'] ?? []);

            if ($errores) {
                $r['stamp'] = ['status' => 'Rejected', 'errors' => implode(' ', array_column($errores, 'message'))];
            }
        }

        return $this->resultado($r, $tipo === 'nota_credito' ? 'cude' : 'cufe');
    }

    public function pdf(string $externoId, string $tipo = 'factura'): ?string
    {
        $ruta = $tipo === 'nota_credito' ? '/v1/credit-notes/' : '/v1/invoices/';
        $b64  = $this->pedir('GET', $ruta . $externoId . '/pdf')['base64'] ?? null;

        return $b64 ? (base64_decode((string) $b64, true) ?: null) : null;
    }

    // ── Traducción a lo que pide Siigo ───────────────────────────────────────

    /** Si el cliente ya existe en Siigo basta su documento; si no, viaja completo y Siigo lo crea. */
    private function cliente(array $c): array
    {
        $existe = $this->pedir('GET', '/v1/customers', ['identification' => $c['numero'], 'page_size' => 1]);

        if (!empty($existe['results'])) {
            return ['identification' => $c['numero'], 'branch_office' => 0];
        }

        $empresa = $c['persona'] === 'juridica';
        $tipos   = ['CC' => '13', 'NIT' => '31', 'CE' => '22', 'PP' => '41', 'TI' => '12', 'PPT' => '48', 'PEP' => '47'];

        $nuevo = [
            'person_type'    => $empresa ? 'Company' : 'Person',
            'id_type'        => $tipos[$c['tipo_documento']] ?? '13',
            'identification' => $c['numero'],
            'branch_office'  => 0,
            'name'           => $empresa ? [$c['razon_social']] : [$c['nombres'], $c['apellidos'] ?: $c['nombres']],
            'address'        => [
                'address' => $c['direccion'],
                'city'    => ['country_code' => 'Co', 'state_code' => substr($c['municipio'], 0, 2), 'city_code' => $c['municipio']],
            ],
            'phones'   => [['number' => $c['telefono']]],
            'contacts' => [array_filter([
                'first_name' => $c['nombres'] ?: $c['razon_social'],
                'last_name'  => $c['apellidos'] ?: ($c['nombres'] ?: $c['razon_social']),
                'email'      => $c['email'] ?: null,
            ])],
        ];

        if ($c['dv'] !== null && $c['dv'] !== '') {
            $nuevo['check_digit'] = (string) $c['dv'];
        }

        return $nuevo;
    }

    /**
     * Con IVA, el valor cobrado viaja como «taxed_price» (precio con el
     * impuesto adentro) y Siigo saca la base. Sin IVA no lleva impuestos.
     */
    private function item(array $d, array $ajustes): array
    {
        $item = [
            'code'        => (string) $ajustes['producto_id'],
            'description' => mb_substr($d['concepto'], 0, 500),
            'quantity'    => 1,
            'discount'    => 0,
        ];

        if ($d['impuesto'] > 0 && !empty($ajustes['impuesto_id'])) {
            $item['taxed_price'] = $d['total'];
            $item['taxes']       = [['id' => (int) $ajustes['impuesto_id']]];
        } else {
            $item['price'] = $d['total'];
        }

        return $item;
    }

    private function pago(array $d, array $ajustes): array
    {
        return ['id' => (int) $ajustes['forma_pago_id'], 'value' => $d['total'], 'due_date' => $d['fecha']];
    }

    /** La respuesta de Siigo, con los nombres de la plataforma. */
    private function resultado(array $r, string $campoCufe): array
    {
        $sello  = (array) ($r['stamp'] ?? []);
        $estado = (string) ($sello['status'] ?? '');
        $error  = trim(is_array($sello['errors'] ?? null) ? implode(' ', array_map('strval', $sello['errors'])) : (string) ($sello['errors'] ?? ''))
            ?: trim((string) ($sello['observations'] ?? ''));

        return [
            'estado' => match ($estado) {
                'Accepted' => 'emitida',
                'Rejected' => 'rechazada',
                default    => 'pendiente',
            },
            'externo_id'  => $r['id'] ?? null,
            'numero'      => $r['name'] ?? (isset($r['number']) ? (string) $r['number'] : null),
            'cufe'        => $sello[$campoCufe] ?? $sello['cufe'] ?? null,
            'estado_dian' => $estado ?: null,
            'pdf_url'     => $r['public_url'] ?? null,
            'qr'          => null,
            'error'       => $estado === 'Accepted' ? null : ($error ?: ($estado === 'Draft'
                ? 'Siigo guardó la factura pero no la envió a la DIAN. Envíela desde Siigo Nube y luego actualice aquí.'
                : ($estado === '' ? 'Siigo no informó el estado ante la DIAN.' : null))),
            'respuesta'   => $r,
        ];
    }

    private static function esElectronico(array $tipo): bool
    {
        return !in_array((string) ($tipo['electronic_type'] ?? 'NoElectronic'), ['', 'NoElectronic'], true);
    }

    // ── Transporte ───────────────────────────────────────────────────────────

    private function claveDelToken(): string
    {
        return 'fe:siigo:token:' . $this->companyId . ':' . md5(($this->credenciales['username'] ?? '') . ($this->credenciales['access_key'] ?? ''));
    }

    private function cabeceras(): array
    {
        return ['Partner-Id' => (string) ($this->credenciales['partner_id'] ?? ''), 'Accept' => 'application/json'];
    }

    private function token(): string
    {
        return Cache::remember($this->claveDelToken(), 82800, function () {
            try {
                $r = Http::withHeaders($this->cabeceras())->timeout(30)->post(self::BASE . '/auth', [
                    'username'   => $this->credenciales['username'] ?? '',
                    'access_key' => $this->credenciales['access_key'] ?? '',
                ]);
            } catch (ConnectionException $e) {
                throw new ErrorDelProveedor('No hubo conexión con Siigo. Intente de nuevo en unos minutos.', true);
            }

            if (!$r->successful() || empty($r->json('access_token'))) {
                throw new ErrorDelProveedor('Siigo no aceptó las credenciales: ' . $this->mensaje($r), $r->serverError());
            }

            return (string) $r->json('access_token');
        });
    }

    private function pedir(string $metodo, string $ruta, array $datos = [], array $cabeceras = []): array
    {
        try {
            $http = Http::withHeaders($this->cabeceras() + $cabeceras + ['Authorization' => $this->token()])
                // Siigo recomienda 120 s al crear comprobantes: espera a la DIAN.
                ->timeout($metodo === 'POST' ? 120 : 40);

            $r = $metodo === 'GET' ? $http->get(self::BASE . $ruta, $datos) : $http->send($metodo, self::BASE . $ruta, ['json' => $datos]);
        } catch (ConnectionException $e) {
            throw new ErrorDelProveedor('No hubo conexión con Siigo. Intente de nuevo en unos minutos.', true);
        }

        if ($r->status() === 401) {
            // El token guardado dejó de servir (le cambiaron la clave): el próximo intento pide otro.
            Cache::forget($this->claveDelToken());
        }

        if (!$r->successful()) {
            throw new ErrorDelProveedor(
                $this->mensaje($r),
                in_array($r->status(), [401, 408, 429], true) || $r->serverError(),
                (array) $r->json()
            );
        }

        return (array) $r->json();
    }

    /** {"Status":400,"Errors":[{"Code","Message","Params"}]} dicho para una persona. */
    private function mensaje(Response $r): string
    {
        $conocidos = [
            'invalid_dian_resolution' => 'La resolución de facturación de la DIAN está vencida o se agotó el rango. Actualícela en Siigo.',
            'invalid_total_payments'  => 'El valor del pago no coincide con el total de la factura calculado por Siigo.',
            'customer_settings'       => 'Al cliente le faltan datos en Siigo (revise que tenga un contacto con correo).',
            'company_settings'        => 'A la empresa le falta configuración en Siigo para facturar electrónicamente.',
            'invalid_partner_id'      => 'El Partner-Id no está registrado en Siigo. Revíselo en la credencial API.',
            'header_required'         => 'Falta el Partner-Id de la credencial API de Siigo.',
            'requests_limit'          => 'Siigo limitó las peticiones por minuto. Se reintenta más tarde.',
            'unauthorized'            => 'Siigo rechazó el acceso: revise el usuario y la access key.',
            'invalid_date'            => 'Siigo no acepta esa fecha: la factura electrónica no puede tener fecha anterior a hoy.',
        ];

        $errores = (array) ($r->json('Errors') ?? $r->json('errors') ?? []);
        $partes  = [];

        foreach ($errores as $e) {
            $codigo   = (string) ($e['Code'] ?? $e['code'] ?? '');
            $partes[] = $conocidos[$codigo] ?? trim((string) ($e['Message'] ?? $e['message'] ?? $codigo));
        }

        return $partes ? implode(' ', array_unique($partes)) : ('Siigo respondió con el código ' . $r->status() . '.');
    }
}

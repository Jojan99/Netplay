<?php

namespace App\Services\Crm;

use App\Models\PaymentProof;
use App\Services\WaBotService;
use App\Support\IdentificacionEnTexto;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Registra en la plataforma los comprobantes que llegan por WhatsApp Web.
 *
 * Antes estos comprobantes se reenviaban a un grupo de WhatsApp y ahí morían:
 * nunca quedaban en el sistema, así que el módulo de auditoría solo veía los
 * del bot de Meta. Como los clientes siguen mandando sus soportes al número de
 * WhatsApp Web, la auditoría estaba mirando el canal equivocado.
 *
 * Este servicio toma el archivo que ya descargó el servicio Node, lo guarda en
 * la plataforma, le pasa el OCR (el mismo que usa el bot de Meta, para que los
 * dos orígenes tengan la misma calidad de dato) y crea el registro con el
 * cliente ya vinculado.
 */
class ComprobanteWhatsAppWeb
{
    public function __construct(private WaBotService $bot) {}

    /**
     * @param  array{company_id:int, phone:string, dni?:?string, media_url?:?string,
     *               caption?:?string, filename?:?string, mime?:?string}  $datos
     * @return array{ok:bool, motivo?:string, proof_id?:int, cliente?:string}
     */
    public function registrar(array $datos): array
    {
        $companyId = (int) $datos['company_id'];
        $phone     = (string) ($datos['phone'] ?? '');
        $dni       = $datos['dni'] ?? null;

        $cliente = $this->resolverCliente($companyId, $dni, $phone);

        if (!$cliente) {
            return ['ok' => false, 'motivo' => 'cliente_no_encontrado'];
        }

        $archivo = $this->guardarArchivo($companyId, $datos['media_url'] ?? null, $datos['filename'] ?? null);

        if (!$archivo['path']) {
            return ['ok' => false, 'motivo' => 'sin_archivo'];
        }

        // Mismo archivo mandado dos veces: no se duplica el registro.
        if ($archivo['hash']) {
            $repetido = PaymentProof::where('company_id', $companyId)
                ->where('file_hash', $archivo['hash'])
                ->first();

            if ($repetido) {
                // Devolver el cliente también acá: sin esto el bot respondía
                // "lo registramos a nombre de undefined".
                return [
                    'ok'       => true,
                    'proof_id' => (int) $repetido->id,
                    'motivo'   => 'ya_registrado',
                    'cliente'  => trim($cliente->names . ' ' . $cliente->lastname),
                ];
            }
        }

        $ocr = $this->bot->extractTextFromProof($archivo['ruta_local']) ?? '';
        $texto = trim(($datos['caption'] ?? '') . "\n" . $ocr);
        $detalle = $this->bot->extractPaymentProofDetails($texto);

        $referencia = $detalle['reference'] ?: ($detalle['invoice_number'] ?? null);

        // La referencia tiene índice único por empresa: dos comprobantes con la
        // misma referencia son el mismo pago. Antes el insert reventaba con un
        // 500 y el servicio no sabía qué había pasado.
        if ($referencia) {
            $mismaRef = PaymentProof::where('company_id', $companyId)
                ->where('reference_number', $referencia)
                ->first();

            if ($mismaRef) {
                return [
                    'ok'       => true,
                    'proof_id' => (int) $mismaRef->id,
                    'motivo'   => 'ya_registrado',
                    'cliente'  => trim($cliente->names . ' ' . $cliente->lastname),
                ];
            }
        }

        $factura = $this->facturaPendiente($companyId, (int) $cliente->user_id);

        $proof = PaymentProof::create([
            'company_id'       => $companyId,
            'source'           => 'whatsapp_web',
            'user_id'          => $cliente->user_id,
            'invoice_id'       => $factura?->id,
            'file_path'        => $archivo['path'],
            'file_name'        => $archivo['nombre'],
            'file_hash'        => $archivo['hash'],
            'reported_amount'  => $detalle['amount'] ?? null,
            'detected_amount'  => $detalle['amount'] ?? null,
            'payment_date'     => $detalle['date'] ?? null,
            'reference_number' => $referencia,
            'bank_name'        => $detalle['bank_name'] ?? null,
            'ocr_text'         => $ocr ?: null,
            'status'           => 'pending',
            'raw_payload'      => [
                'origen'   => 'whatsapp_web',
                'phone'    => $phone,
                'dni'      => $cliente->dni,
                'caption'  => $datos['caption'] ?? null,
                'media_url'=> $datos['media_url'] ?? null,
            ],
        ]);

        Log::info('[Comprobante WhatsApp Web] Registrado', [
            'proof_id'   => $proof->id,
            'company_id' => $companyId,
            'user_id'    => $cliente->user_id,
            'referencia' => $proof->reference_number,
        ]);

        self::avisar($companyId, trim($cliente->names . ' ' . $cliente->lastname), $proof, 'WhatsApp Web');

        return [
            'ok'       => true,
            'proof_id' => (int) $proof->id,
            'cliente'  => trim($cliente->names . ' ' . $cliente->lastname),
        ];
    }

    /**
     * Avisa que entró un comprobante, al destino que la empresa eligió en
     * Avisos y destinos. El comprobante queda esperando revisión: si nadie se
     * entera, el cliente pagó y nadie lo aplica.
     */
    public static function avisar(int $companyId, string $cliente, PaymentProof $proof, string $origen): void
    {
        try {
            \App\Services\Avisos\MensajeDeAviso::nuevo('Comprobante de pago recibido', $companyId, '🧾')
                ->dato('Cliente', $cliente)
                ->dinero('Valor', $proof->reported_amount)
                ->dato('Banco', $proof->bank_name)
                ->dato('Referencia', $proof->reference_number)
                ->fecha('Fecha del pago', $proof->payment_date, false)
                ->dato('Llegó por', $origen)
                ->fecha('Recibido', now())
                ->cierre('Queda pendiente de revisión en Comprobantes.')
                ->enviar('comprobante_pago');
        } catch (\Throwable $e) {
            Log::warning('[Comprobante] No se pudo avisar', ['empresa' => $companyId, 'error' => $e->getMessage()]);
        }
    }

    /* ── Cliente ─────────────────────────────────────────────────────────── */

    /**
     * Por cédula si la dieron; si no, por teléfono.
     *
     * Cuando viene una cédula NO se cae al teléfono: el bot se la pidió
     * expresamente, así que si no existe hay que decirlo. Caer al dueño del
     * número acreditaría el pago a la persona equivocada, que es justo lo que
     * este flujo viene a evitar.
     */
    private function resolverCliente(int $companyId, ?string $dni, string $phone): ?object
    {
        if ($dni) {
            foreach (IdentificacionEnTexto::numerosPosibles($dni) ?: [$dni] as $posible) {
                $c = DB::table('user_data as ud')
                    ->join('users as u', 'u.id', '=', 'ud.user_id')
                    ->where('u.company_id', $companyId)
                    ->where('ud.dni', $posible)
                    ->first(['ud.user_id', 'ud.dni', 'ud.names', 'ud.lastname']);

                if ($c) {
                    return $c;
                }
            }

            return null;
        }

        $corto = substr(preg_replace('/\D/', '', $phone), -10);

        if (strlen($corto) < 7) {
            return null;
        }

        return DB::table('user_data as ud')
            ->join('users as u', 'u.id', '=', 'ud.user_id')
            ->where('u.company_id', $companyId)
            ->whereRaw('RIGHT(REGEXP_REPLACE(ud.phone, "[^0-9]", ""), 10) = ?', [$corto])
            ->first(['ud.user_id', 'ud.dni', 'ud.names', 'ud.lastname']);
    }

    /** La factura pendiente más vieja: es la que el cliente suele estar pagando. */
    private function facturaPendiente(int $companyId, int $userId): ?object
    {
        return DB::table('det_facturations as d')
            ->join('cab_facturations as cab', 'cab.id', '=', 'd.cab_id')
            ->where('cab.company_id', $companyId)
            ->where('cab.user_id', $userId)
            ->where('d.paid', 0)
            ->orderBy('d.date_facturation')
            ->first(['d.id']);
    }

    /* ── Archivo ─────────────────────────────────────────────────────────── */

    /**
     * Baja el archivo que ya tiene el servicio Node y lo deja en la plataforma.
     *
     * @return array{path:?string, ruta_local:?string, nombre:?string, hash:?string}
     */
    private function guardarArchivo(int $companyId, ?string $url, ?string $nombre): array
    {
        $vacio = ['path' => null, 'ruta_local' => null, 'nombre' => $nombre, 'hash' => null];

        if (!$url) {
            return $vacio;
        }

        try {
            $respuesta = Http::timeout(30)->get($url);

            if (!$respuesta->successful()) {
                Log::warning('[Comprobante WhatsApp Web] No se pudo bajar el archivo', [
                    'url' => $url, 'status' => $respuesta->status(),
                ]);
                return $vacio;
            }

            $contenido = $respuesta->body();

            if ($contenido === '') {
                return $vacio;
            }

            $extension = pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION) ?: 'jpg';
            $nombre    = $nombre ?: ('comprobante_' . now()->format('YmdHis') . '.' . $extension);
            $path      = "payment-proofs/{$companyId}/" . uniqid('wa_', true) . '.' . $extension;

            Storage::disk('public')->put($path, $contenido);

            return [
                // URL completa, igual que el flujo de Meta: el panel muestra la
                // evidencia usando este campo tal cual, y con una ruta relativa
                // la imagen no cargaba.
                'path'       => url('/storage/' . $path),
                'ruta_local' => storage_path('app/public/' . $path),
                'nombre'     => $nombre,
                'hash'       => hash('sha256', $contenido),
            ];
        } catch (\Throwable $e) {
            Log::warning('[Comprobante WhatsApp Web] Error guardando el archivo', [
                'url' => $url, 'error' => $e->getMessage(),
            ]);
            return $vacio;
        }
    }
}

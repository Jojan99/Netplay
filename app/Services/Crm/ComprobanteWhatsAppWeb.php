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
                // Devolver el cliente también aquí: sin esto el bot respondía
                // "lo registramos a nombre de undefined".
                return [
                    'ok'       => true,
                    'proof_id' => (int) $repetido->id,
                    'viejo'    => \App\Services\Comprobantes\ComprobanteConfiable::esViejo($repetido),
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
                    'viejo'    => \App\Services\Comprobantes\ComprobanteConfiable::esViejo($mismaRef),
                    'motivo'   => 'ya_registrado',
                    'cliente'  => trim($cliente->names . ' ' . $cliente->lastname),
                ];
            }
        }

        $factura = $this->facturaPendiente($companyId, (int) $cliente->user_id);

        $proof = PaymentProof::create([
            'company_id'       => $companyId,
            'source'           => 'whatsapp_web',
            'wa_linea_id'      => $datos['wa_linea_id'] ?? null,
            'user_id'          => $cliente->user_id,
            'invoice_id'       => $factura?->id,
            'file_path'        => $archivo['path'],
            'file_name'        => $archivo['nombre'],
            'file_hash'        => $archivo['hash'],
            'reported_amount'  => $detalle['amount'] ?? null,
            'detected_amount'  => $detalle['amount'] ?? null,
            // El lector la devuelve como «payment_date»: con «date» se perdía siempre.
            'payment_date'     => $detalle['payment_date'] ?? null,
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

        // Si el comprobante no tiene nada raro, se aplica solo.
        //
        // Esto ya existía pero sólo en el camino del bot de Meta, y todo el
        // tráfico real entra por aquí: por eso los pagos quedaban esperando
        // aprobación a mano aunque estuvieran perfectos.
        $aplicado = false;

        try {
            // «solo_revision»: lo registra una persona a mano (un comprobante que el bot dejó sin
            // atender). Queda esperando autorización; no se aplica solo.
            $aplicado = empty($datos['solo_revision'])
                && (app(\App\Http\Controllers\PaymentProofController::class)
                    ->aplicarSiEsConfiable($proof)['aplicado'] ?? false);
        } catch (\Throwable $e) {
            // Que no se pueda aplicar solo no puede perder el comprobante:
            // queda registrado y alguien lo revisa.
            Log::warning('[Comprobante WhatsApp Web] No se pudo aplicar solo', [
                'proof_id' => $proof->id, 'error' => $e->getMessage(),
            ]);
        }

        Log::info('[Comprobante WhatsApp Web] Registrado', [
            'proof_id'   => $proof->id,
            'company_id' => $companyId,
            'user_id'    => $cliente->user_id,
            'referencia' => $proof->reference_number,
            'aplicado_solo' => $aplicado,
        ]);

        self::avisar($companyId, trim($cliente->names . ' ' . $cliente->lastname), $proof->fresh(), 'WhatsApp Web');

        return [
            'ok'       => true,
            'proof_id' => (int) $proof->id,
            'aplicado' => $aplicado,
            // Pago de hace más de tres días: al cliente NO se le dice que quedó registrado (puede
            // ser un comprobante viejo reenviado) y no va al grupo de reporte de pagos.
            'viejo'    => !$aplicado && \App\Services\Comprobantes\ComprobanteConfiable::esViejo($proof->fresh() ?? $proof),
            'dias'     => \App\Services\Comprobantes\ComprobanteConfiable::DIAS_DE_GRACIA,
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
            // Aplicado solo o para revisión: que en el grupo se distinga de un vistazo, con el porqué.
            $aplicado = in_array($proof->status, ['approved', 'auto_approved'], true);
            $motivos  = $aplicado ? [] : \App\Services\Comprobantes\ComprobanteConfiable::revisar($proof)['motivos'];
            $viejo    = \App\Services\Comprobantes\ComprobanteConfiable::esViejo($proof);

            // Un comprobante con más de tres días no se manda al grupo de reporte de pagos: casi
            // siempre es un reenvío y llenaba el grupo de avisos que nadie tenía que atender ahí.
            // No se pierde: queda para revisión en la pantalla de Comprobantes, con el motivo.
            if ($viejo && !$aplicado) {
                Log::info('[Comprobante] Viejo: no se avisa al grupo, queda en revisión', ['empresa' => $companyId, 'proof' => $proof->id, 'fecha_del_pago' => (string) $proof->payment_date]);

                return;
            }

            \App\Services\Avisos\MensajeDeAviso::nuevo(
                $aplicado ? 'Comprobante de pago aplicado' : ($viejo ? 'Comprobante viejo: REVISAR' : 'Comprobante de pago para revisión'),
                $companyId,
                $aplicado ? '✅' : ($viejo ? '⚠️' : '🧾')
            )
                ->dato('Cliente', $cliente)
                ->dinero('Valor', $proof->reported_amount)
                ->dato('Banco', $proof->bank_name)
                ->dato('Referencia', $proof->reference_number)
                ->fecha('Fecha del pago', $proof->payment_date, false)
                ->dato('Llegó por', $origen)
                ->dato('Al WhatsApp', self::lineaQueLoRecibio($proof))
                ->fecha('Recibido', now())
                ->cierre($aplicado
                    ? 'Pasó la revisión y se aplicó solo a la factura.'
                    : 'Va a revisión en Comprobantes' . ($motivos ? ': ' . implode('; ', $motivos) . '.' : '.'))
                ->adjunto(self::direccionDelArchivo($proof), $proof->file_name)
                ->enviar('comprobante_pago');
        } catch (\Throwable $e) {
            Log::warning('[Comprobante] No se pudo avisar', ['empresa' => $companyId, 'error' => $e->getMessage()]);
        }
    }

    /** A qué línea de WhatsApp de la empresa le escribió el cliente: su nombre y su número. */
    private static function lineaQueLoRecibio(PaymentProof $proof): ?string
    {
        if (!$proof->wa_linea_id) {
            return null;
        }

        $linea = \Illuminate\Support\Facades\DB::table('wa_lineas')->where('id', $proof->wa_linea_id)->first(['nombre', 'telefono']);

        if (!$linea) {
            return null;
        }

        $numero = preg_replace('/\D/', '', (string) $linea->telefono);
        // 573103398607 → 310 339 8607: el indicativo de Colombia no aporta al leerlo en el grupo.
        $numero = preg_replace('/^57(?=\d{10}$)/', '', (string) $numero);
        $numero = strlen((string) $numero) === 10 ? substr($numero, 0, 3) . ' ' . substr($numero, 3, 3) . ' ' . substr($numero, 6) : $numero;

        return trim(($linea->nombre ?: 'Línea') . ($numero ? ' (' . $numero . ')' : ''));
    }

    /** La dirección pública del archivo del comprobante, para mandarlo junto al aviso. */
    private static function direccionDelArchivo(PaymentProof $proof): ?string
    {
        $ruta = trim((string) $proof->file_path);

        if ($ruta === '') {
            return null;
        }

        return preg_match('#^https?://#i', $ruta) ? $ruta : url('storage/' . ltrim($ruta, '/'));
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
            ->where('d.paid', 0)->whereNull('d.anulada_en')
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

<?php

namespace App\Http\Controllers;

use App\Models\DetFacturation;
use App\UseCases\GeneratePdf\Interfaces\GeneratePdfByIdFacturesUseCaseInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * La factura de un cliente, abierta desde un botón de WhatsApp.
 *
 * La ruta que se usaba hasta ahora pide el número de factura y nada más
 * (/api/generatePdf/generatePdfbyId/NT16533). Los números son correlativos, así
 * que cualquiera podía bajarse las facturas de todos los clientes probando
 * NT16000, NT16001… y en una factura van el nombre, la dirección y el plan.
 *
 * Aquí el enlace lleva su propia firma: sin ella no abre, y no se puede
 * fabricar sin conocer APP_KEY.
 */
class InvoiceLinkController extends Controller
{
    /** Longitud de la firma. 24 caracteres hex son 96 bits: no se adivina. */
    private const FIRMA_LEN = 24;

    public function show(
        Request $request,
        GeneratePdfByIdFacturesUseCaseInterface $generador,
        string $token
    ) {
        $factura = $this->facturaDe($token);

        if (!$factura) {
            Log::warning('Factura por enlace: token inválido', ['ip' => $request->ip()]);

            return response()->view('payment.link_message', [
                'message' => 'Este enlace no es válido o ya venció. Escríbenos y te mandamos tu factura.',
            ], 404);
        }

        try {
            return $generador->generatePdfByIdFacture($factura->number_facture);
        } catch (\Throwable $e) {
            Log::error('Factura por enlace: no se pudo generar el PDF', [
                'invoice_id' => $factura->id,
                'error'      => $e->getMessage(),
            ]);

            return response()->view('payment.link_message', [
                'message' => 'No pudimos abrir tu factura en este momento. Intenta de nuevo en unos minutos.',
            ], 200);
        }
    }

    /**
     * El token que se manda en el botón de la plantilla.
     *
     * Lleva el id y su firma en una sola cadena porque Meta solo admite una
     * variable al final de la URL del botón.
     */
    public static function tokenFor(int $invoiceId): string
    {
        return $invoiceId . '-' . self::firma($invoiceId);
    }

    /** URL completa, lista para el botón o para mandar por chat. */
    public static function urlFor(int $invoiceId): string
    {
        return url('/api/factura/' . self::tokenFor($invoiceId));
    }

    // ─── Interno ─────────────────────────────────────────────────────────────

    private function facturaDe(string $token): ?DetFacturation
    {
        if (!preg_match('/^(\d+)-([a-f0-9]{' . self::FIRMA_LEN . '})$/', $token, $m)) {
            return null;
        }

        $id = (int) $m[1];

        // Comparación en tiempo constante: comparar firmas con === deja medir
        // cuántos caracteres acertó quien las prueba.
        if (!hash_equals(self::firma($id), $m[2])) {
            return null;
        }

        return DetFacturation::find($id);
    }

    private static function firma(int $invoiceId): string
    {
        return substr(
            hash_hmac('sha256', 'factura-link:' . $invoiceId, (string) config('app.key')),
            0,
            self::FIRMA_LEN
        );
    }
}

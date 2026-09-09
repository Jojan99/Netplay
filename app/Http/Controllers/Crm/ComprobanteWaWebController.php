<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\Crm\ComprobanteWhatsAppWeb;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Entrada de comprobantes desde el servicio de WhatsApp Web.
 *
 * Lo llama el servicio Node máquina a máquina cuando detecta un comprobante y
 * ya sabe de qué cliente es, así que va firmado con la clave maestra y no con
 * una sesión de usuario.
 */
class ComprobanteWaWebController extends Controller
{
    public function __construct(private ComprobanteWhatsAppWeb $servicio) {}

    /** POST /api/management/wa-proof */
    public function store(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'instanceId' => 'nullable|string|max:64',
            'company_id' => 'nullable|integer',
            'phone'      => 'required|string|max:32',
            'dni'        => 'nullable|string|max:64',
            'media_url'  => 'required|url|max:1000',
            'filename'   => 'nullable|string|max:180',
            'caption'    => 'nullable|string|max:2000',
        ]);

        $companyId = $datos['company_id'] ?? null;

        if (!$companyId && !empty($datos['instanceId'])) {
            $companyId = Company::where('wa_instance_id', $datos['instanceId'])->value('id');
        }

        if (!$companyId) {
            return response()->json(['ok' => false, 'motivo' => 'empresa_no_resuelta'], 422);
        }

        $resultado = $this->servicio->registrar($datos + ['company_id' => (int) $companyId]);

        return response()->json($resultado, $resultado['ok'] ? 200 : 422);
    }
}

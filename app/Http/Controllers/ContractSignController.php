<?php

namespace App\Http\Controllers;

use App\Repositories\Interfaces\ContractRepositoryInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

class ContractSignController extends Controller
{
    public function __construct(
        private ContractRepositoryInterface $contractRepository
    ) {}

    /**
     * Muestra la página de firma al cliente.
     * URL: /contrato/firmar/{token}
     */
    public function show(string $token)
    {
        try {
            $clientContract = $this->contractRepository->getByToken($token);
        } catch (ModelNotFoundException) {
            abort(404, 'Contrato no encontrado.');
        }

        $logo = $clientContract->contract->logo ?? '';
        $pdfUrl = null;

        // Construir valores de variables (HTML + PDF)
        $uc = new \App\UseCases\Contract\ClientContractUseCase($this->contractRepository);
        $fieldValues = $uc->buildFieldValues(
            $clientContract->user_id,
            $clientContract->id,
            $clientContract->contract->installation_value ?? null
        );

        // Reemplazar variables en el contenido HTML del contrato
        $content = str_replace(
            array_keys($fieldValues),
            array_values($fieldValues),
            $clientContract->contract->content ?? ''
        );
        // Escapar cualquier {{ restante para que Blade no lo interprete como PHP
        $content = str_replace('{{', '@{{', $content);
        $clientContract->contract->content = $content;

        if ($clientContract->contract->pdf_path) {
            // Si ya está firmado, mostrar el PDF firmado permanente si existe
            if ($clientContract->status === 'signed') {
                $signedPath = storage_path("app/public/contracts/signed/contract_{$clientContract->id}_signed.pdf");
                if (file_exists($signedPath)) {
                    $pdfUrl = url("storage/contracts/signed/contract_{$clientContract->id}_signed.pdf");
                }
            }

            // Si no hay PDF firmado, generar preview rellenado (sin página de firma)
            if (!$pdfUrl) {
                $fullPath = storage_path('app/public/' . $clientContract->contract->pdf_path);
                if (file_exists($fullPath)) {
                    $pdfFields = \App\Models\ContractPdfField::where('contract_id', $clientContract->contract_id)
                        ->orderBy('page')->orderBy('id')
                        ->get();

                    try {
                        $service = new \App\Services\ContractPdfService();
                        $output = $service->fillPdfBase(
                            $clientContract->contract->pdf_path,
                            $fieldValues,
                            $pdfFields->toArray()
                        );

                        $tempName = 'contract_filled_' . $clientContract->id . '.pdf';
                        $tempPath = 'temp/' . $tempName;
                        $fullTempPath = storage_path('app/public/' . $tempPath);
                        if (!is_dir(dirname($fullTempPath))) {
                            mkdir(dirname($fullTempPath), 0755, true);
                        }
                        file_put_contents($fullTempPath, $output);
                        $pdfUrl = url('storage/' . $tempPath);
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::error('Error generando PDF rellenado para firma: ' . $e->getMessage());
                        $pdfUrl = url('storage/' . $clientContract->contract->pdf_path);
                    }
                }
            }
        }
        return view('contract_sign', compact('clientContract', 'token', 'logo', 'pdfUrl'));
    }

    /**
     * Recibe la firma desde el canvas (sin JWT — autenticado por token).
     * POST: /api/contracts/sign-token/{token}
     */
    public function sign(string $token, Request $request)
    {
        try {
            $cc = $this->contractRepository->getByToken($token);

            if ($cc->status === 'signed') {
                return response()->json(['message' => 'El contrato ya fue firmado', 'status' => 1]);
            }

            if (!$request->filled('signature')) {
                return response()->json(['message' => 'La firma es requerida', 'status' => 1]);
            }

            $this->contractRepository->sign($cc->id, $request->input('signature'));

            // ── Generar PDF firmado permanentemente ──────────────────────────────
            $logCtx = ['client_contract_id' => $cc->id, 'company_id' => $cc->company_id];

            if ($cc->contract->pdf_path) {
                try {
                    \Illuminate\Support\Facades\Log::info('[ContractSign] Iniciando post-firma PDF/WhatsApp', $logCtx);

                    $uc = new \App\UseCases\Contract\ClientContractUseCase($this->contractRepository);
                    $fieldValues = $uc->buildFieldValues(
                        $cc->user_id,
                        $cc->id,
                        $cc->contract->installation_value ?? null
                    );

                    $pdfFields = \App\Models\ContractPdfField::where('contract_id', $cc->contract_id)
                        ->orderBy('page')->orderBy('id')
                        ->get();

                    $service = new \App\Services\ContractPdfService();
                    $output = $service->combineWithSignature(
                        $cc->contract->pdf_path,
                        $request->input('signature'),
                        $cc->user->username ?? 'Cliente',
                        $fieldValues,
                        $pdfFields->toArray()
                    );

                    $signedPath = "contracts/signed/contract_{$cc->id}_signed.pdf";
                    $fullSignedPath = storage_path('app/public/' . $signedPath);
                    if (!is_dir(dirname($fullSignedPath))) {
                        mkdir(dirname($fullSignedPath), 0755, true);
                    }
                    file_put_contents($fullSignedPath, $output);
                    $signedUrl = url('storage/' . $signedPath);

                    \Illuminate\Support\Facades\Log::info('[ContractSign] PDF firmado generado', array_merge($logCtx, ['signed_path' => $signedPath, 'signed_url' => $signedUrl]));

                    // ── Enviar WhatsApp con PDF adjunto ──────────────────────────────
                    $ud = \Illuminate\Support\Facades\DB::table('user_data')
                        ->where('user_id', $cc->user_id)
                        ->first();

                    $rawPhone = $ud->phone ?? '';
                    $phone = preg_replace('/\D/', '', $rawPhone);

                    \Illuminate\Support\Facades\Log::info('[ContractSign] Teléfono cliente', array_merge($logCtx, ['raw_phone' => $rawPhone, 'normalized' => $phone]));

                    if (preg_match('/^(57\d{10}|3\d{9})$/', $phone)) {
                        try {
                            // USAR company_id del contrato porque este endpoint no tiene JWT/session
                            $wa = new \App\Services\WhatsAppService($cc->company_id);
                            $waResponse = $wa->sendDocument(
                                $phone,
                                $signedUrl,
                                "contrato-{$cc->id}-firmado.pdf",
                                "¡Gracias por firmar! Aquí tienes tu contrato firmado."
                            );

                            if (isset($waResponse['error']) || ($waResponse['success'] ?? true) === false) {
                                $apiMsg = $waResponse['message'] ?? $waResponse['error'] ?? 'Error desconocido WA';
                                \Illuminate\Support\Facades\Log::warning('[ContractSign] WhatsApp post-firma respondió error', array_merge($logCtx, [
                                    'phone' => $phone,
                                    'wa_response' => $waResponse,
                                    'api_msg' => $apiMsg,
                                ]));
                            } else {
                                \Illuminate\Support\Facades\Log::info('[ContractSign] WhatsApp post-firma enviado exitosamente', array_merge($logCtx, [
                                    'phone' => $phone,
                                    'wa_response' => $waResponse,
                                ]));
                            }
                        } catch (\Throwable $waErr) {
                            \Illuminate\Support\Facades\Log::error('[ContractSign] Excepción enviando WA post-firma: ' . $waErr->getMessage(), $logCtx);
                        }
                    } else {
                        \Illuminate\Support\Facades\Log::warning('[ContractSign] Teléfono inválido, no se envía WhatsApp', array_merge($logCtx, ['raw_phone' => $rawPhone, 'normalized' => $phone]));
                    }
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::error('[ContractSign] Error post-firma (PDF/WhatsApp): ' . $e->getMessage(), $logCtx);
                }
            } else {
                \Illuminate\Support\Facades\Log::warning('[ContractSign] Contrato sin PDF base, no se genera PDF firmado', $logCtx);
            }

            return response()->json(['message' => 'Contrato firmado exitosamente', 'status' => 0]);

        } catch (ModelNotFoundException) {
            return response()->json(['message' => 'Contrato no encontrado', 'status' => 1], 404);
        }
    }
}

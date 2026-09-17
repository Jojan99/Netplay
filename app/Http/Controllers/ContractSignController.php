<?php

namespace App\Http\Controllers;

use App\Models\ClientContract;
use App\Repositories\Interfaces\ContractRepositoryInterface;
use App\Support\ArchivosContrato;
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

        // Reemplazar variables en el contenido HTML del contrato. Los valores
        // son datos del cliente: se escapan para que no metan HTML en la página.
        $content = str_replace(
            array_keys($fieldValues),
            array_map(fn ($v) => e((string) $v), array_values($fieldValues)),
            $clientContract->contract->content ?? ''
        );
        // Escapar cualquier {{ restante para que Blade no lo interprete como PHP
        $content = str_replace('{{', '@{{', $content);
        $clientContract->contract->content = $content;

        if ($clientContract->contract->pdf_path) {
            // Firmado: el PDF firmado, servido por la ruta protegida con el token.
            if ($clientContract->status === 'signed' && ArchivosContrato::firmado((int) $clientContract->id)) {
                $pdfUrl = ArchivosContrato::urlPorToken($token, 'firmado');
            }

            // Sin firmado: vista previa rellenada, generada al pedirla. Antes se
            // dejaba en storage/app/public/temp con nombre adivinable y datos del cliente.
            if (!$pdfUrl && file_exists(storage_path('app/public/' . $clientContract->contract->pdf_path))) {
                $pdfUrl = ArchivosContrato::urlPorToken($token, 'preview');
            }
        }

        // Documentos, por la ruta protegida con el token
        $documentFrontUrl = ArchivosContrato::archivo($clientContract, 'frente') ? ArchivosContrato::urlPorToken($token, 'frente') : null;
        $documentBackUrl  = ArchivosContrato::archivo($clientContract, 'reverso') ? ArchivosContrato::urlPorToken($token, 'reverso') : null;
        $clientDni = $fieldValues['{{dni}}'] ?? '';

        return view('contract_sign', compact(
            'clientContract', 'token', 'logo', 'pdfUrl',
            'documentFrontUrl', 'documentBackUrl', 'clientDni'
        ));
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

            // ── Validación de documentos si se requieren ─────────────────────────
            if ($cc->require_documents) {
                // Si ya tiene documentos guardados previamente, solo verificar firma
                $hasFront = (bool) ArchivosContrato::absoluta($cc->document_front_path);
                $hasBack  = (bool) ArchivosContrato::absoluta($cc->document_back_path);

                if (!$hasFront || !$hasBack) {
                    // Aceptar documentos en este request si aún no están guardados
                    $frontData = $request->input('document_front');
                    $backData  = $request->input('document_back');

                    if (!$frontData || !$backData) {
                        return response()->json(['message' => 'Se requieren fotos de ambas caras del documento de identidad.', 'status' => 1]);
                    }

                    // Validar que sean imágenes base64 válidas
                    if (!$this->isValidImageBase64($frontData) || !$this->isValidImageBase64($backData)) {
                        return response()->json(['message' => 'Las fotos del documento deben ser imágenes válidas (JPG/PNG).', 'status' => 1]);
                    }

                    // Guardar documentos en la carpeta privada (no se sirve en /storage)
                    $companyId = $cc->company_id;
                    $dir = ArchivosContrato::dirDocumentos((int) $companyId);
                    $fullDir = storage_path('app/' . $dir);
                    if (!is_dir($fullDir)) {
                        mkdir($fullDir, 0750, true);
                    }
                    $updateData = [];

                    $frontExt = $this->getBase64ImageExtension($frontData) ?: 'jpg';
                    $frontName = "cc_{$cc->id}_front_" . uniqid() . '.' . $frontExt;
                    $frontPath = $dir . '/' . $frontName;
                    file_put_contents(storage_path('app/' . $frontPath), $this->extractBase64Image($frontData));
                    $updateData['document_front_path'] = $frontPath;

                    $backExt = $this->getBase64ImageExtension($backData) ?: 'jpg';
                    $backName = "cc_{$cc->id}_back_" . uniqid() . '.' . $backExt;
                    $backPath = $dir . '/' . $backName;
                    file_put_contents(storage_path('app/' . $backPath), $this->extractBase64Image($backData));
                    $updateData['document_back_path'] = $backPath;

                    // Número de documento: usar el DNI registrado del cliente directamente
                    $clientDni = \Illuminate\Support\Facades\DB::table('user_data')
                        ->where('user_id', $cc->user_id)
                        ->value('dni') ?? '';

                    $updateData['document_number_front'] = $clientDni;
                    $updateData['document_number_back']  = $clientDni;

                    $cc->update($updateData);
                    $cc->refresh();
                }
            }

            $this->contractRepository->sign($cc->id, $request->input('signature'));
            $cc->refresh();

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
                        $pdfFields->toArray(),
                        $cc->document_front_path,
                        $cc->document_back_path
                    );

                    // PDF firmado en carpeta privada. A WhatsApp va un enlace firmado
                    // que vence en 7 días (antes era un /storage/ público y adivinable).
                    $signedPath = ArchivosContrato::guardarFirmado((int) $cc->id, $output);
                    $signedUrl = ArchivosContrato::urlFirmada((int) $cc->id, 'firmado', now()->addDays(7));

                    \Illuminate\Support\Facades\Log::info('[ContractSign] PDF firmado generado', array_merge($logCtx, ['signed_path' => $signedPath]));

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

    /**
     * GET /api/contracts/archivo/{clientContract}/{tipo}  (firma temporal)
     * PDF firmado o fotos de la cédula. La URL la genera el panel (JWT y empresa
     * ya validados) o el envío por WhatsApp; sin firma válida no abre.
     */
    public function archivo(int $clientContract, string $tipo)
    {
        $cc = ClientContract::find($clientContract);

        return $this->entregar($cc ? ArchivosContrato::archivo($cc, $tipo) : null, $tipo, $clientContract);
    }

    /**
     * GET /api/contracts/archivo-token/{token}/{tipo}
     * Lo mismo para la página de firma, protegido por el token del contrato.
     * 'preview' genera el PDF rellenado al vuelo, sin dejarlo en disco.
     */
    public function archivoPorToken(string $token, string $tipo)
    {
        try {
            $cc = $this->contractRepository->getByToken($token);
        } catch (ModelNotFoundException) {
            abort(404);
        }

        if ($tipo !== 'preview') {
            return $this->entregar(ArchivosContrato::archivo($cc, $tipo), $tipo, (int) $cc->id);
        }

        $pdfPath = $cc->contract->pdf_path ?? null;
        if (!$pdfPath || !file_exists(storage_path('app/public/' . $pdfPath))) {
            abort(404);
        }

        try {
            $uc = new \App\UseCases\Contract\ClientContractUseCase($this->contractRepository);
            $fieldValues = $uc->buildFieldValues($cc->user_id, $cc->id, $cc->contract->installation_value ?? null);
            $pdfFields = \App\Models\ContractPdfField::where('contract_id', $cc->contract_id)
                ->orderBy('page')->orderBy('id')
                ->get();

            $output = (new \App\Services\ContractPdfService())->fillPdfBase($pdfPath, $fieldValues, $pdfFields->toArray());
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Error generando PDF rellenado para firma: ' . $e->getMessage());
            // Plantilla sin datos del cliente
            return $this->entregar(storage_path('app/public/' . $pdfPath), 'preview', (int) $cc->id);
        }

        return response($output, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="contrato-' . $cc->id . '.pdf"',
            'Cache-Control'       => 'private, no-store',
        ]);
    }

    private function entregar(?string $ruta, string $tipo, int $id)
    {
        if (!$ruta || !is_file($ruta)) {
            abort(404);
        }

        $nombre = $tipo === 'firmado' || $tipo === 'preview'
            ? "contrato-{$id}" . ($tipo === 'firmado' ? '-firmado' : '') . '.pdf'
            : "documento-{$id}-{$tipo}." . strtolower(pathinfo($ruta, PATHINFO_EXTENSION));

        return response()->file($ruta, [
            'Content-Disposition' => 'inline; filename="' . $nombre . '"',
            'Cache-Control'       => 'private, no-store',
        ]);
    }

    /**
     * POST /api/contracts/detect-document-side
     * Detecta automáticamente si una imagen es cara frontal o trasera de cédula.
     * Sin autenticación — usado en la vista de firma del cliente.
     */
    public function detectDocumentSide(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $imageData = $request->input('image');
            if (!$imageData) {
                return response()->json(['status' => 1, 'message' => 'Imagen requerida.']);
            }

            $binary = $this->extractBase64Image($imageData);
            if (empty($binary)) {
                return response()->json(['status' => 1, 'message' => 'Imagen inválida.']);
            }

            $tmpFile = tempnam(sys_get_temp_dir(), 'doc_detect_') . '.jpg';
            file_put_contents($tmpFile, $binary);

            $side = \App\Services\DocumentSideDetector::detect($tmpFile);
            unlink($tmpFile);

            return response()->json([
                'status' => 0,
                'side'   => $side, // 'front', 'back', 'unknown'
                'confidence' => $side === 'unknown' ? 'low' : 'medium',
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('[DetectDocSide] Error: ' . $e->getMessage());
            return response()->json(['status' => 1, 'message' => 'Error al analizar imagen.']);
        }
    }

    /**
     * Valida que un string base64 sea una imagen válida.
     */
    private function isValidImageBase64(string $base64): bool
    {
        $data = $this->extractBase64Image($base64);
        if (empty($data)) return false;

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($data);
        return in_array($mime, ['image/jpeg', 'image/png', 'image/jpg']);
    }

    private function getBase64ImageExtension(string $base64): ?string
    {
        if (str_starts_with($base64, 'data:image/jpeg')) return 'jpg';
        if (str_starts_with($base64, 'data:image/jpg')) return 'jpg';
        if (str_starts_with($base64, 'data:image/png')) return 'png';
        return null;
    }

    private function extractBase64Image(string $base64): string
    {
        if (str_starts_with($base64, 'data:image')) {
            $parts = explode(',', $base64, 2);
            return isset($parts[1]) ? base64_decode($parts[1]) : '';
        }
        return base64_decode($base64);
    }
}

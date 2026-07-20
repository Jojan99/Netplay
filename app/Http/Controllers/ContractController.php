<?php

namespace App\Http\Controllers;

use App\UseCases\Contract\Interfaces\ClientContractUseCaseInterface;
use App\UseCases\Contract\Interfaces\CreateContractUseCaseInterface;
use App\UseCases\Contract\Interfaces\GetContractsUseCaseInterface;
use App\Repositories\Interfaces\ContractRepositoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use App\Services\WhatsAppService;

class ContractController extends Controller
{
    // ── Plantillas ────────────────────────────────────────────────────────────

    public function index(GetContractsUseCaseInterface $uc): JsonResponse
    {
        return response()->json($uc->getAll());
    }

    public function show(int $id, GetContractsUseCaseInterface $uc): JsonResponse
    {
        return response()->json($uc->getById($id));
    }

    public function store(Request $request, CreateContractUseCaseInterface $uc): JsonResponse
    {
        return response()->json($uc->create($request));
    }

    public function update(int $id, Request $request, CreateContractUseCaseInterface $uc): JsonResponse
    {
        return response()->json($uc->update($id, $request));
    }

    public function destroy(int $id, CreateContractUseCaseInterface $uc): JsonResponse
    {
        return response()->json($uc->delete($id));
    }

    // ── Contratos de clientes ─────────────────────────────────────────────────

    public function assign(Request $request, ClientContractUseCaseInterface $uc): JsonResponse
    {
        return response()->json($uc->assign($request));
    }

    /**
     * POST /api/contracts/client-contract/{clientContractId}/documents
     * Sube fotos de documento de identidad (cara frontal y trasera).
     */
    public function uploadDocument(int $clientContractId, Request $request, ContractRepositoryInterface $repo): JsonResponse
    {
        try {
            $cc = $repo->getClientContract($clientContractId);

            $request->validate([
                'document_front' => 'nullable|image|mimes:jpeg,jpg,png|max:5120',
                'document_back'  => 'nullable|image|mimes:jpeg,jpg,png|max:5120',
                'document_number_front' => 'nullable|string|max:50',
                'document_number_back'  => 'nullable|string|max:50',
            ]);

            $companyId = getSessionCompanyId();
            $dir = "contracts/{$companyId}/documents";

            $updateData = [];

            if ($request->hasFile('document_front')) {
                $frontFile = $request->file('document_front');
                $frontName = "cc_{$clientContractId}_front_" . uniqid() . '.' . $frontFile->getClientOriginalExtension();
                $frontPath = $frontFile->storeAs($dir, $frontName, 'public');
                $updateData['document_front_path'] = $frontPath;
            }

            if ($request->hasFile('document_back')) {
                $backFile = $request->file('document_back');
                $backName = "cc_{$clientContractId}_back_" . uniqid() . '.' . $backFile->getClientOriginalExtension();
                $backPath = $backFile->storeAs($dir, $backName, 'public');
                $updateData['document_back_path'] = $backPath;
            }

            if ($request->filled('document_number_front')) {
                $updateData['document_number_front'] = $request->input('document_number_front');
            }
            if ($request->filled('document_number_back')) {
                $updateData['document_number_back'] = $request->input('document_number_back');
            }

            if (!empty($updateData)) {
                $cc->update($updateData);
            }

            return response()->json([
                'status'  => 0,
                'message' => 'Documentos actualizados.',
                'data'    => [
                    'document_front_url' => $cc->document_front_path ? url('storage/' . $cc->document_front_path) : null,
                    'document_back_url'  => $cc->document_back_path ? url('storage/' . $cc->document_back_path) : null,
                    'document_number_front' => $cc->document_number_front,
                    'document_number_back'  => $cc->document_number_back,
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 1,
                'message' => 'Error al subir documentos: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * GET /api/contracts/client-contract/{clientContractId}/documents
     * Devuelve URLs seguras de los documentos subidos.
     */
    public function getDocuments(int $clientContractId, ContractRepositoryInterface $repo): JsonResponse
    {
        try {
            $cc = $repo->getClientContract($clientContractId);

            return response()->json([
                'status' => 0,
                'data'   => [
                    'require_documents'     => $cc->require_documents,
                    'document_front_url'    => $cc->document_front_path ? url('storage/' . $cc->document_front_path) : null,
                    'document_back_url'     => $cc->document_back_path ? url('storage/' . $cc->document_back_path) : null,
                    'document_number_front' => $cc->document_number_front,
                    'document_number_back'  => $cc->document_number_back,
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 1,
                'message' => 'Error al obtener documentos: ' . $e->getMessage(),
            ]);
        }
    }

    public function clientContracts(int $userId, ClientContractUseCaseInterface $uc, ContractRepositoryInterface $repo): JsonResponse
    {
        // userId=0 → devuelve todos los contratos asignados de la empresa
        if ($userId === 0) {
            $data = DB::table('client_contracts as cc')
                ->join('contracts as c', 'c.id', '=', 'cc.contract_id')
                ->join('users', 'users.id', '=', 'cc.user_id')
                ->leftJoin('user_data as ud', 'ud.user_id', '=', 'cc.user_id')
                ->where('cc.company_id', getSessionCompanyId())
                ->select('cc.id', 'cc.status', 'cc.token', 'cc.signed_at',
                         'cc.require_documents', 'cc.document_front_path', 'cc.document_back_path',
                         'cc.document_number_front', 'cc.document_number_back',
                         'c.id as contract_id', 'c.title as contract_title',
                         'users.id as user_id', 'users.username',
                         'ud.names', 'ud.lastname', 'ud.phone', 'ud.email', 'ud.dni')
                ->orderByDesc('cc.created_at')
                ->get()
                ->map(fn($r) => [
                    'id'        => $r->id,
                    'status'    => $r->status,
                    'token'     => $r->token,
                    'signed_at' => $r->signed_at,
                    'require_documents'      => (bool) $r->require_documents,
                    'document_front_path'    => $r->document_front_path,
                    'document_back_path'     => $r->document_back_path,
                    'document_number_front'  => $r->document_number_front,
                    'document_number_back'   => $r->document_number_back,
                    'contract'  => ['id' => $r->contract_id, 'title' => $r->contract_title],
                    'user'      => [
                        'id'       => $r->user_id,
                        'username' => $r->username,
                        'names'    => $r->names,
                        'lastname' => $r->lastname,
                        'phone'    => $r->phone,
                        'email'    => $r->email,
                        'dni'      => $r->dni,
                    ],
                ]);
            return response()->json(['status' => 0, 'message' => 'ok', 'data' => $data]);
        }
        return response()->json($uc->getByUser($userId));
    }

    public function sendEmail(int $clientContractId, Request $request, ContractRepositoryInterface $repo): JsonResponse
    {
        try {
            $cc      = $repo->getClientContract($clientContractId);
            $signUrl = url("/contrato/firmar/{$cc->token}");
            $email   = $request->input('email');

            Mail::raw(
                "Hola, le compartimos el link para firmar su contrato \"{$cc->contract->title}\":\n\n{$signUrl}\n\nAbra el link desde su teléfono para firmar.",
                fn($msg) => $msg->to($email)->subject("Contrato pendiente de firma: {$cc->contract->title}")
            );

            return response()->json(['status' => 0, 'message' => 'Correo enviado exitosamente.']);
        } catch (\Throwable $e) {
            return response()->json(['status' => 1, 'message' => 'Error al enviar correo: ' . $e->getMessage()]);
        }
    }

    public function sendWhatsApp(int $clientContractId, Request $request, ContractRepositoryInterface $repo): JsonResponse
    {
        try {
            \Illuminate\Support\Facades\Log::info('[ContractSendWA] Petición recibida', [
                'client_contract_id' => $clientContractId,
                'phone_raw' => $request->input('phone'),
                'session_company_id' => getSessionCompanyId(),
            ]);

            $cc      = $repo->getClientContract($clientContractId);
            $signUrl = url("/contrato/firmar/{$cc->token}");
            $phone   = $request->input('phone');

            // Normalizar: quitar todo excepto dígitos
            $phone = preg_replace('/\D/', '', $phone);

            \Illuminate\Support\Facades\Log::info('[ContractSendWA] Teléfono normalizado', [
                'client_contract_id' => $clientContractId,
                'phone_normalized' => $phone,
            ]);

            // Aceptar formato colombiano: 10 dígitos (3xxxxxxxxx) o 12 dígitos (57xxxxxxxxxx)
            if (!preg_match('/^(57\d{10}|3\d{9})$/', $phone)) {
                return response()->json([
                    'status'  => 1,
                    'message' => 'El número de teléfono no es válido. Use 10 dígitos (3XX...) o 12 dígitos (57...).',
                ]);
            }

            $message = "Hola 👋, le compartimos el link para firmar su contrato *{$cc->contract->title}*:\n\n{$signUrl}\n\nAbra el link desde su teléfono para completar su firma.";

            // Usar company_id del contrato para garantizar credenciales correctas
            $wa = new WhatsAppService($cc->company_id);
            $waResponse = $wa->mensajeInformativo($phone, $message);

            \Illuminate\Support\Facades\Log::info('[ContractSendWA] Respuesta WA', [
                'client_contract_id' => $clientContractId,
                'phone' => $phone,
                'wa_response' => $waResponse,
            ]);

            // Si la API de WA respondió con error dentro del body JSON, reflejarlo
            if (isset($waResponse['error']) || ($waResponse['success'] ?? true) === false) {
                $apiMsg = $waResponse['message'] ?? $waResponse['error'] ?? 'Error desconocido de la API de WhatsApp';
                return response()->json([
                    'status'   => 1,
                    'message'  => 'La API de WhatsApp respondió con error: ' . $apiMsg,
                    'wa_debug' => $waResponse,
                ]);
            }

            return response()->json([
                'status'   => 0,
                'message'  => 'Mensaje enviado por WhatsApp.',
                'wa_debug' => $waResponse,
            ]);
        } catch (\RuntimeException $e) {
            \Illuminate\Support\Facades\Log::error('[ContractSendWA] RuntimeException: ' . $e->getMessage());
            return response()->json([
                'status'  => 1,
                'message' => 'Error de la API de WhatsApp: ' . $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('[ContractSendWA] Throwable: ' . $e->getMessage());
            return response()->json([
                'status'  => 1,
                'message' => 'Error al enviar WhatsApp: ' . $e->getMessage(),
            ]);
        }
    }

    public function clientContractById(int $clientContractId, ClientContractUseCaseInterface $uc): JsonResponse
    {
        return response()->json($uc->getById($clientContractId));
    }

    public function deleteClientContract(int $clientContractId, ContractRepositoryInterface $repo): JsonResponse
    {
        try {
            $cc = $repo->getClientContract($clientContractId);
            $cc->delete();
            return response()->json(['status' => 0, 'message' => 'Contrato eliminado.']);
        } catch (\Throwable $e) {
            return response()->json(['status' => 1, 'message' => 'Error al eliminar: ' . $e->getMessage()]);
        }
    }

    public function sign(int $clientContractId, Request $request, ClientContractUseCaseInterface $uc): JsonResponse
    {
        return response()->json($uc->sign($clientContractId, $request));
    }

    public function pdf(int $clientContractId, ClientContractUseCaseInterface $uc): mixed
    {
        return $uc->generatePdf($clientContractId);
    }

    /**
     * POST /api/contracts/upload-pdf
     * Sube un PDF y devuelve su contenido extraído en HTML estructurado.
     */
    public function uploadPdf(Request $request): JsonResponse
    {
        $request->validate([
            'pdf' => 'required|file|mimes:pdf|max:5120',
        ]);

        try {
            $file = $request->file('pdf');
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($file->getPathname());
            $text = $pdf->getText();

            // Guardar PDF temporalmente para que el frontend lo muestre como guía
            $tempName = uniqid('contract_pdf_') . '.pdf';
            $tempPath = storage_path('app/public/temp/' . $tempName);
            if (!is_dir(dirname($tempPath))) {
                mkdir(dirname($tempPath), 0755, true);
            }
            copy($file->getPathname(), $tempPath);
            $pdfUrl = url('storage/temp/' . $tempName);

            // Extraer HTML estructurado intentando detectar títulos, tablas, etc.
            $html = $this->pdfTextToStructuredHtml($text);

            return response()->json([
                'status'  => 0,
                'message' => 'PDF procesado exitosamente.',
                'data'    => [
                    'html'   => $html,
                    'pdfUrl' => $pdfUrl,
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 1,
                'message' => 'Error al procesar PDF: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Convierte texto plano extraído de PDF a HTML estructurado.
     */
    private function pdfTextToStructuredHtml(string $text): string
    {
        $lines = array_filter(array_map('trim', explode("\n", $text)));
        $html = [];
        $inTable = false;
        $tableRows = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            // Detectar título: corto, mayúsculas, sin punto final
            if (strlen($line) <= 80 && strtoupper($line) === $line && !str_ends_with($line, '.')) {
                if ($inTable && count($tableRows) > 0) {
                    $html[] = $this->buildTableHtml($tableRows);
                    $tableRows = [];
                    $inTable = false;
                }
                // HTML semántico LIMPIO — sin inline styles
                $html[] = '<h2>' . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . '</h2>';
                continue;
            }

            // Detectar subtítulo: corto, empieza con "CLAUSULA", número romano, etc.
            if (preg_match('/^(CLAUSULA|CLÁUSULA|ARTICULO|ARTÍCULO|SECCIÓN|SECCION|CAPITULO|CAPÍTULO|[IVX]+\.?|[0-9]+\.?)/i', $line) && strlen($line) <= 100) {
                if ($inTable && count($tableRows) > 0) {
                    $html[] = $this->buildTableHtml($tableRows);
                    $tableRows = [];
                    $inTable = false;
                }
                $html[] = '<h3>' . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . '</h3>';
                continue;
            }

            // Detectar fila de tabla: múltiples tabs o múltiples espacios seguidos
            if (preg_match('/\t{2,}/', $line) || preg_match('/\s{4,}.+\s{4,}/', $line)) {
                $inTable = true;
                $tableRows[] = $line;
                continue;
            }

            // Si estábamos en tabla pero ya no, cerrar tabla
            if ($inTable) {
                $html[] = $this->buildTableHtml($tableRows);
                $tableRows = [];
                $inTable = false;
            }

            // Detectar lista numerada
            if (preg_match('/^[0-9]+[\.\)]\s+/', $line)) {
                $html[] = '<p class="list-item">' . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . '</p>';
                continue;
            }

            // Detectar línea de firma
            if (preg_match('/(firma|firman|firmado|testigo|TESTIGO|FIRMA|FIRM|f\.)/i', $line)) {
                $html[] = '<p class="signature-line">' . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . '</p>';
                continue;
            }

            // Párrafo normal
            $html[] = '<p>' . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . '</p>';
        }

        // Cerrar tabla pendiente
        if ($inTable && count($tableRows) > 0) {
            $html[] = $this->buildTableHtml($tableRows);
        }

        $allHtml = implode("\n", $html);

        // Reemplazar placeholders comunes entre corchetes con variables
        $placeholderMap = [
            '/\[NOMBRE\s*COMPLETO\]|\[NOMBRE\s*Y\s*APELLIDO\]/i' => '{{nombre_completo}}',
            '/\[NOMBRE\]|\[NAME\]/i' => '{{nombre}}',
            '/\[APELLIDO\]|\[APELLIDOS\]/i' => '{{apellido}}',
            '/\[DNI\]|\[CEDULA\]|\[CÉDULA\]|\[DOCUMENTO\]|\[CC\]/i' => '{{dni}}',
            '/\[TELEFONO\]|\[TELÉFONO\]|\[CELULAR\]|\[PHONE\]/i' => '{{telefono}}',
            '/\[EMAIL\]|\[CORREO\]|\[E-MAIL\]/i' => '{{email}}',
            '/\[DIRECCION\]|\[DIRECCIÓN\]|\[DOMICILIO\]|\[ADDRESS\]/i' => '{{direccion}}',
            '/\[FECHA\]|\[DATE\]|\[HOY\]/i' => '{{fecha}}',
            '/\[NUMERO\s*CONTRATO\]|\[N°\s*CONTRATO\]|\[CONTRATO\s*ID\]/i' => '{{contrato_id}}',
        ];
        foreach ($placeholderMap as $pattern => $replacement) {
            $allHtml = preg_replace($pattern, $replacement, $allHtml);
        }

        // Convertir líneas que son solo _____ o ----- a <hr>
        $allHtml = preg_replace('/<p[^>]*>\s*[_-]{5,}\s*<\/p>/i', '<hr>', $allHtml);

        // HTML limpio con clases semánticas, SIN inline styles
        $wrapper = '<div class="contract-body">';
        $wrapper .= '<p class="contract-guide"><strong>Guía:</strong> Reemplace los textos entre corchetes o use las variables rápidas ({{nombre}}, {{dni}}, etc.).</p>';
        $wrapper .= $allHtml;
        $wrapper .= '</div>';

        return $wrapper;
    }

    private function buildTableHtml(array $rows): string
    {
        $html = '<table>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            $cells = preg_split('/\t+|\s{4,}/', $row, -1, PREG_SPLIT_NO_EMPTY);
            foreach ($cells as $cell) {
                $html .= '<td>' . htmlspecialchars(trim($cell), ENT_QUOTES, 'UTF-8') . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</table>';
        return $html;
    }

    /**
     * POST /api/contracts/{id}/pdf-base
     * Sube el PDF original como base del contrato (para mantener diseño exacto).
     */
    public function uploadPdfBase(int $id, Request $request): JsonResponse
    {
        $request->validate([
            'pdf' => 'required|file|mimes:pdf|max:5120',
        ]);

        try {
            $contract = \App\Models\Contract::findOrFail($id);
            $companyId = getSessionCompanyId();
            $file = $request->file('pdf');

            $dir = "contracts/{$companyId}";
            $filename = "contract_{$id}_base.pdf";
            $path = $file->storeAs($dir, $filename, 'public');

            $contract->pdf_path = $path;
            $contract->save();

            $pdfUrl = url('storage/' . $path);

            return response()->json([
                'status'  => 0,
                'message' => 'PDF base guardado. Se usará como fondo exacto del contrato.',
                'data'    => ['pdf_path' => $path, 'pdf_url' => $pdfUrl],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 1,
                'message' => 'Error al guardar PDF base: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * POST /api/contracts/{id}/logo
     * Sube logo de la plantilla de contrato (base64 o URL).
     */
    public function uploadLogo(int $id, Request $request): JsonResponse
    {
        $request->validate([
            'logo' => 'required|image|max:2048',
        ]);

        try {
            $contract = \App\Models\Contract::findOrFail($id);
            $file = $request->file('logo');
            $content = file_get_contents($file->getRealPath());
            $mime = $file->getMimeType();
            $base64 = "data:{$mime};base64," . base64_encode($content);

            $contract->logo = $base64;
            $contract->save();

            return response()->json([
                'status'  => 0,
                'message' => 'Logo actualizado.',
                'data'    => ['logo' => $base64],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 1,
                'message' => 'Error al subir logo: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * GET /api/contracts/{id}/pdf-fields
     * Devuelve las coordenadas guardadas de variables sobre el PDF.
     */
    public function getPdfFields(int $id): JsonResponse
    {
        try {
            $fields = \App\Models\ContractPdfField::where('contract_id', $id)
                ->orderBy('page')->orderBy('id')
                ->get();

            return response()->json([
                'status' => 0,
                'data'   => $fields,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 1,
                'message' => 'Error al obtener campos: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * POST /api/contracts/{id}/pdf-fields
     * Guarda o actualiza coordenadas de variables sobre el PDF.
     */
    public function savePdfFields(int $id, Request $request): JsonResponse
    {
        $request->validate([
            'fields'   => 'required|array',
            'fields.*.variable'   => 'required|string',
            'fields.*.page'      => 'required|integer|min:1',
            'fields.*.x'         => 'required|numeric',
            'fields.*.y'         => 'required|numeric',
            'fields.*.font_size' => 'nullable|integer|min:6|max:72',
            'fields.*.color'     => 'nullable|string|size:6',
            'fields.*.max_width' => 'nullable|integer|min:10',
        ]);

        try {
            // Borrar campos anteriores y guardar los nuevos
            \App\Models\ContractPdfField::where('contract_id', $id)->delete();

            foreach ($request->input('fields') as $f) {
                \App\Models\ContractPdfField::create([
                    'contract_id' => $id,
                    'variable'    => $f['variable'],
                    'page'        => $f['page'],
                    'x'           => $f['x'],
                    'y'           => $f['y'],
                    'font_size'   => $f['font_size'] ?? 10,
                    'color'       => $f['color']     ?? '000000',
                    'max_width'   => $f['max_width'] ?? 200,
                ]);
            }

            return response()->json([
                'status'  => 0,
                'message' => 'Coordenadas guardadas.',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 1,
                'message' => 'Error al guardar campos: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * GET /api/contracts/{id}/pdf-dimensions
     * Devuelve las dimensiones de cada página del PDF base en puntos.
     */
    public function getPdfDimensions(int $id): JsonResponse
    {
        try {
            $contract = \App\Models\Contract::findOrFail($id);
            if (!$contract->pdf_path) {
                return response()->json([
                    'status'  => 1,
                    'message' => 'No hay PDF base cargado.',
                ]);
            }

            $fullPath = storage_path('app/public/' . $contract->pdf_path);
            if (!file_exists($fullPath)) {
                return response()->json([
                    'status'  => 1,
                    'message' => 'Archivo PDF no encontrado en servidor.',
                ]);
            }

            $pdf = new \setasign\Fpdi\Fpdi();
            $pageCount = $pdf->setSourceFile($fullPath);
            $pages = [];
            for ($i = 1; $i <= $pageCount; $i++) {
                $tpl = $pdf->importPage($i);
                $size = $pdf->getTemplateSize($tpl);
                $pages[] = [
                    'page'   => $i,
                    'width'  => $size['width'],
                    'height' => $size['height'],
                    'orientation' => $size['orientation'],
                ];
            }

            return response()->json([
                'status' => 0,
                'data'   => ['pageCount' => $pageCount, 'pages' => $pages],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 1,
                'message' => 'Error al leer dimensiones: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * GET /api/contracts/{id}/pdf-preview
     * Genera un PDF de preview con datos dummy para verificar coordenadas.
     */
    public function pdfPreview(int $id): mixed
    {
        try {
            $contract = \App\Models\Contract::findOrFail($id);
            if (!$contract->pdf_path) {
                return response()->json([
                    'status'  => 1,
                    'message' => 'No hay PDF base cargado.',
                ]);
            }

            $pdfFields = \App\Models\ContractPdfField::where('contract_id', $id)
                ->orderBy('page')->orderBy('id')
                ->get();

            $dummyValues = [
                '{{nombre}}'          => 'JUAN PEREZ',
                '{{apellido}}'        => 'GOMEZ',
                '{{nombre_completo}}' => 'JUAN PEREZ GOMEZ',
                '{{dni}}'             => '12345678',
                '{{telefono}}'        => '3001234567',
                '{{email}}'           => 'juan@ejemplo.com',
                '{{direccion}}'       => 'Calle 123 # 45-67',
                '{{fecha}}'           => now()->format('d/m/Y'),
                '{{fecha_hora}}'      => now()->format('d/m/Y H:i'),
                '{{contrato_id}}'     => '999',
                // Fecha separada
                '{{dia}}'             => now()->format('d'),
                '{{mes}}'             => now()->format('m'),
                '{{anio}}'            => now()->format('Y'),
                // Plan
                '{{plan_nombre}}'     => 'INTERNET 200MG PLUS',
                '{{plan_velocidad}}'  => '200 Mb',
                '{{plan_precio}}'     => '$70.000',
                '{{plan_instalacion}}'=> '$60.000',
                '{{promocion_nombre}}'=> 'Promoción verano 200Mb',
                // Checks
                '{{check_200mb}}'     => 'X',
                '{{check_300mb}}'     => '',
                '{{check_400mb}}'     => '',
                '{{check_otra}}'      => '',
                '{{check_os_nuevo}}'  => 'X',
                '{{check_os_mod}}'    => '',
                // Check simple
                '{{check}}'           => 'X',
                // Tipo documento
                '{{tipo_documento}}'  => 'CC',
                // Valor instalación
                '{{valor_instalacion}}' => '$60.000',
                // Firma placeholder
                '{{firma}}'           => '',
            ];

            $service = new \App\Services\ContractPdfService();
            $output = $service->fillPdfBase(
                $contract->pdf_path,
                $dummyValues,
                $pdfFields->toArray()
            );

            return response($output)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', 'inline; filename="preview.pdf"');

        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 1,
                'message' => 'Error al generar preview: ' . $e->getMessage(),
            ]);
        }
    }
}

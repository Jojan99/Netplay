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
            $cc      = $repo->getClientContract($clientContractId);
            $signUrl = url("/contrato/firmar/{$cc->token}");
            $phone   = $request->input('phone');
            $message = "Hola 👋, le compartimos el link para firmar su contrato *{$cc->contract->title}*:\n\n{$signUrl}\n\nAbra el link desde su teléfono para completar su firma.";

            (new WhatsAppService())->mensajeInformativo($phone, $message);

            return response()->json(['status' => 0, 'message' => 'Mensaje enviado por WhatsApp.']);
        } catch (\Throwable $e) {
            return response()->json(['status' => 1, 'message' => 'Error al enviar WhatsApp: ' . $e->getMessage()]);
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
                $html[] = '<h2 style="font-size:16px; font-weight:bold; text-align:center; margin:20px 0 10px; text-transform:uppercase;">' . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . '</h2>';
                continue;
            }

            // Detectar subtítulo: corto, primera letra mayúscula, sin punto final, empieza con número romano o "CLAUSULA"
            if (preg_match('/^(CLAUSULA|CLÁUSULA|ARTICULO|ARTÍCULO|SECCIÓN|SECCION|CAPITULO|CAPÍTULO|[IVX]+\.?|[0-9]+\.?)/i', $line) && strlen($line) <= 100) {
                if ($inTable && count($tableRows) > 0) {
                    $html[] = $this->buildTableHtml($tableRows);
                    $tableRows = [];
                    $inTable = false;
                }
                $html[] = '<h3 style="font-size:14px; font-weight:bold; margin:15px 0 8px;">' . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . '</h3>';
                continue;
            }

            // Detectar fila de tabla: múltiples tabs o múltiples espacios seguidos (más de 3)
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
                $html[] = '<p style="margin-bottom:6px; text-align:justify; padding-left:20px;">' . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . '</p>';
                continue;
            }

            // Detectar línea de firma / espacio en blanco para firma
            if (preg_match('/(firma|firman|firmado|testigo|TESTIGO|FIRMA|FIRM|f\.)/i', $line)) {
                $html[] = '<p style="margin-bottom:6px; text-align:justify; font-weight:bold;">' . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . '</p>';
                continue;
            }

            // Párrafo normal
            $html[] = '<p style="margin-bottom:10px; text-align:justify;">' . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . '</p>';
        }

        // Cerrar tabla pendiente
        if ($inTable && count($tableRows) > 0) {
            $html[] = $this->buildTableHtml($tableRows);
        }

        $allHtml = implode("\n", $html);

        // Reemplazar placeholders comunes entre corchetes o paréntesis con variables
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

        // También convertir líneas que son solo _____ o ----- a líneas horizontales
        $allHtml = preg_replace('/<p[^>]*>\s*[_-]{5,}\s*<\/p>/i', '<hr>', $allHtml);

        $wrapper = '<div style="font-family: Arial, sans-serif; font-size: 13px; line-height: 1.7; color: #333;">';
        $wrapper .= '<p style="font-size:11px; color:#888; border-bottom:1px solid #ddd; padding-bottom:8px; margin-bottom:15px;">';
        $wrapper .= '<strong>Guía:</strong> Reemplace los textos entre corchetes o use las variables rápidas ({{nombre}}, {{dni}}, etc.).';
        $wrapper .= '</p>';
        $wrapper .= $allHtml;
        $wrapper .= '</div>';

        return $wrapper;
    }

    private function buildTableHtml(array $rows): string
    {
        $html = '<table style="width:100%; border-collapse:collapse; margin:10px 0; font-size:12px;">';
        foreach ($rows as $row) {
            $html .= '<tr>';
            // Separar por tabs o múltiples espacios
            $cells = preg_split('/\t+|\s{4,}/', $row, -1, PREG_SPLIT_NO_EMPTY);
            foreach ($cells as $cell) {
                $html .= '<td style="border:1px solid #ccc; padding:6px 8px;">' . htmlspecialchars(trim($cell), ENT_QUOTES, 'UTF-8') . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</table>';
        return $html;
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
}

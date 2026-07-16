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
     * Sube un PDF y devuelve su contenido extraído en HTML.
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

            // Convertir texto plano a HTML básico con párrafos
            $paragraphs = array_filter(array_map('trim', explode("\n", $text)));
            $html = '<div style="font-family: Arial, sans-serif; font-size: 14px; line-height: 1.7; color: #333;">';
            foreach ($paragraphs as $p) {
                $html .= '<p style="margin-bottom: 10px; text-align: justify;">' . htmlspecialchars($p, ENT_QUOTES, 'UTF-8') . '</p>';
            }
            $html .= '</div>';

            return response()->json([
                'status'  => 0,
                'message' => 'PDF convertido a HTML exitosamente.',
                'data'    => ['html' => $html],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 1,
                'message' => 'Error al procesar PDF: ' . $e->getMessage(),
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
}

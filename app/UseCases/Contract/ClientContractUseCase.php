<?php

namespace App\UseCases\Contract;

use App\Constants\ApiResponseConstants;
use App\Repositories\Interfaces\ContractRepositoryInterface;
use App\UseCases\Contract\Interfaces\ClientContractUseCaseInterface;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

class ClientContractUseCase implements ClientContractUseCaseInterface
{
    public function __construct(
        private ContractRepositoryInterface $contractRepository
    ) {}

    public function assign(Request $request): mixed
    {
        try {
            if (!sessionUserHasProfile('ADMIN', 'CONTADOR')) {
                return ['message' => 'Acción no permitida', 'status' => 1, 'data' => ApiResponseConstants::DATA_NULL];
            }
            $data = $this->contractRepository->assignToClient(
                $request->input('contract_id'),
                $request->input('user_id')
            );
        } catch (QueryException $e) {
            return ['message' => 'Error al asignar contrato: ' . $e->getMessage(), 'status' => 1, 'data' => ApiResponseConstants::DATA_NULL];
        }
        return ['message' => 'Contrato asignado al cliente exitosamente', 'status' => 0, 'data' => $data];
    }

    public function getByUser(int $userId): mixed
    {
        try {
            $data = $this->contractRepository->getClientContractsByUser($userId);
        } catch (QueryException $e) {
            return ['message' => 'Error al consultar contratos del cliente', 'status' => 1, 'data' => ApiResponseConstants::DATA_NULL];
        }
        return ['message' => 'Consulta realizada con éxito', 'status' => 0, 'data' => $data];
    }

    public function getById(int $clientContractId): mixed
    {
        try {
            $data = $this->contractRepository->getClientContract($clientContractId);
        } catch (ModelNotFoundException) {
            return ['message' => 'Contrato no encontrado', 'status' => 1, 'data' => ApiResponseConstants::DATA_NULL];
        } catch (QueryException $e) {
            return ['message' => 'Error al consultar contrato', 'status' => 1, 'data' => ApiResponseConstants::DATA_NULL];
        }
        return ['message' => 'Consulta realizada con éxito', 'status' => 0, 'data' => $data];
    }

    public function sign(int $clientContractId, Request $request): mixed
    {
        try {
            $cc = $this->contractRepository->getClientContract($clientContractId);

            if ($cc->status === 'signed') {
                return ['message' => 'El contrato ya fue firmado', 'status' => 1, 'data' => ApiResponseConstants::DATA_NULL];
            }

            $data = $this->contractRepository->sign($clientContractId, $request->input('signature'));
        } catch (ModelNotFoundException) {
            return ['message' => 'Contrato no encontrado', 'status' => 1, 'data' => ApiResponseConstants::DATA_NULL];
        } catch (QueryException $e) {
            return ['message' => 'Error al guardar firma', 'status' => 1, 'data' => ApiResponseConstants::DATA_NULL];
        }
        return ['message' => 'Contrato firmado exitosamente', 'status' => 0, 'data' => $data];
    }

    public function generatePdf(int $clientContractId): mixed
    {
        try {
            $cc = $this->contractRepository->getClientContract($clientContractId);

            $html = $this->buildHtml($cc);

            $options = new Options();
            $options->set('isHtml5ParserEnabled', true);
            $options->set('isPhpEnabled', true);
            $options->set('isRemoteEnabled', true);
            $options->set('defaultFont', 'Arial');

            $pdf = new Dompdf($options);
            $pdf->setPaper('A4', 'portrait');
            $pdf->loadHtml($html);
            $pdf->render();
            $output = $pdf->output();

            $clientName = $cc->user->username ?? 'contrato';
            $filename   = 'contrato-' . $clientContractId . '-' . $clientName . '.pdf';

            return response($output)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');

        } catch (ModelNotFoundException) {
            return ['message' => 'Contrato no encontrado', 'status' => 1, 'data' => ApiResponseConstants::DATA_NULL];
        } catch (\Throwable $e) {
            return ['message' => 'Error al generar PDF: ' . $e->getMessage(), 'status' => 1, 'data' => ApiResponseConstants::DATA_NULL];
        }
    }

    private function replaceVars(string $content, object $cc): string
    {
        $ud  = \Illuminate\Support\Facades\DB::table('user_data')->where('user_id', $cc->user_id)->first();
        $now = now();
        $vars = [
            '{{nombre}}'          => $ud->names    ?? '',
            '{{apellido}}'        => $ud->lastname  ?? '',
            '{{nombre_completo}}' => trim(($ud->names ?? '') . ' ' . ($ud->lastname ?? '')),
            '{{dni}}'             => $ud->dni       ?? '',
            '{{telefono}}'        => $ud->phone     ?? '',
            '{{email}}'           => $ud->email     ?? '',
            '{{direccion}}'       => $ud->address   ?? '',
            '{{fecha}}'           => $now->format('d/m/Y'),
            '{{fecha_hora}}'      => $now->format('d/m/Y H:i'),
            '{{contrato_id}}'     => $cc->id,
        ];
        return str_replace(array_keys($vars), array_values($vars), $content);
    }

    private function buildHtml(object $cc): string
    {
        $content   = $this->replaceVars($cc->contract->content ?? '', $cc);
        $title     = $cc->contract->title ?? 'Contrato';
        $signedAt  = $cc->signed_at ? $cc->signed_at->format('d/m/Y H:i') : 'Pendiente';
        $status    = $cc->status === 'signed' ? 'FIRMADO' : 'PENDIENTE';

        $signatureBlock = '';
        if ($cc->status === 'signed' && $cc->signature) {
            $signatureBlock = "
                <div style='margin-top:40px; border-top:1px solid #ccc; padding-top:20px;'>
                    <p style='font-weight:bold;'>Firma del cliente:</p>
                    <img src='{$cc->signature}' style='max-width:300px; border:1px solid #ddd; padding:8px;' />
                    <p style='font-size:12px; color:#666;'>Firmado el: {$signedAt}</p>
                </div>
            ";
        }

        return "
        <!DOCTYPE html>
        <html lang='es'>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: Arial, sans-serif; font-size: 13px; color: #333; margin: 40px; }
                h1   { font-size: 20px; text-align: center; margin-bottom: 6px; }
                .meta { text-align: center; font-size: 11px; color: #888; margin-bottom: 30px; }
                .badge { display:inline-block; padding:3px 10px; border-radius:4px; font-size:11px; font-weight:bold;
                         background: " . ($cc->status === 'signed' ? '#d4edda; color:#155724' : '#fff3cd; color:#856404') . "; }
                .content { line-height: 1.7; }
            </style>
        </head>
        <body>
            <h1>{$title}</h1>
            <p class='meta'>Estado: <span class='badge'>{$status}</span></p>
            <hr>
            <div class='content'>{$content}</div>
            {$signatureBlock}
        </body>
        </html>
        ";
    }
}

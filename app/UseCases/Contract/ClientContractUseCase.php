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
use Illuminate\Support\Facades\DB;

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
                $request->input('user_id'),
                (bool) $request->input('require_documents', false)
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
            $clientName = $cc->user->username ?? 'contrato';
            $filename   = 'contrato-' . $clientContractId . '-' . $clientName . '.pdf';

            // Si ya existe el PDF firmado guardado permanentemente, devolverlo directamente
            $signedPath = storage_path("app/public/contracts/signed/contract_{$clientContractId}_signed.pdf");
            if (file_exists($signedPath)) {
                return response()->file($signedPath, [
                    'Content-Type'        => 'application/pdf',
                    'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                ]);
            }

            $pdfBasePath = storage_path('app/public/' . ($cc->contract->pdf_path ?? ''));
            $hasPdfBase = $cc->contract->pdf_path && file_exists($pdfBasePath);

            if ($hasPdfBase) {
                try {
                    $service = new \App\Services\ContractPdfService();
                    $signature = $cc->signature ?? '';

                    $pdfFields = \App\Models\ContractPdfField::where('contract_id', $cc->contract_id)
                        ->orderBy('page')->orderBy('id')
                        ->get();

                    $fieldValues = $this->buildFieldValues(
                        $cc->user_id,
                        $cc->id,
                        $cc->contract->installation_value ?? null,
                        $cc->plazo->plazo ?? 12
                    );

                    $output = $service->combineWithSignature(
                        $cc->contract->pdf_path,
                        $signature,
                        $clientName,
                        $fieldValues,
                        $pdfFields->toArray()
                    );

                    return response($output)
                        ->header('Content-Type', 'application/pdf')
                        ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::error('FPDI Error: ' . $e->getMessage());
                }
            }

            // Fallback: generar PDF desde HTML con dompdf
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

            return response($output)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');

        } catch (ModelNotFoundException) {
            return ['message' => 'Contrato no encontrado', 'status' => 1, 'data' => ApiResponseConstants::DATA_NULL];
        } catch (\Throwable $e) {
            return ['message' => 'Error al generar PDF: ' . $e->getMessage(), 'status' => 1, 'data' => ApiResponseConstants::DATA_NULL];
        }
    }

    /**
     * Construye el array completo de valores de variables incluyendo
     * datos del cliente, plan de internet e instalación.
     */
    public function buildFieldValues(int $userId, int $clientContractId, ?float $installationValue = null,?int $plazo = 12): array
    {
        $ud = DB::table('user_data')->where('user_id', $userId)->first();
        $now = now();

        // Datos del plan de internet
        $plan = null;
        if ($ud && $ud->internet_plans_id) {
            $plan = DB::table('internet_plans')->where('id', $ud->internet_plans_id)->first();
        }

        // Orden de instalación más reciente del cliente
        $installOrder = DB::table('installation_orders')
            ->where('user_data_id', $ud->id ?? 0)
            ->orderByDesc('created_at')
            ->first();

        // Determinar si es OS Nuevo o Modificación
        $installCount = DB::table('installation_orders')
            ->where('user_data_id', $ud->id ?? 0)
            ->count();

        $speed = (int) ($plan->download_speed ?? 0);

        // Tipo de documento (CC, NIT, etc.)
        $tipoDoc = '';
        if ($ud && $ud->dni_id) {
            $dniType = DB::table('dnis')->where('id', $ud->dni_id)->first();
            $tipoDoc = $dniType->name ?? '';
        }

        // Valor de instalación: prioridad al valor fijo de la plantilla, fallback a la orden
        $valorInstalacion = '';
        if ($installationValue && $installationValue > 0) {
            $valorInstalacion = '$' . number_format($installationValue, 0, ',', '.');
        } elseif ($installOrder && $installOrder->installation_cost > 0) {
            $valorInstalacion = '$' . number_format((float) $installOrder->installation_cost, 0, ',', '.');
        }

        $fieldValues = [
            // ── Cliente ──────────────────────────────────────────
            '{{nombre}}'          => $ud->names    ?? '',
            '{{apellido}}'        => $ud->lastname  ?? '',
            '{{nombre_completo}}' => trim(($ud->names ?? '') . ' ' . ($ud->lastname ?? '')),
            '{{dni}}'             => $ud->dni       ?? '',
            '{{telefono}}'        => $ud->phone     ?? '',
            '{{email}}'           => $ud->email     ?? '',
            '{{direccion}}'       => $ud->address   ?? '',
            '{{fecha}}'           => $now->format('d/m/Y'),
            '{{fecha_hora}}'      => $now->format('d/m/Y H:i'),
            '{{contrato_id}}'     => (string) $clientContractId,

            // ── Fecha separada ───────────────────────────────────
            '{{dia}}'             => $now->format('d'),
            '{{mes}}'             => $now->format('m'),
            '{{anio}}'            => $now->format('Y'),

            // ── Plan de internet ─────────────────────────────────
            '{{plan_nombre}}'     => $plan->plan_name ?? '',
            '{{plazo}}' => (string)$plazo,
            '{{plan_velocidad}}'  => $speed > 0 ? $speed . ' Mb' : '',
            '{{plan_precio}}'     => $plan->monthly_price > 0 ? '$' . number_format($plan->monthly_price, 0, ',', '.') : '',
            '{{plan_instalacion}}'=> $installOrder && $installOrder->installation_cost > 0
                ? '$' . number_format((float) $installOrder->installation_cost, 0, ',', '.')
                : '',
            '{{promocion_nombre}}'=> $plan->description ?? $plan->plan_name ?? '',

            // ── Checks velocidad ─────────────────────────────────
            '{{check_200mb}}'     => $speed == 200 ? 'X' : '',
            '{{check_300mb}}'     => $speed == 300 ? 'X' : '',
            '{{check_400mb}}'     => $speed == 400 ? 'X' : '',
            '{{check_otra}}'      => ($speed > 0 && !in_array($speed, [200, 300, 400])) ? 'X' : '',

            // ── Checks OS ─────────────────────────────────────────
            '{{check_os_nuevo}}'  => $installCount <= 1 ? 'X' : '',
            '{{check_os_mod}}'    => $installCount > 1  ? 'X' : '',

            // ── Check simple (siempre X) ──────────────────────────
            '{{check}}'           => 'X',

            // ── Tipo de documento ─────────────────────────────────
            '{{tipo_documento}}'  => $tipoDoc,

            // ── Valor de instalación parametrizable ───────────────
            '{{valor_instalacion}}' => $valorInstalacion,
        ];

        return $fieldValues;
    }

    private function replaceVars(string $content, object $cc): string
    {
        $fieldValues = $this->buildFieldValues(
            $cc->user_id,
            $cc->id,
            $cc->contract->installation_value ?? null
        );
        return str_replace(array_keys($fieldValues), array_values($fieldValues), $content);
    }

    private function buildHtml(object $cc): string
    {
        $content   = $this->replaceVars($cc->contract->content ?? '', $cc);
        $title     = $cc->contract->title ?? 'Contrato';
        $signedAt  = $cc->signed_at ? $cc->signed_at->format('d/m/Y H:i') : 'Pendiente';
        $status    = $cc->status === 'signed' ? 'FIRMADO' : 'PENDIENTE';
        $logo      = $cc->contract->logo ?? '';

        $logoBlock = '';
        if ($logo) {
            $logoBlock = "<div style='text-align:center; margin-bottom:20px;'><img src='{$logo}' style='max-height:80px; max-width:200px;' /></div>";
        }

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
                @page { margin: 40px; }
                body {
                    font-family: 'Georgia', 'Times New Roman', serif;
                    font-size: 13px;
                    color: #222;
                    line-height: 1.85;
                    margin: 0;
                    padding: 0;
                }
                h1 {
                    font-size: 20px;
                    text-align: center;
                    font-weight: bold;
                    color: #1a1a2e;
                    margin-bottom: 6px;
                    text-transform: uppercase;
                    letter-spacing: 0.5px;
                }
                .meta {
                    text-align: center;
                    font-size: 11px;
                    color: #888;
                    margin-bottom: 30px;
                    font-family: Arial, sans-serif;
                }
                .badge {
                    display:inline-block;
                    padding:3px 10px;
                    border-radius:4px;
                    font-size:11px;
                    font-weight:bold;
                    background: " . ($cc->status === 'signed' ? '#d4edda; color:#155724' : '#fff3cd; color:#856404') . ";
                }
                .content {
                    text-align: justify;
                }
                .content h2, .content h3, .content h4 {
                    font-family: 'Georgia', serif;
                    text-align: center;
                    font-weight: bold;
                    color: #1a1a2e;
                    margin: 16px 0 10px;
                    text-transform: uppercase;
                    letter-spacing: 0.5px;
                }
                .content h2 { font-size: 15px; }
                .content h3 { font-size: 14px; text-align: left; margin-top: 14px; }
                .content p {
                    margin-bottom: 8px;
                    text-indent: 30px;
                }
                .content p:first-of-type { text-indent: 0; }
                .content table {
                    width: 100%;
                    border-collapse: collapse;
                    margin: 10px 0;
                    font-size: 12px;
                }
                .content th, .content td {
                    border: 1px solid #bbb;
                    padding: 6px 8px;
                    text-align: left;
                }
                .content th {
                    background: #f5f5f5;
                    font-weight: bold;
                }
                .content strong, .content b { color: #111; }
                .content ul, .content ol { margin: 8px 0 8px 20px; }
                .content li { margin-bottom: 4px; }
                .content hr {
                    border: none;
                    border-top: 1px solid #ccc;
                    margin: 14px 0;
                }
                .content blockquote {
                    border-left: 3px solid #6c63ff;
                    padding-left: 10px;
                    margin: 8px 0;
                    color: #555;
                    font-style: italic;
                }
            </style>
        </head>
        <body>
            {$logoBlock}
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

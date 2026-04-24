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

        // Reemplazar variables del contrato con datos del cliente
        $ud = \Illuminate\Support\Facades\DB::table('user_data')
            ->where('user_id', $clientContract->user_id)
            ->first();

        $clientContract->contract->content = $this->replaceVars(
            $clientContract->contract->content,
            $clientContract,
            $ud
        );

        return view('contract_sign', compact('clientContract', 'token'));
    }

    private function replaceVars(string $content, object $cc, ?object $ud): string
    {
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

            return response()->json(['message' => 'Contrato firmado exitosamente', 'status' => 0]);

        } catch (ModelNotFoundException) {
            return response()->json(['message' => 'Contrato no encontrado', 'status' => 1], 404);
        }
    }
}

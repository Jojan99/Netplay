<?php

namespace App\UseCases\Company;

use App\Models\User;
use App\Repositories\Interfaces\CompanyRepositoryInterface;
use App\Services\AccesoDirectoService;
use App\UseCases\Company\Interfaces\ConfirmCompanyEmailUseCaseInterface;

class ConfirmCompanyEmailUseCase implements ConfirmCompanyEmailUseCaseInterface
{
    public function __construct(
        private CompanyRepositoryInterface $companyRepository,
        private AccesoDirectoService $accesoDirecto,
    ) {}

    /**
     * Confirma el correo de una empresa a partir del token del enlace.
     *
     * Devuelve además un 'vale' de un solo uso para dejar la sesión iniciada:
     * quien acaba de abrir el enlace ya demostró que controla la casilla, no
     * tiene sentido pedirle usuario y contraseña acto seguido.
     *
     * El campo 'estado' le sirve al controlador para saber a dónde mandar al
     * navegador: 'ok', 'ya' (estaba confirmada) o 'invalido'.
     */
    public function confirm(string $token): mixed
    {
        $company = $this->companyRepository->findByVerificationToken($token);

        if (!$company) {
            return [
                'message' => 'Token inválido o ya fue utilizado.',
                'status'  => 1,
                'data'    => ['estado' => 'invalido'],
            ];
        }

        // Si ya estaba activa, no se vuelve a "confirmar": se la deja pasar.
        if ($company->active && $company->email_verified_at) {
            return [
                'message' => 'Esta cuenta ya estaba confirmada.',
                'status'  => 0,
                'data'    => ['estado' => 'ya', 'company' => $company->name],
            ];
        }

        $this->companyRepository->confirmEmail($company->id);

        // Activar el usuario admin de la empresa
        User::where('company_id', $company->id)
            ->update(['active' => 1]);

        return [
            'message' => 'Correo confirmado. Tu empresa ya está activa.',
            'status'  => 0,
            'data'    => [
                'estado'  => 'ok',
                'company' => $company->name,
                'vale'    => $this->accesoDirecto->emitirParaEmpresa((int) $company->id),
            ],
        ];
    }
}

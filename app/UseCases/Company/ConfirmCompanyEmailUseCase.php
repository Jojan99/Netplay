<?php

namespace App\UseCases\Company;

use App\Models\User;
use App\Repositories\Interfaces\CompanyRepositoryInterface;
use App\UseCases\Company\Interfaces\ConfirmCompanyEmailUseCaseInterface;

class ConfirmCompanyEmailUseCase implements ConfirmCompanyEmailUseCaseInterface
{
    public function __construct(
        private CompanyRepositoryInterface $companyRepository,
    ) {}

    public function confirm(string $token): mixed
    {
        $company = $this->companyRepository->findByVerificationToken($token);

        if (!$company) {
            return ['message' => 'Token inválido o ya fue utilizado.', 'status' => 1, 'data' => null];
        }

        $this->companyRepository->confirmEmail($company->id);

        // Activar el usuario admin de la empresa
        User::where('company_id', $company->id)
            ->update(['active' => 1]);

        return [
            'message' => 'Correo confirmado. Tu empresa ya está activa.',
            'status'  => 0,
            'data'    => ['company' => $company->name],
        ];
    }
}

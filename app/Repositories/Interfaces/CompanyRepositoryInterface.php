<?php

namespace App\Repositories\Interfaces;

use App\Http\Requests\Company\RegisterCompanyRequest;

interface CompanyRepositoryInterface
{
    public function createCompany(RegisterCompanyRequest $data, string $token): mixed;
    public function findByVerificationToken(string $token): mixed;
    public function confirmEmail(int $companyId): void;
}

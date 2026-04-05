<?php

namespace App\Repositories;

use App\Http\Requests\Company\RegisterCompanyRequest;
use App\Models\Company;
use App\Repositories\Interfaces\CompanyRepositoryInterface;

class CompanyRepository implements CompanyRepositoryInterface
{
    public function createCompany(RegisterCompanyRequest $data, string $token): mixed
    {
        return Company::create([
            'name'               => $data['name'],
            'nit'                => $data['nit'],
            'email'              => $data['email'],
            'phone'              => $data['phone'],
            'address'            => $data['address'],
            'active'             => 0,
            'verification_token' => $token,
        ]);
    }

    public function findByVerificationToken(string $token): mixed
    {
        return Company::where('verification_token', $token)->first();
    }

    public function confirmEmail(int $companyId): void
    {
        Company::where('id', $companyId)->update([
            'active'             => 1,
            'email_verified_at'  => now(),
            'verification_token' => null,
        ]);
    }
}

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
            'slug'               => $this->uniqueSlug($data['name']),
            'nit'                => $data['nit'],
            'email'              => $data['email'],
            'phone'              => $data['phone'],
            'address'            => $data['address'],
            'active'             => 0,
            'verification_token' => $token,
            // Datos de la factura: por defecto los mismos de la empresa (se editan luego)
            'invoice_business_name' => $data['name'],
            'invoice_nit'           => $data['nit'],
            'invoice_phone'         => $data['phone'],
            'invoice_address'       => $data['address'],
            'invoice_city'          => $data['city'] ?? null,
            'invoice_country'       => $data['country'] ?? 'Colombia',
            'invoice_prefix'        => $data['invoice_prefix'] ?? 'FAC',
        ]);
    }

    /** El slug identifica a la empresa en las URL públicas (pagos, webhooks) y es único. */
    private function uniqueSlug(string $name): string
    {
        $base = \Illuminate\Support\Str::slug($name) ?: 'empresa';
        $base = \Illuminate\Support\Str::limit($base, 90, '');
        $slug = $base;
        $i = 2;
        while (Company::where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i++;
        }
        return $slug;
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

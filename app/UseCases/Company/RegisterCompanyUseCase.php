<?php

namespace App\UseCases\Company;

use App\Http\Requests\Company\RegisterCompanyRequest;
use App\Models\User;
use App\Models\UserData;
use App\Repositories\Interfaces\CompanyRepositoryInterface;
use App\Resources\TemplatesEmail\TemplateEmailCompanyConfirmation;
use App\Services\WhatsAppApiService;
use App\UseCases\Company\Interfaces\RegisterCompanyUseCaseInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class RegisterCompanyUseCase implements RegisterCompanyUseCaseInterface
{
    public function __construct(
        private CompanyRepositoryInterface $companyRepository,
        private TemplateEmailCompanyConfirmation $emailTemplate,
    ) {}

    public function register(RegisterCompanyRequest $data): mixed
    {
        try {
            $token = Str::uuid()->toString();

            $company = $this->companyRepository->createCompany($data, $token);

            // Crear roles y módulos para la empresa PRIMERO para obtener el profile_id del ADMIN
            $now = now();
            $moduleDefaults = [
                'ADMIN'    => [
                    'usuario', 'finanzas', 'egresos', 'report-paid', 'history-facture',
                    'created-ticket', 'view-ticket', 'olt-detail', 'olt-admin', 'router',
                    'staff', 'billing-config', 'inventory', 'mikrotik', 'resumen', 'crm', 'whatsapp',
                ],
                'TECNICO'  => ['created-ticket', 'view-ticket', 'crm'],
                'CONTADOR' => ['finanzas', 'egresos', 'report-paid', 'history-facture', 'inventory', 'resumen'],
            ];

            $adminProfileId = null;
            foreach (['ADMIN', 'TECNICO', 'CONTADOR'] as $roleName) {
                $profileId = DB::table('profiles')->insertGetId([
                    'company_id' => $company->id,
                    'name'       => $roleName,
                    'active'     => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                if ($roleName === 'ADMIN') {
                    $adminProfileId = $profileId;
                }

                $moduleRows = array_map(fn($m) => [
                    'profile_id' => $profileId,
                    'module'     => $m,
                    'active'     => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $moduleDefaults[$roleName]);

                DB::table('profile_modules')->insert($moduleRows);
            }

            // Crear usuario admin de la empresa con el profile_id real de esta empresa
            User::create([
                'username'   => $data['nit'],
                'email'      => $data['email'],
                'password'   => Hash::make($data['admin_password']),
                'profile_id' => $adminProfileId,
                'company_id' => $company->id,
                'active'     => 0,
            ]);

            // Aprovisionar empresa en whatsapp-service (sin lanzar excepción si falla)
            try {
                $waService = new WhatsAppApiService();
                $waCompany = $waService->provisionCompany($data['name'], $data['email'], 5);
                $waId  = $waCompany['company']['id']      ?? $waCompany['companyId'] ?? null;
                $waKey = $waCompany['company']['api_key'] ?? $waCompany['apiKey']    ?? null;
                if ($waId && $waKey) {
                    $company->update(['wa_company_id' => $waId, 'wa_api_key' => $waKey]);
                    // La suscripción se activa automáticamente en el WA service al crear la empresa
                }
            } catch (\Throwable) {
                // No bloquear el registro si el servicio WA no responde
            }

            // Enviar correo de confirmación solo a la empresa
            $confirmUrl = env('APP_URL') . '/api/company/confirm/' . $token;
            $this->emailTemplate->sendConfirmation($data['email'], $data['name'], $confirmUrl);

        } catch (QueryException $e) {
            return ['message' => 'Error al registrar la empresa: ' . $e->getMessage(), 'status' => 1, 'data' => null];
        }

        return [
            'message' => 'Empresa registrada. Revisa tu correo para confirmar la cuenta.',
            'status'  => 0,
            'data'    => null,
        ];
    }
}

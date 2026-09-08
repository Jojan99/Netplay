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
use App\Support\Modules;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RegisterCompanyUseCase implements RegisterCompanyUseCaseInterface
{
    public function __construct(
        private CompanyRepositoryInterface $companyRepository,
        private TemplateEmailCompanyConfirmation $emailTemplate,
    ) {}

    public function register(RegisterCompanyRequest $data): mixed
    {
        $token = Str::uuid()->toString();

        try {
            // Todo o nada: si algo falla no queda una empresa a medias (sin perfiles ni admin).
            $company = DB::transaction(function () use ($data, $token) {
                $company = $this->companyRepository->createCompany($data, $token);
                $now = now();

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
                    ], Modules::defaultsFor($roleName));

                    if ($moduleRows) {
                        DB::table('profile_modules')->insert($moduleRows);
                    }
                }

                // Usuario administrador de la empresa
                $user = User::create([
                    'username'   => $data['admin_username'] ?? $data['nit'],
                    'email'      => $data['email'],
                    'password'   => Hash::make($data['admin_password']),
                    'profile_id' => $adminProfileId,
                    'company_id' => $company->id,
                    'active'     => 0,
                ]);

                // Ficha del administrador: sin ella el panel no muestra nombre ni permite editar el perfil
                // role_id tiene FK a profiles y su default (0) no existe, hay que fijarlo
                UserData::create([
                    'role_id'    => $adminProfileId,
                    'company_id' => $company->id,
                    'user_id'    => $user->id,
                    'names'      => $data['admin_name'],
                    'lastname'   => $data['admin_lastname'],
                    'email'      => $data['email'],
                    'phone'      => $data['admin_phone'] ?? $data['phone'],
                    'address'    => $data['address'],
                    'dni'        => $data['admin_dni'] ?? $data['nit'],
                    'birthday'   => '',
                    'active'     => 1,
                ]);

                return $company;
            });

            // Aprovisionar la empresa en el servicio de WhatsApp (no bloquea el registro)
            try {
                $waService = new WhatsAppApiService();
                $waCompany = $waService->provisionCompany($data['name'], $data['email'], 5);
                $waId  = $waCompany['company']['id']      ?? $waCompany['companyId'] ?? null;
                $waKey = $waCompany['company']['api_key'] ?? $waCompany['apiKey']    ?? null;
                if ($waId && $waKey) {
                    $company->update(['wa_company_id' => $waId, 'wa_api_key' => $waKey]);
                }
            } catch (\Throwable $e) {
                Log::warning('[Registro empresa] WhatsApp no aprovisionado', ['company_id' => $company->id, 'error' => $e->getMessage()]);
            }

            // Correo de confirmación (tampoco debe tumbar el registro)
            try {
                $confirmUrl = rtrim(config('app.url'), '/') . '/api/company/confirm/' . $token;
                $this->emailTemplate->sendConfirmation($data['email'], $data['name'], $confirmUrl);
            } catch (\Throwable $e) {
                Log::warning('[Registro empresa] correo de confirmación no enviado', ['company_id' => $company->id, 'error' => $e->getMessage()]);
                return [
                    'message' => 'Empresa registrada, pero no pudimos enviar el correo de confirmación. Escribinos para activarla.',
                    'status'  => 0,
                    'data'    => ['company_id' => $company->id, 'email_sent' => false],
                ];
            }
        } catch (QueryException $e) {
            Log::error('[Registro empresa] error de base de datos', ['error' => $e->getMessage()]);
            return ['message' => $this->friendlyDbError($e), 'status' => 1, 'data' => null];
        } catch (\Throwable $e) {
            Log::error('[Registro empresa] error inesperado', ['error' => $e->getMessage()]);
            return ['message' => 'No pudimos registrar la empresa. Intentá de nuevo en unos minutos.', 'status' => 1, 'data' => null];
        }

        return [
            'message' => 'Empresa registrada. Revisá tu correo para confirmar la cuenta.',
            'status'  => 0,
            'data'    => ['company_id' => $company->id, 'email_sent' => true],
        ];
    }

    /** Traduce los errores típicos de base de datos a algo que el usuario entienda. */
    private function friendlyDbError(QueryException $e): string
    {
        $msg = $e->getMessage();
        if (str_contains($msg, 'companies_nit_unique') || str_contains($msg, "for key 'nit'")) return 'Ya existe una empresa registrada con ese NIT.';
        if (str_contains($msg, 'companies_email_unique') || str_contains($msg, "for key 'email'")) return 'Ya existe una empresa registrada con ese correo.';
        if (str_contains($msg, 'companies_slug_unique')) return 'Ya existe una empresa con un nombre muy parecido. Probá con otro nombre.';
        if (str_contains($msg, 'Duplicate entry')) return 'Alguno de los datos ya está registrado. Revisá NIT, correo y usuario.';
        return 'No pudimos registrar la empresa. Revisá los datos e intentá de nuevo.';
    }
}

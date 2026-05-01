<?php

namespace App\Http\Controllers;

use App\Http\Requests\Company\CreateStaffRequest;
use App\Http\Requests\Company\RegisterCompanyRequest;
use App\Http\Requests\Company\UpdateBillingConfigRequest;
use App\Models\Company;
use App\Models\CompanyBillingSchedule;
use App\Models\UserData;
use App\Models\WhatsappPlanRequest;
use App\Models\WaNotificationRoute;
use App\Services\AutoSuspendService;
use App\Services\NotificationRouterService;
use App\Services\WhatsAppApiService;
use App\UseCases\Company\Interfaces\ConfirmCompanyEmailUseCaseInterface;
use App\UseCases\Company\Interfaces\CreateStaffUseCaseInterface;
use App\UseCases\Company\Interfaces\GetStaffUseCaseInterface;
use App\UseCases\Company\Interfaces\RegisterCompanyUseCaseInterface;
use App\UseCases\Company\Interfaces\UpdateBillingConfigUseCaseInterface;
use App\UseCases\Company\Interfaces\GetBillingConfigUseCaseInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class CompanyController extends Controller
{
    /**
     * POST /api/company/register
     * Registra una nueva empresa y envía correo de confirmación.
     */
    public function register(
        RegisterCompanyRequest $request,
        RegisterCompanyUseCaseInterface $registerCompanyUseCase
    ): object {
        $result = $registerCompanyUseCase->register($request);

        return standardApiReponse(
            $result['message'],
            $result['data'],
            $result['status'],
            JsonResponse::HTTP_OK
        );
    }

    /**
     * GET /api/company/confirm/{token}
     * Confirma el correo de la empresa activando la cuenta.
     */
    public function confirmEmail(
        string $token,
        ConfirmCompanyEmailUseCaseInterface $confirmCompanyEmailUseCase
    ): object {
        $result = $confirmCompanyEmailUseCase->confirm($token);

        return standardApiReponse(
            $result['message'],
            $result['data'],
            $result['status'],
            JsonResponse::HTTP_OK
        );
    }

    /**
     * POST /api/company/staff/create
     * Crea un usuario de staff (admin, técnico, contador) para la empresa en sesión.
     */
    public function createStaff(
        CreateStaffRequest $request,
        CreateStaffUseCaseInterface $createStaffUseCase
    ): object {
        $result = $createStaffUseCase->create($request);

        return standardApiReponse(
            $result['message'],
            $result['data'],
            $result['status'],
            JsonResponse::HTTP_OK
        );
    }

    /**
     * GET /api/company/staff
     * Lista todos los usuarios de staff de la empresa en sesión.
     */
    public function getStaff(
        GetStaffUseCaseInterface $getStaffUseCase
    ): object {
        $result = $getStaffUseCase->getAll();

        return standardApiReponse(
            $result['message'],
            $result['data'],
            $result['status'],
            JsonResponse::HTTP_OK
        );
    }

    /**
     * PUT /api/company/billing-config
     * Configura los días de corte automático de facturación para la empresa.
     */
    public function updateBillingConfig(
        UpdateBillingConfigRequest $request,
        UpdateBillingConfigUseCaseInterface $updateBillingConfigUseCase
    ): object {
        $result = $updateBillingConfigUseCase->update($request);

        return standardApiReponse(
            $result['message'],
            $result['data'],
            $result['status'],
            JsonResponse::HTTP_OK
        );
    }

    /**
     * GET /api/company/billing-config
     * Retorna la configuración de facturación de la empresa en sesión.
     */
    public function getBillingConfig(): object
    {
        $companyId = getSessionCompanyId();
        $schedules = CompanyBillingSchedule::where('company_id', $companyId)
            ->orderBy('grupo')
            ->get(['grupo', 'billing_day', 'billing_hour', 'active']);

        return standardApiReponse('OK', ['schedules' => $schedules], false, JsonResponse::HTTP_OK);
    }

    /**
     * POST /api/company/billing-run
     * Ejecuta manualmente el proceso de facturación para un grupo específico.
     */
    public function billingRun(Request $request): object
    {
        $companyId = getSessionCompanyId();
        $grupo     = (int) $request->input('grupo', 1);

        $schedule = CompanyBillingSchedule::where('company_id', $companyId)
            ->where('grupo', $grupo)
            ->first();

        if (!$schedule) {
            return standardApiReponse('Grupo no encontrado.', null, true, JsonResponse::HTTP_NOT_FOUND);
        }

        $now          = \Carbon\Carbon::now();
        $billingMonth = (int) $request->input('billing_month', $now->month);
        $billingYear  = (int) $request->input('billing_year',  $now->year);

        Artisan::call('post:create', [
            'company_id'    => $companyId,
            'periodo'       => $grupo,
            'billing_day'   => $schedule->billing_day,
            'billing_month' => $billingMonth,
            'billing_year'  => $billingYear,
        ]);

        return standardApiReponse('Proceso ejecutado correctamente.', null, false, JsonResponse::HTTP_OK);
    }

    // ═══════════════════════════════════════════════════════════
    // GRUPOS — info y traslado de usuarios
    // ═══════════════════════════════════════════════════════════

    /**
     * GET /api/company/groups/info
     * Retorna cuántos usuarios tiene cada grupo de la empresa.
     */
    public function groupsInfo(): JsonResponse
    {
        $companyId = getSessionCompanyId();
        $counts = \Illuminate\Support\Facades\DB::table('cab_facturations')
            ->where('company_id', $companyId)
            ->select('group', \Illuminate\Support\Facades\DB::raw('COUNT(*) as users_count'))
            ->groupBy('group')
            ->pluck('users_count', 'group');

        return standardApiReponse('OK', $counts, false, JsonResponse::HTTP_OK);
    }

    /**
     * POST /api/company/groups/transfer
     * Traslada todos los usuarios de un grupo a otro.
     * body: { from_grupo, to_grupo }
     */
    public function transferGroup(Request $request): JsonResponse
    {
        $companyId = getSessionCompanyId();
        $from = (int) $request->input('from_grupo');
        $to   = (int) $request->input('to_grupo');

        if ($from === $to) {
            return standardApiReponse('Los grupos son iguales.', null, true, JsonResponse::HTTP_OK);
        }

        $updated = \Illuminate\Support\Facades\DB::table('cab_facturations')
            ->where('company_id', $companyId)
            ->where('group', $from)
            ->update(['group' => $to]);

        return standardApiReponse(
            "{$updated} usuario(s) trasladados al grupo {$to}.",
            ['updated' => $updated],
            false,
            JsonResponse::HTTP_OK
        );
    }

    // ═══════════════════════════════════════════════════════════
    // WHATSAPP — gestión dinámica por empresa
    // ═══════════════════════════════════════════════════════════

    /**
     * GET /api/company/whatsapp/config
     * Retorna la configuración WA de la empresa en sesión,
     * incluyendo el estado de suscripción desde el WA service.
     */
    public function getWhatsAppConfig(): object
    {
        $company = Company::findOrFail(getSessionCompanyId());

        $subscription = null;
        if ($company->wa_api_key) {
            try {
                $me = (new WhatsAppApiService())->getCompanyMe($company->wa_api_key);
                $subscription = [
                    'status'     => $me['company']['subscription_status'] ?? 'inactive',
                    'plan'       => $me['company']['plan_id']             ?? null,
                    'expires_at' => null,
                ];
            } catch (\Throwable) {
                // WA service no disponible — omitir suscripción
            }
        }

        return standardApiReponse('OK', [
            'wa_company_id'    => $company->wa_company_id,
            'wa_instance_id'   => $company->wa_instance_id,
            'whatsapp_enabled' => (bool) $company->whatsapp_enabled,
            'has_credentials'  => !empty($company->wa_api_key),
            'subscription'     => $subscription,
        ], false, JsonResponse::HTTP_OK);
    }

    /**
     * PUT /api/company/whatsapp/config
     * Actualiza instancia por defecto y/o habilita/deshabilita WA.
     * body: { wa_instance_id?, whatsapp_enabled? }
     */
    public function updateWhatsAppConfig(Request $request): object
    {
        $company = Company::findOrFail(getSessionCompanyId());

        $fields = $request->only(['wa_instance_id', 'whatsapp_enabled']);
        $company->update($fields);

        return standardApiReponse('Configuración actualizada', null, false, JsonResponse::HTTP_OK);
    }

    /**
     * GET /api/company/whatsapp/instances
     * Lista instancias desde el whatsapp-service para esta empresa.
     */
    public function getWhatsAppInstances(): object
    {
        $company = Company::findOrFail(getSessionCompanyId());

        if (!$company->wa_api_key) {
            return standardApiReponse('Empresa sin credenciales WA', [], false, JsonResponse::HTTP_OK);
        }

        try {
            $result = (new WhatsAppApiService())->getInstances($company->wa_api_key);
            return standardApiReponse('OK', $result['instances'] ?? [], false, JsonResponse::HTTP_OK);
        } catch (\Throwable $e) {
            return standardApiReponse($e->getMessage(), null, true, JsonResponse::HTTP_OK);
        }
    }

    /**
     * POST /api/company/whatsapp/instances
     * Crea una instancia WA. El servicio valida el límite del plan.
     * body: { name: string }
     */
    public function createWhatsAppInstance(Request $request): object
    {
        $company = Company::findOrFail(getSessionCompanyId());

        if (!$company->wa_api_key) {
            return standardApiReponse('Empresa sin credenciales WA. Contacta al administrador.', null, true, JsonResponse::HTTP_OK);
        }

        try {
            $result = (new WhatsAppApiService())->createInstance($company->wa_api_key, $request->input('name', 'principal'));

            // Si la empresa no tiene instancia por defecto, asignar ésta
            if (!$company->wa_instance_id && !empty($result['instanceId'])) {
                $company->update(['wa_instance_id' => $result['instanceId']]);
            }

            return standardApiReponse('Instancia creada. Escanea el QR para conectar.', $result, false, JsonResponse::HTTP_OK);
        } catch (\Throwable $e) {
            return standardApiReponse($e->getMessage(), null, true, JsonResponse::HTTP_OK);
        }
    }

    /**
     * DELETE /api/company/whatsapp/instances/{instanceId}
     */
    public function deleteWhatsAppInstance(string $instanceId): object
    {
        $company = Company::findOrFail(getSessionCompanyId());

        if (!$company->wa_api_key) {
            return standardApiReponse('Sin credenciales WA', null, true, JsonResponse::HTTP_OK);
        }

        try {
            (new WhatsAppApiService())->deleteInstance($company->wa_api_key, $instanceId);

            // Si era la instancia por defecto, limpiar
            if ($company->wa_instance_id === $instanceId) {
                $company->update(['wa_instance_id' => null]);
            }

            return standardApiReponse('Instancia eliminada', null, false, JsonResponse::HTTP_OK);
        } catch (\Throwable $e) {
            return standardApiReponse($e->getMessage(), null, true, JsonResponse::HTTP_OK);
        }
    }

    /**
     * GET /api/company/whatsapp/instances/{instanceId}/status
     */
    public function getWhatsAppInstanceStatus(string $instanceId): object
    {
        $company = Company::findOrFail(getSessionCompanyId());

        if (!$company->wa_api_key) {
            return standardApiReponse('Sin credenciales WA', null, true, JsonResponse::HTTP_OK);
        }

        try {
            $result = (new WhatsAppApiService())->getInstanceStatus($company->wa_api_key, $instanceId);
            return standardApiReponse('OK', $result, false, JsonResponse::HTTP_OK);
        } catch (\Throwable $e) {
            return standardApiReponse($e->getMessage(), null, true, JsonResponse::HTTP_OK);
        }
    }

    /**
     * GET /api/company/whatsapp/instances/{instanceId}/qr
     * Retorna la imagen PNG del QR directamente (proxy al whatsapp-service).
     */
    public function getWhatsAppInstanceQr(string $instanceId)
    {
        $company = Company::findOrFail(getSessionCompanyId());

        if (!$company->wa_api_key) {
            return standardApiReponse('Sin credenciales WA', null, true, JsonResponse::HTTP_OK);
        }

        try {
            $png = (new WhatsAppApiService())->getInstanceQrImage($company->wa_api_key, $instanceId);
            if (!$png) {
                return standardApiReponse('QR no disponible aún. Intenta en unos segundos.', null, true, JsonResponse::HTTP_OK);
            }
            return response($png, 200, [
                'Content-Type'  => 'image/png',
                'Cache-Control' => 'no-store, no-cache',
            ]);
        } catch (\Throwable $e) {
            return standardApiReponse($e->getMessage(), null, true, JsonResponse::HTTP_OK);
        }
    }

    /**
     * POST /api/company/whatsapp/subscribe
     * Activa o renueva la suscripción WA de la empresa en sesión.
     * body: { plan_id?: string, billing_cycle?: string }
     */
    public function subscribeWhatsApp(Request $request): object
    {
        $company = Company::findOrFail(getSessionCompanyId());

        if (!$company->wa_company_id) {
            return standardApiReponse('La empresa no tiene credenciales WA. Contacta al administrador.', null, true, JsonResponse::HTTP_OK);
        }

        try {
            $result = (new WhatsAppApiService())->subscribeCompany(
                $company->wa_company_id,
                $request->input('plan_id', 'plan_pro'),
                $request->input('billing_cycle', 'yearly')
            );
            return standardApiReponse('Suscripción activada correctamente.', $result, false, JsonResponse::HTTP_OK);
        } catch (\Throwable $e) {
            return standardApiReponse($e->getMessage(), null, true, JsonResponse::HTTP_OK);
        }
    }

    /**
     * PUT /api/company/whatsapp/users/{userId}/toggle
     * Habilita o deshabilita WhatsApp para un usuario específico.
     * body: { whatsapp_enabled: bool }
     */
    public function toggleUserWhatsApp(int $userId, Request $request): object
    {
        UserData::where('user_id', $userId)->update([
            'whatsapp_enabled' => (bool) $request->input('whatsapp_enabled', true),
        ]);

        return standardApiReponse('Preferencia de WhatsApp actualizada', null, false, JsonResponse::HTTP_OK);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // WhatsApp plan activation requests
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * POST /api/company/whatsapp/plan-request
     * Any authenticated user can submit a plan activation request.
     */
    public function submitPlanRequest(Request $request): object
    {
        $companyId = getSessionCompanyId();
        $userId    = getSessionUserId();

        // One pending request per company at a time
        $existing = WhatsappPlanRequest::where('company_id', $companyId)
            ->where('status', 'pending')
            ->first();

        if ($existing) {
            return standardApiReponse('Ya tienes una solicitud de activación pendiente.', null, true, JsonResponse::HTTP_OK);
        }

        WhatsappPlanRequest::create([
            'company_id' => $companyId,
            'user_id'    => $userId,
            'notes'      => $request->input('notes', ''),
            'status'     => 'pending',
        ]);

        return standardApiReponse('Solicitud enviada. El administrador la revisará pronto.', null, false, JsonResponse::HTTP_OK);
    }

    /**
     * GET /api/company/whatsapp/plan-requests
     * Admin: list all pending/resolved plan requests for this company.
     */
    public function listPlanRequests(): object
    {
        $requests = WhatsappPlanRequest::where('company_id', getSessionCompanyId())
            ->orderBy('created_at', 'desc')
            ->get();

        return standardApiReponse('OK', $requests, false, JsonResponse::HTTP_OK);
    }

    /**
     * PUT /api/company/whatsapp/plan-requests/{id}
     * Admin: approve or reject a plan request.
     * body: { action: 'approve'|'reject', notes?: string }
     */
    public function resolvePlanRequest(int $id, Request $request): object
    {
        $planRequest = WhatsappPlanRequest::where('id', $id)
            ->where('company_id', getSessionCompanyId())
            ->firstOrFail();

        $action = $request->input('action');
        if (!in_array($action, ['approve', 'reject'])) {
            return standardApiReponse('Acción inválida.', null, true, JsonResponse::HTTP_OK);
        }

        $planRequest->update([
            'status'      => $action === 'approve' ? 'approved' : 'rejected',
            'notes'       => $request->input('notes', $planRequest->notes),
            'resolved_by' => getSessionUserId(),
            'resolved_at' => now(),
        ]);

        // If approved, auto-activate WA subscription for the company
        if ($action === 'approve') {
            $company = Company::findOrFail(getSessionCompanyId());
            if ($company->wa_company_id) {
                try {
                    (new WhatsAppApiService())->subscribeCompany($company->wa_company_id);
                } catch (\Throwable) { /* log but don't fail */ }
            }
        }

        return standardApiReponse(
            $action === 'approve' ? 'Solicitud aprobada y plan activado.' : 'Solicitud rechazada.',
            null, false, JsonResponse::HTTP_OK
        );
    }

    // ═══════════════════════════════════════════════════════════
    // NOTIFICATION ROUTES
    // ═══════════════════════════════════════════════════════════

    /** GET /api/company/notification-routes */
    public function listNotificationRoutes(): JsonResponse
    {
        $routes = WaNotificationRoute::where('company_id', getSessionCompanyId())
            ->orderBy('event_type')->orderBy('id')
            ->get();

        return standardApiReponse('OK', [
            'routes'      => $routes,
            'event_types' => NotificationRouterService::eventLabels(),
        ], false, JsonResponse::HTTP_OK);
    }

    /** POST /api/company/notification-routes */
    public function createNotificationRoute(Request $request): JsonResponse
    {
        $allowed = array_keys(NotificationRouterService::eventLabels());
        $event   = $request->input('event_type');

        if (!in_array($event, $allowed)) {
            return standardApiReponse('Tipo de evento inválido.', null, true, JsonResponse::HTTP_OK);
        }

        $route = WaNotificationRoute::create([
            'company_id'  => getSessionCompanyId(),
            'event_type'  => $event,
            'destination' => $request->input('destination'),
            'label'       => $request->input('label'),
            'enabled'     => true,
        ]);

        return standardApiReponse('Ruta creada.', $route, false, JsonResponse::HTTP_OK);
    }

    /** PUT /api/company/notification-routes/{id} */
    public function updateNotificationRoute(Request $request, int $id): JsonResponse
    {
        $route = WaNotificationRoute::where('id', $id)
            ->where('company_id', getSessionCompanyId())
            ->firstOrFail();

        $route->update(array_filter([
            'destination' => $request->input('destination'),
            'label'       => $request->input('label'),
            'enabled'     => $request->has('enabled') ? (bool) $request->input('enabled') : null,
        ], fn($v) => $v !== null));

        return standardApiReponse('Ruta actualizada.', $route, false, JsonResponse::HTTP_OK);
    }

    /** DELETE /api/company/notification-routes/{id} */
    public function deleteNotificationRoute(int $id): JsonResponse
    {
        WaNotificationRoute::where('id', $id)
            ->where('company_id', getSessionCompanyId())
            ->delete();

        return standardApiReponse('Ruta eliminada.', null, false, JsonResponse::HTTP_OK);
    }

    // ═══════════════════════════════════════════════════════════
    // INVOICE CONFIG
    // ═══════════════════════════════════════════════════════════

    /**
     * GET /api/company/invoice-config
     * Returns the invoice template configuration for the logged-in company.
     */
    public function getInvoiceConfig(): JsonResponse
    {
        $company = Company::findOrFail(getSessionCompanyId());

        return standardApiReponse('OK', [
            'invoice_business_name'     => $company->invoice_business_name     ?? $company->name,
            'invoice_nit'               => $company->invoice_nit               ?? $company->nit,
            'invoice_phone'             => $company->invoice_phone             ?? $company->phone,
            'invoice_address'           => $company->invoice_address           ?? $company->address,
            'invoice_city'              => $company->invoice_city              ?? '',
            'invoice_country'           => $company->invoice_country           ?? 'COLOMBIA',
            'invoice_iva_condition'     => $company->invoice_iva_condition     ?? 'No Aplica',
            'invoice_economic_activity' => $company->invoice_economic_activity ?? '',
            'invoice_payment_info'      => $company->invoice_payment_info      ?? '',
            'invoice_footer'            => $company->invoice_footer            ?? '',
            'invoice_logo_url'          => $company->invoice_logo_url          ?? $company->logo ?? '',
        ], false, JsonResponse::HTTP_OK);
    }

    /**
     * PUT /api/company/invoice-config
     * Updates the invoice template configuration.
     */
    public function updateInvoiceConfig(Request $request): JsonResponse
    {
        $company = Company::findOrFail(getSessionCompanyId());

        $fields = $request->only([
            'invoice_business_name',
            'invoice_nit',
            'invoice_phone',
            'invoice_address',
            'invoice_city',
            'invoice_country',
            'invoice_iva_condition',
            'invoice_economic_activity',
            'invoice_payment_info',
            'invoice_footer',
            'invoice_logo_url',
        ]);

        $company->update($fields);

        return standardApiReponse('Configuración de factura actualizada', null, false, JsonResponse::HTTP_OK);
    }

    /**
     * POST /api/company/invoice-config/logo
     * Uploads a logo image and stores it in public storage.
     * Returns the public URL of the uploaded file.
     */
    public function uploadInvoiceLogo(Request $request): JsonResponse
    {
        $request->validate(['logo' => 'required|image|max:2048']);

        $company = Company::findOrFail(getSessionCompanyId());
        $file = $request->file('logo');

        $fileContent = file_get_contents($file->getRealPath());
        $mimeType = $file->getMimeType();
        $base64Logo = "data:{$mimeType};base64," . base64_encode($fileContent);

        $company->update([
            'invoice_logo_url' => $base64Logo,
            'invoice_logo_base64' => $base64Logo,
        ]);

        return standardApiReponse('Logo subido correctamente', ['url' => $base64Logo], false, JsonResponse::HTTP_OK);
    }

    // ═══════════════════════════════════════════════════════════
    // MÓDULOS POR PERFIL
    // ═══════════════════════════════════════════════════════════

    /**
     * GET /api/company/my-modules
     * Retorna los módulos permitidos para el perfil del usuario en sesión.
     */
    public function getMyModules(): JsonResponse
    {
        // Usar JWT directamente en lugar de la sesión para evitar mezcla entre usuarios
        $user = \Tymon\JWTAuth\Facades\JWTAuth::user();

        $modules = \Illuminate\Support\Facades\DB::table('profile_modules')
            ->where('profile_id', $user->profile_id)
            ->where('active', true)
            ->pluck('module');

        return standardApiReponse('OK', $modules, false, JsonResponse::HTTP_OK);
    }

    /**
     * GET /api/company/profiles/{profileId}/modules
     * Lista todos los módulos del sistema con su estado activo/inactivo para el perfil dado.
     */
    public function getProfileModules(int $profileId): JsonResponse
    {
        $all = [
            'usuario',
            'created-ticket', 'view-ticket', 'installations',
            'finanzas', 'egresos', 'report-paid', 'history-facture', 'resumen',
            'inventory',
            'mikrotik',
            'olt-admin', 'olt-detail', 'router',
            'staff', 'billing-config', 'contratos', 'empleados', 'planes-internet',
            'crm',
            'whatsapp',
            'technician-map',
        ];

        $active = \Illuminate\Support\Facades\DB::table('profile_modules')
            ->where('profile_id', $profileId)
            ->where('active', true)
            ->pluck('module')
            ->toArray();

        $result = array_map(fn($m) => [
            'module' => $m,
            'active' => in_array($m, $active),
        ], $all);

        return standardApiReponse('OK', $result, false, JsonResponse::HTTP_OK);
    }

    /**
     * PUT /api/company/profiles/{profileId}/modules
     * Actualiza los módulos activos para un perfil de la empresa en sesión.
     * body: { modules: string[] }
     */
    public function updateProfileModules(int $profileId, Request $request): JsonResponse
    {
        $user = \Tymon\JWTAuth\Facades\JWTAuth::user();
        $companyId = $user ? $user->company_id : getSessionCompanyId();

        // Verificar que el perfil pertenece a la empresa en sesión
        $exists = \Illuminate\Support\Facades\DB::table('profiles')
            ->where('id', $profileId)
            ->where('company_id', $companyId)
            ->exists();

        if (!$exists) {
            return standardApiReponse('Perfil no encontrado', null, true, JsonResponse::HTTP_NOT_FOUND);
        }

        $modules = $request->input('modules', []);
        $now     = now();
        $all = [
            'usuario',
            'created-ticket', 'view-ticket', 'installations',
            'finanzas', 'egresos', 'report-paid', 'history-facture', 'resumen',
            'inventory',
            'mikrotik',
            'olt-admin', 'olt-detail', 'router',
            'staff', 'billing-config', 'contratos', 'empleados', 'planes-internet',
            'crm',
            'whatsapp',
            'technician-map',
        ];

        foreach ($all as $module) {
            \Illuminate\Support\Facades\DB::table('profile_modules')->upsert(
                [['profile_id' => $profileId, 'module' => $module, 'active' => in_array($module, $modules), 'created_at' => $now, 'updated_at' => $now]],
                ['profile_id', 'module'],
                ['active', 'updated_at']
            );
        }

        return standardApiReponse('Módulos actualizados', null, false, JsonResponse::HTTP_OK);
    }

    // ═══════════════════════════════════════════════════════════
    // COMPANY PROFILES (roles por empresa)
    // ═══════════════════════════════════════════════════════════

    /**
     * GET /api/company/profiles
     * Retorna los roles disponibles para la empresa en sesión.
     */
    public function getProfiles(): JsonResponse
    {
        $profiles = \Illuminate\Support\Facades\DB::table('profiles')
            ->where('company_id', getSessionCompanyId())
            ->select('id', 'name', 'active')
            ->orderBy('id')
            ->get();

        return standardApiReponse('OK', $profiles, false, JsonResponse::HTTP_OK);
    }

    /**
     * PUT /api/company/profiles/{id}
     * Activa o desactiva un rol para la empresa en sesión.
     * body: { active: bool }
     */
    public function updateProfile(int $id, Request $request): JsonResponse
    {
        \Illuminate\Support\Facades\DB::table('profiles')
            ->where('id', $id)
            ->where('company_id', getSessionCompanyId())
            ->update(['active' => (bool) $request->input('active')]);

        return standardApiReponse('Rol actualizado', null, false, JsonResponse::HTTP_OK);
    }

    // ── Auto-suspend ──────────────────────────────────────────────────────────

    public function getAutoSuspendConfig(AutoSuspendService $service): JsonResponse
    {
        $companyId = getSessionCompanyId();
        $config = \Illuminate\Support\Facades\DB::table('auto_suspend_configs')
            ->where('company_id', $companyId)
            ->first();

        return standardApiReponse('OK', [
            'enabled'        => $config ? (bool) $config->enabled : false,
            'days_overdue'   => $config ? (int) $config->days_overdue : 5,
            'suspension_day' => $config ? (int) $config->suspension_day : 5,
            'stats'          => $service->getStats($companyId),
        ], false, JsonResponse::HTTP_OK);
    }

    public function saveAutoSuspendConfig(Request $request, AutoSuspendService $service): JsonResponse
    {
        $companyId     = getSessionCompanyId();
        $enabled       = (bool) $request->input('enabled', false);
        $daysOverdue   = max(1, (int) $request->input('days_overdue', 5));
        $suspensionDay = max(1, min(28, (int) $request->input('suspension_day', 5)));

        \Illuminate\Support\Facades\DB::table('auto_suspend_configs')->updateOrInsert(
            ['company_id' => $companyId],
            [
                'enabled'        => $enabled,
                'days_overdue'   => $daysOverdue,
                'suspension_day' => $suspensionDay,
                'updated_at'     => now(),
                'created_at'     => now(),
            ]
        );

        return standardApiReponse('Configuración guardada.', null, false, JsonResponse::HTTP_OK);
    }

    public function runAutoSuspend(AutoSuspendService $service): JsonResponse
    {
        $companyId = getSessionCompanyId();
        $config    = \Illuminate\Support\Facades\DB::table('auto_suspend_configs')
            ->where('company_id', $companyId)->first();

        $minInvoices = $config ? (int) $config->days_overdue : 1;

        // Importa al log los que ya estaban suspendidos manualmente (solo los nuevos)
        $service->importExistingSuspended($companyId);

        $suspended   = $service->suspendOverdue($companyId, $minInvoices);
        $reactivated = $service->reactivateAllClear($companyId, $minInvoices);

        return standardApiReponse(
            "{$suspended} suspendido(s), {$reactivated} reactivado(s).",
            ['suspended' => $suspended, 'reactivated' => $reactivated],
            false,
            JsonResponse::HTTP_OK
        );
    }

    /** GET /api/company/whatsapp/groups — lista grupos de la instancia activa */
    public function getWhatsAppGroups(): JsonResponse
    {
        $company = Company::findOrFail(getSessionCompanyId());

        if (!$company->wa_api_key || !$company->wa_instance_id) {
            return standardApiReponse('WhatsApp no configurado.', [], false, JsonResponse::HTTP_OK);
        }

        try {
            $waService = new \App\Services\WhatsAppApiService();
            $result    = $waService->getGroups($company->wa_api_key, $company->wa_instance_id);
            return standardApiReponse('OK', $result, false, JsonResponse::HTTP_OK);
        } catch (\Throwable $e) {
            return standardApiReponse('No se pudieron obtener grupos: ' . $e->getMessage(), [], false, JsonResponse::HTTP_OK);
        }
    }
}

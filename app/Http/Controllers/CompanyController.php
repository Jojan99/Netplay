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
use App\Services\Equipo\BajaDePersonal;
use App\Services\NotificationRouterService;
use App\Services\WhatsAppApiService;
use App\Services\WhatsApp\LineasDeWhatsApp;
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
     *
     * Este enlace lo abre una persona desde su cliente de correo, así que
     * responde con una redirección al panel y no con JSON: antes el usuario
     * terminaba mirando una respuesta de API en crudo y tenía que volver a
     * buscar el login por su cuenta.
     *
     * El 'vale' viaja en la URL a propósito y es de un solo uso y vida corta:
     * el frontend lo canjea por la sesión con un POST, así el token real nunca
     * queda en el historial del navegador.
     */
    public function confirmEmail(
        string $token,
        ConfirmCompanyEmailUseCaseInterface $confirmCompanyEmailUseCase,
        Request $request
    ) {
        // Se busca antes de confirmar: al confirmarse el token se borra.
        $companyId = Company::where('verification_token', $token)->value('id');

        $result = $confirmCompanyEmailUseCase->confirm($token);
        $datos  = $result['data'] ?? [];

        // Quien llame pidiendo JSON (una integración, una prueba) lo sigue recibiendo.
        if ($request->wantsJson() && !$request->acceptsHtml()) {
            return standardApiReponse($result['message'], $datos, $result['status'], JsonResponse::HTTP_OK);
        }

        $parametros = ['estado' => $datos['estado'] ?? 'invalido'];

        if (!empty($datos['vale'])) {
            $parametros['vale'] = $datos['vale'];
        }
        if (!empty($datos['company'])) {
            $parametros['empresa'] = $datos['company'];
        }

        // Con subdominios activos se entra ya en la dirección de la empresa: la
        // sesión queda guardada ahí y no en la raíz.
        $base = app(\App\Services\Plataforma\EmpresaDelDominio::class)->urlDe($companyId ? (int) $companyId : null);

        return redirect()->away($base . '/confirm-email?' . http_build_query($parametros));
    }

    /**
     * POST /api/company/confirm-session  { vale }
     * Canjea el vale de un solo uso que dejó la confirmación por una sesión.
     */
    public function confirmSession(Request $request, \App\Services\AccesoDirectoService $acceso): JsonResponse
    {
        $request->validate(['vale' => 'required|string|max:120']);

        $sesion = $acceso->canjear($request->input('vale'));

        if (!$sesion) {
            return response()->json([
                'message' => 'Este enlace ya se usó o venció. Iniciá sesión con tu usuario y contraseña.',
                'data'    => null,
                'error'   => 1,
            ], JsonResponse::HTTP_OK);
        }

        return response()->json(['message' => 'Sesión iniciada', 'data' => $sesion, 'error' => 0]);
    }

    /**
     * POST /api/company/resend-confirmation  { user | email }
     * Vuelve a mandar el correo de activación. Sin esto, si el correo se pierde
     * o cae en spam la empresa queda registrada pero sin poder entrar.
     */
    public function resendConfirmation(Request $request): JsonResponse
    {
        $request->validate(['user' => 'nullable|string|max:120', 'email' => 'nullable|email']);

        $company = null;
        if ($request->filled('user')) {
            $companyId = \App\Models\User::where('username', $request->user)->value('company_id');
            $company = $companyId ? \App\Models\Company::find($companyId) : null;
        }
        if (!$company && $request->filled('email')) {
            $company = \App\Models\Company::where('email', $request->email)->first();
        }

        // Respuesta uniforme: no se revela si la empresa existe o no
        $generico = 'Si la cuenta existe y falta confirmarla, te enviamos el correo de activación.';

        if (!$company) {
            return response()->json(['message' => $generico, 'data' => null, 'error' => 0]);
        }
        if ($company->active) {
            return response()->json(['message' => 'Esa empresa ya está activa. Podés iniciar sesión.', 'data' => null, 'error' => 0]);
        }

        if (!$company->verification_token) {
            $company->verification_token = \Illuminate\Support\Str::uuid()->toString();
            $company->save();
        }

        try {
            $url = rtrim(config('app.url'), '/') . '/api/company/confirm/' . $company->verification_token;
            app(\App\Resources\TemplatesEmail\TemplateEmailCompanyConfirmation::class)
                ->sendConfirmation($company->email, $company->name, $url);
        } catch (\Throwable $e) {
            \Log::error('[Reenvío confirmación] falló', ['company_id' => $company->id, 'error' => $e->getMessage()]);
            return response()->json(['message' => 'No pudimos enviar el correo. Escribinos para activarte la cuenta.', 'data' => null, 'error' => 1], 502);
        }

        return response()->json(['message' => $generico, 'data' => null, 'error' => 0]);
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
     * DELETE /api/company/staff/{id}
     * Da de baja una cuenta del equipo. Si no tiene trabajo a su nombre se
     * borra; si ya trabajó, se desactiva para no romper el historial.
     */
    public function deleteStaff(int $id, BajaDePersonal $baja): object
    {
        $result = $baja->eliminar($id);

        return standardApiReponse($result['message'], $result['data'], $result['status'], JsonResponse::HTTP_OK);
    }

    /**
     * POST /api/company/staff/{id}/reactivar
     * Vuelve a habilitar una cuenta del equipo que había sido desactivada.
     */
    public function reactivateStaff(int $id, BajaDePersonal $baja): object
    {
        $result = $baja->reactivar($id);

        return standardApiReponse($result['message'], $result['data'], $result['status'], JsonResponse::HTTP_OK);
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
        if ($company->wa_api_key && $company->wa_provider !== 'meta') {
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
            'wa_provider'         => $company->wa_provider ?? 'netplay',
            'wa_company_id'       => $company->wa_company_id,
            'wa_instance_id'      => $company->wa_instance_id,
            'wa_phone_number_id'  => $company->wa_phone_number_id,
            'wa_business_id'      => $company->wa_business_id,
            'wa_access_token'     => $company->wa_access_token ? $this->maskToken($company->wa_access_token) : null,
            'whatsapp_enabled'    => (bool) $company->whatsapp_enabled,
            'has_credentials'     => !empty($company->wa_api_key) || ($company->wa_provider === 'meta' && $company->wa_access_token),
            'subscription'        => $subscription,
        ], false, JsonResponse::HTTP_OK);
    }

    /**
     * PUT /api/company/whatsapp/config
     * Actualiza instancia por defecto y/o habilita/deshabilita WA.
     * body: { wa_provider?, wa_instance_id?, wa_phone_number_id?, wa_access_token?, whatsapp_enabled? }
     */
    public function updateWhatsAppConfig(Request $request): object
    {
        $company = Company::findOrFail(getSessionCompanyId());

        $fields = $request->only([
            'wa_provider',
            'wa_instance_id',
            'wa_phone_number_id',
            'wa_business_id',
            'wa_access_token',
            'whatsapp_enabled',
        ]);

        // Validar provider
        if (isset($fields['wa_provider']) && !in_array($fields['wa_provider'], ['netplay', 'meta'])) {
            return standardApiReponse('Provider inválido. Use: netplay o meta', null, true, JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

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

            // El catálogo se pone al día con lo que dice el servicio y cada
            // instancia sale marcada si es la línea principal de la empresa.
            $lineas = (new LineasDeWhatsApp())->sincronizar((int) $company->id);
            $porId  = collect($lineas)->keyBy('instance_id');

            $instancias = array_map(function ($inst) use ($porId) {
                $id = $inst['instanceId'] ?? $inst['id'] ?? null;
                $l  = $id ? $porId->get($id) : null;

                return $inst + [
                    'principal' => (bool) ($l['principal'] ?? false),
                    'linea_id'  => $l['id'] ?? null,
                ];
            }, $result['instances'] ?? []);

            return standardApiReponse('OK', $instancias, false, JsonResponse::HTTP_OK);
        } catch (\Throwable $e) {
            return standardApiReponse($e->getMessage(), null, true, JsonResponse::HTTP_OK);
        }
    }

    /**
     * GET /api/company/whatsapp/lineas
     * El catálogo de líneas de la empresa (sincronizado con el servicio Node).
     */
    public function getWhatsAppLineas(): object
    {
        $company = Company::findOrFail(getSessionCompanyId());

        (new LineasDeWhatsApp())->sincronizar((int) $company->id);

        return standardApiReponse('OK', LineasDeWhatsApp::deEmpresa((int) $company->id), false, JsonResponse::HTTP_OK);
    }

    /**
     * PUT /api/company/whatsapp/lineas/{lineaId}/principal
     * Cambia la línea principal: la que usan las facturas, los avisos y todo lo
     * que no cuelga de una conversación del CRM.
     */
    public function setWhatsAppLineaPrincipal(int $lineaId): object
    {
        $companyId = (int) getSessionCompanyId();

        if (!(new LineasDeWhatsApp())->marcarPrincipal($companyId, $lineaId)) {
            return standardApiReponse('Esa línea no existe o no está activa.', null, true, JsonResponse::HTTP_OK);
        }

        return standardApiReponse('Línea principal actualizada', LineasDeWhatsApp::deEmpresa($companyId), false, JsonResponse::HTTP_OK);
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

            // La línea nueva entra al catálogo: es lo que permite que un mensaje
            // que llegue por ella resuelva empresa y caiga en la bandeja.
            if (!empty($result['instanceId'])) {
                (new LineasDeWhatsApp())->registrar(
                    (int) $company->id,
                    (string) $result['instanceId'],
                    (string) $request->input('name', 'principal')
                );
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
            // Una instancia de OTRA empresa no se borra desde acá aunque el id
            // llegue en la URL. Si no figura en el catálogo se sincroniza antes
            // de rechazar: puede ser una línea recién creada desde el panel.
            if (LineasDeWhatsApp::disponible() && !LineasDeWhatsApp::porInstancia($instanceId, (int) $company->id)) {
                (new LineasDeWhatsApp())->sincronizar((int) $company->id);

                if (!LineasDeWhatsApp::porInstancia($instanceId, (int) $company->id)) {
                    return standardApiReponse('Esa línea no es de tu empresa.', null, true, JsonResponse::HTTP_OK);
                }
            }

            (new WhatsAppApiService())->deleteInstance($company->wa_api_key, $instanceId);

            // Si era la instancia por defecto, limpiar
            if ($company->wa_instance_id === $instanceId) {
                $company->update(['wa_instance_id' => null]);
            }

            // Baja lógica en el catálogo: las conversaciones viejas siguen
            // apuntando a esa línea y perderían el nombre si se borrara.
            (new LineasDeWhatsApp())->darDeBaja((int) $company->id, $instanceId);

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
        UserData::where('user_id', $userId)->where('company_id', getSessionCompanyId())->update([
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
    // AVISOS Y DESTINOS (notification routes)
    // ═══════════════════════════════════════════════════════════

    /**
     * GET /api/company/notification-routes
     *
     * Todo lo que necesita la pantalla: el catálogo de avisos con su
     * explicación, a dónde va cada uno hoy y si la línea de WhatsApp Web
     * está vinculada (sin ella no hay grupos).
     */
    public function listNotificationRoutes(): JsonResponse
    {
        $companyId = getSessionCompanyId();

        // El grupo de alertas que quedó del comando pasa a ser una ruta más, la
        // primera vez que la empresa entra acá. Idempotente: la migración hace
        // lo mismo para todas de una.
        \App\Services\Alertas\AvisosAlGrupo::materializarEspejo($companyId);

        $routes = WaNotificationRoute::where('company_id', $companyId)
            ->orderBy('event_type')->orderBy('id')
            ->get()
            ->map(fn ($r) => [
                'id'          => (int) $r->id,
                'event_type'  => $r->event_type,
                'destination' => $r->destination,
                'label'       => NotificationRouterService::textoLimpio($r->label),
                'enabled'     => (bool) $r->enabled,
            ])
            ->values();

        $company = Company::findOrFail($companyId);

        return standardApiReponse('OK', [
            'routes'      => $routes,
            'eventos'     => NotificationRouterService::catalogo(),
            'event_types' => NotificationRouterService::eventLabels(),
            'linea'       => [
                'conectada'   => (bool) ($company->wa_instance_id && $company->wa_api_key),
                'instancia'   => $company->wa_instance_id,
                'wa_activado' => (bool) $company->whatsapp_enabled,
            ],
        ], false, JsonResponse::HTTP_OK);
    }

    /** POST /api/company/notification-routes */
    public function createNotificationRoute(Request $request): JsonResponse
    {
        $companyId = getSessionCompanyId();
        $event     = (string) $request->input('event_type');

        if (!array_key_exists($event, NotificationRouterService::eventLabels())) {
            return standardApiReponse('Ese tipo de aviso no existe.', null, true, JsonResponse::HTTP_OK);
        }

        $destino = $this->destinoDeAviso($request->input('destination'), $event);

        if (is_string($destino['error'] ?? null)) {
            return standardApiReponse($destino['error'], null, true, JsonResponse::HTTP_OK);
        }

        $yaEsta = WaNotificationRoute::where('company_id', $companyId)
            ->where('event_type', $event)
            ->where('destination', $destino['valor'])
            ->exists();

        if ($yaEsta) {
            return standardApiReponse('Ese destino ya está en este aviso.', null, true, JsonResponse::HTTP_OK);
        }

        $route = WaNotificationRoute::create([
            'company_id'  => $companyId,
            'event_type'  => $event,
            'destination' => $destino['valor'],
            'label'       => NotificationRouterService::textoLimpio($request->input('label')),
            'enabled'     => true,
        ]);

        $this->despuesDeTocarAvisos($companyId, $event, $destino['valor'], true);

        return standardApiReponse('Destino agregado.', $route, false, JsonResponse::HTTP_OK);
    }

    /** PUT /api/company/notification-routes/{id} */
    public function updateNotificationRoute(Request $request, int $id): JsonResponse
    {
        $companyId = getSessionCompanyId();

        $route = WaNotificationRoute::where('id', $id)
            ->where('company_id', $companyId)
            ->firstOrFail();

        $cambios = [];

        if ($request->filled('destination')) {
            $destino = $this->destinoDeAviso($request->input('destination'), $route->event_type);

            if (is_string($destino['error'] ?? null)) {
                return standardApiReponse($destino['error'], null, true, JsonResponse::HTTP_OK);
            }

            $repetido = WaNotificationRoute::where('company_id', $companyId)
                ->where('event_type', $route->event_type)
                ->where('destination', $destino['valor'])
                ->where('id', '!=', $route->id)
                ->exists();

            if ($repetido) {
                return standardApiReponse('Ese destino ya está en este aviso.', null, true, JsonResponse::HTTP_OK);
            }

            $cambios['destination'] = $destino['valor'];
        }

        if ($request->has('label')) {
            $cambios['label'] = NotificationRouterService::textoLimpio($request->input('label'));
        }

        if ($request->has('enabled')) {
            $cambios['enabled'] = $request->boolean('enabled');
        }

        if ($cambios) {
            $route->update($cambios);
        }

        $this->despuesDeTocarAvisos($companyId, $route->event_type, $route->destination, false);

        return standardApiReponse('Aviso actualizado.', $route, false, JsonResponse::HTTP_OK);
    }

    /** DELETE /api/company/notification-routes/{id} */
    public function deleteNotificationRoute(int $id): JsonResponse
    {
        $companyId = getSessionCompanyId();

        $route = WaNotificationRoute::where('id', $id)
            ->where('company_id', $companyId)
            ->first();

        if (!$route) {
            return standardApiReponse('Ese destino ya no existe.', null, false, JsonResponse::HTTP_OK);
        }

        $evento = $route->event_type;
        $route->delete();

        $this->despuesDeTocarAvisos($companyId, $evento, null, false);

        return standardApiReponse('Destino eliminado.', null, false, JsonResponse::HTTP_OK);
    }

    /**
     * POST /api/company/notification-routes/probar
     *
     * Manda un mensaje de prueba al destino, por la línea de la empresa de la
     * sesión y sólo a un destino que esté guardado en sus propios avisos.
     */
    public function probarNotificationRoute(Request $request): JsonResponse
    {
        $companyId = getSessionCompanyId();

        $route = WaNotificationRoute::where('company_id', $companyId)
            ->where('id', (int) $request->input('id'))
            ->first();

        if (!$route) {
            return standardApiReponse('No encontramos ese destino en tus avisos.', null, true, JsonResponse::HTTP_OK);
        }

        $company = Company::findOrFail($companyId);

        if (!$company->wa_instance_id || !$company->wa_api_key) {
            return standardApiReponse('La empresa no tiene una línea de WhatsApp Web vinculada.', null, true, JsonResponse::HTTP_OK);
        }

        $titulo = NotificationRouterService::eventLabels()[$route->event_type] ?? $route->event_type;
        $texto  = \App\Services\Avisos\MensajeDeAviso::nuevo('Mensaje de prueba', $companyId)
            ->empresa($company->name)
            ->dato('Aviso', $titulo)
            ->dato('Destino', $route->label ?: $route->destination)
            ->fecha('Enviado', now())
            ->cierre('Así van a llegar estos avisos. Es sólo una prueba: no pasó nada en la red ni en el sistema.')
            ->texto();

        try {
            if (in_array($route->event_type, NotificationRouterService::EVENTOS_DE_RED, true)) {
                // Las de red salen forzadas, como las de verdad.
                $ok = (new \App\Services\Alertas\AvisosAlGrupo($companyId))->enviarA($route->destination, $texto);
            } else {
                (new \App\Services\WhatsAppService($companyId, false, 'netplay'))
                    ->mensajeInformativo($route->destination, $texto);
                $ok = true;
            }
        } catch (\Throwable $e) {
            return standardApiReponse('No se pudo enviar: ' . $e->getMessage(), null, true, JsonResponse::HTTP_OK);
        }

        return $ok
            ? standardApiReponse('Mensaje de prueba enviado.', null, false, JsonResponse::HTTP_OK)
            : standardApiReponse('No se pudo enviar. Revisá que la línea siga conectada.', null, true, JsonResponse::HTTP_OK);
    }

    /**
     * Valida y normaliza el destino: un grupo de WhatsApp o un teléfono.
     *
     * @return array{valor?:string, error?:string}
     */
    private function destinoDeAviso(mixed $crudo, string $evento): array
    {
        $texto = trim((string) $crudo);

        if ($texto === '') {
            return ['error' => 'Falta el destino.'];
        }

        if (str_contains($texto, '@g.us')) {
            return preg_match('/^\d{5,32}(-\d{5,20})?@g\.us$/', $texto)
                ? ['valor' => $texto]
                : ['error' => 'Ese grupo no tiene un identificador válido.'];
        }

        if (in_array($evento, NotificationRouterService::EVENTOS_DE_RED, true)) {
            return ['error' => 'Las alertas de red van a un grupo de WhatsApp, no a un número suelto.'];
        }

        $digitos = preg_replace('/\D/', '', str_replace('@s.whatsapp.net', '', $texto));

        // Diez dígitos empezando en 3 es un celular colombiano sin indicativo:
        // el dueño lo escribe así y el servicio necesita el país adelante.
        if (strlen($digitos) === 10 && str_starts_with($digitos, '3')) {
            $digitos = '57' . $digitos;
        }

        if (strlen($digitos) < 10 || strlen($digitos) > 15) {
            return ['error' => 'El número tiene que ir con indicativo del país, por ejemplo 573001234567.'];
        }

        return ['valor' => $digitos];
    }

    /**
     * Después de tocar un aviso de red: deja el espejo de companies al día y,
     * si es un grupo recién agregado, le manda la misma confirmación que
     * mandaba el comando para que los técnicos sepan qué va a llegar ahí.
     */
    private function despuesDeTocarAvisos(int $companyId, string $evento, ?string $destinoNuevo, bool $confirmar): void
    {
        if (!in_array($evento, NotificationRouterService::EVENTOS_DE_RED, true)) {
            return;
        }

        \App\Services\Alertas\AvisosAlGrupo::sincronizarEspejo($companyId);

        if ($confirmar && $destinoNuevo && $evento === 'alerta_red') {
            (new \App\Services\Alertas\AvisosAlGrupo($companyId))
                ->enviarA($destinoNuevo, \App\Services\Alertas\AvisosAlGrupo::textoDeConfirmacion());
        }
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

        $template = null;
        if ($company->invoice_template_id) {
            $template = \App\Models\InvoiceTemplate::where('id', $company->invoice_template_id)
                ->where('company_id', $company->id)
                ->first();
        }
        if (!$template) {
            $template = \App\Models\InvoiceTemplate::where('company_id', $company->id)
                ->where('is_default', true)
                ->first();
        }

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
            'invoice_template_id'       => $company->invoice_template_id,
            'invoice_template'          => $template,
            'invoice_prefix'            => $company->invoice_prefix ?? 'GL',
            'whatsapp_enabled'          => (bool) $company->whatsapp_enabled,
            'invoice_whatsapp_enabled'  => (bool) $company->invoice_whatsapp_enabled,
            'email_enabled'             => (bool) $company->email_enabled,
            'email_daily_limit'         => (int) $company->email_daily_limit,
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
            'invoice_prefix',
            'whatsapp_enabled',
            'invoice_whatsapp_enabled',
            'email_enabled',
            'email_daily_limit',
        ]);

        if (isset($fields['invoice_prefix'])) {
            $fields['invoice_prefix'] = strtoupper(preg_replace('/[^A-Za-z0-9\-]/', '', $fields['invoice_prefix']));
            $fields['invoice_prefix'] = substr($fields['invoice_prefix'], 0, 10) ?: 'GL';
        }

        if (isset($fields['email_daily_limit'])) {
            $fields['email_daily_limit'] = max(0, (int) $fields['email_daily_limit']);
        }

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
        // El perfil tiene que ser de la empresa en sesión (igual que al actualizar)
        $propio = \Illuminate\Support\Facades\DB::table('profiles')
            ->where('id', $profileId)
            ->where('company_id', getSessionCompanyId())
            ->exists();

        if (!$propio) {
            return standardApiReponse('Perfil no encontrado', null, true, JsonResponse::HTTP_NOT_FOUND);
        }

        $catalog = \App\Support\Modules::flat();
        $all = array_column($catalog, 'module');

        $active = \Illuminate\Support\Facades\DB::table('profile_modules')
            ->where('profile_id', $profileId)
            ->where('active', true)
            ->pluck('module')
            ->toArray();

        $result = array_map(fn($m) => $m + ['active' => in_array($m['module'], $active)], $catalog);

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
            'staff', 'billing-config', 'payment-gateway', 'contratos', 'empleados', 'planes-internet',
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
        // days_overdue: número mínimo de facturas cabecera pendientes para activar la suspensión (no son "días")
        $daysOverdue   = max(1, (int) $request->input('days_overdue', 1));
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

        // Retornar las últimas 150 líneas del log del día para inspección inmediata
        $logPath = storage_path('logs/auto-suspend-' . now()->format('Y-m-d') . '.log');
        $logLines = [];
        if (file_exists($logPath)) {
            $all = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $logLines = array_slice($all, -150);
        }

        return standardApiReponse(
            "{$suspended} suspendido(s), {$reactivated} reactivado(s).",
            [
                'suspended'   => $suspended,
                'reactivated' => $reactivated,
                'log'         => $logLines,
                'log_file'    => $logPath,
            ],
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

        // Los grupos de TODAS las líneas de la empresa, no sólo la principal:
        // con dos líneas la mitad de los grupos no aparecía.
        $lineas = LineasDeWhatsApp::deEmpresa((int) $company->id)
            ?: [['instance_id' => $company->wa_instance_id, 'nombre' => 'Principal', 'id' => null]];

        $waService = new \App\Services\WhatsAppApiService();
        $grupos    = [];

        foreach ($lineas as $linea) {
            try {
                $result = $waService->getGroups($company->wa_api_key, $linea['instance_id']);

                foreach ($result['groups'] ?? [] as $g) {
                    $grupos[] = $g + ['linea_id' => $linea['id'], 'linea_nombre' => $linea['nombre']];
                }
            } catch (\Throwable $e) {
                // Una línea desconectada no puede dejar sin grupos a las demás.
                \Illuminate\Support\Facades\Log::warning('[Grupos WA] Línea sin grupos', [
                    'instance_id' => $linea['instance_id'], 'error' => $e->getMessage(),
                ]);
            }
        }

        return standardApiReponse('OK', ['groups' => $grupos], false, JsonResponse::HTTP_OK);
    }

    /**
     * Enmascara un token para mostrar solo los últimos 4 caracteres.
     */
    private function maskToken(?string $token): ?string
    {
        if (!$token) return null;
        if (strlen($token) <= 8) return '***';
        return substr($token, 0, 4) . '...' . substr($token, -4);
    }
}

<?php

use App\Http\Controllers\CompanyController;
use Illuminate\Support\Facades\Route;

Route::prefix('company')->group(function () {

    // ── Públicas (sin JWT) ──────────────────────────────────────────────────
    Route::post('register', [CompanyController::class, 'register'])
        ->withoutMiddleware('jwt.verify');

    Route::get('confirm/{token}', [CompanyController::class, 'confirmEmail'])
        ->withoutMiddleware('jwt.verify');

    // ── Cualquier usuario autenticado ─────────────────────────────────────────
    Route::get('my-modules',             [CompanyController::class, 'getMyModules']);
    Route::post('whatsapp/plan-request', [CompanyController::class, 'submitPlanRequest']);

    // ── Solo admin ──────────────────────────────────────────────────────────
    Route::middleware('role:admin')->group(function () {

        // Staff y facturación
        Route::post('staff/create',  [CompanyController::class, 'createStaff']);
        Route::get('staff',          [CompanyController::class, 'getStaff']);

        // Roles de la empresa
        Route::get('profiles',                             [CompanyController::class, 'getProfiles']);
        Route::put('profiles/{id}',                        [CompanyController::class, 'updateProfile']);
        Route::get('profiles/{profileId}/modules',         [CompanyController::class, 'getProfileModules']);
        Route::put('profiles/{profileId}/modules',         [CompanyController::class, 'updateProfileModules']);
        Route::get('billing-config', [CompanyController::class, 'getBillingConfig']);
        Route::put('billing-config', [CompanyController::class, 'updateBillingConfig']);
        Route::post('billing-run',      [CompanyController::class, 'billingRun']);
        Route::get('groups/info',       [CompanyController::class, 'groupsInfo']);
        Route::post('groups/transfer',  [CompanyController::class, 'transferGroup']);

        // Auto-suspend de clientes por mora
        Route::get('auto-suspend',       [CompanyController::class, 'getAutoSuspendConfig']);
        Route::put('auto-suspend',       [CompanyController::class, 'saveAutoSuspendConfig']);
        Route::post('auto-suspend/run',  [CompanyController::class, 'runAutoSuspend']);

        // Invoice template config
        Route::get('invoice-config',        [CompanyController::class, 'getInvoiceConfig']);
        Route::put('invoice-config',        [CompanyController::class, 'updateInvoiceConfig']);
        Route::post('invoice-config/logo',  [CompanyController::class, 'uploadInvoiceLogo']);

        // Notification routes
        Route::get('notification-routes',         [CompanyController::class, 'listNotificationRoutes']);
        Route::post('notification-routes',        [CompanyController::class, 'createNotificationRoute']);
        Route::put('notification-routes/{id}',    [CompanyController::class, 'updateNotificationRoute']);
        Route::delete('notification-routes/{id}', [CompanyController::class, 'deleteNotificationRoute']);

        // ── WhatsApp dinámico ───────────────────────────────────────────────
        Route::prefix('whatsapp')->group(function () {
            // Configuración de la empresa
            Route::get('config',  [CompanyController::class, 'getWhatsAppConfig']);
            Route::put('config',  [CompanyController::class, 'updateWhatsAppConfig']);

            // Gestión de instancias (límites aplicados por el servicio WA)
            Route::get('instances',                              [CompanyController::class, 'getWhatsAppInstances']);
            Route::post('instances',                             [CompanyController::class, 'createWhatsAppInstance']);
            Route::delete('instances/{instanceId}',              [CompanyController::class, 'deleteWhatsAppInstance']);
            Route::get('instances/{instanceId}/status',          [CompanyController::class, 'getWhatsAppInstanceStatus']);
            Route::get('instances/{instanceId}/qr',              [CompanyController::class, 'getWhatsAppInstanceQr']);

            // Suscripción WA
            Route::post('subscribe',  [CompanyController::class, 'subscribeWhatsApp']);
            Route::get('groups',      [CompanyController::class, 'getWhatsAppGroups']);

            // Solicitudes de activación de plan WA (admin gestiona)
            Route::get('plan-requests',        [CompanyController::class, 'listPlanRequests']);
            Route::put('plan-requests/{id}',   [CompanyController::class, 'resolvePlanRequest']);

            // Toggle WA por usuario
            Route::put('users/{userId}/toggle', [CompanyController::class, 'toggleUserWhatsApp']);
        });
    });
});

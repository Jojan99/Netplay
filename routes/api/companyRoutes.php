<?php

use App\Http\Controllers\CompanyController;
use App\Http\Controllers\InvoiceTemplateController;
use Illuminate\Support\Facades\Route;

Route::prefix('company')->group(function () {

    // ── Públicas (sin JWT) ──────────────────────────────────────────────────
    Route::post('register', [CompanyController::class, 'register'])
        ->withoutMiddleware('jwt.verify');

    // Reenviar el correo de activación (público: la cuenta aún no puede entrar)
    Route::post('resend-confirmation', [CompanyController::class, 'resendConfirmation'])
        ->withoutMiddleware('jwt.verify')
        ->middleware('throttle:5,10');

    Route::get('confirm/{token}', [CompanyController::class, 'confirmEmail'])
        ->withoutMiddleware('jwt.verify');

    // Canje del vale de un solo uso que deja la confirmación, para entrar sin
    // volver a pedir credenciales. Con tope de intentos: el vale es aleatorio
    // pero no hay razón para permitir que alguien pruebe muchos.
    Route::post('confirm-session', [CompanyController::class, 'confirmSession'])
        ->withoutMiddleware('jwt.verify')
        ->middleware('throttle:10,1');

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

        // Invoice templates
        Route::get('invoice-templates',                 [InvoiceTemplateController::class, 'index']);
        Route::get('invoice-templates/preview',         [InvoiceTemplateController::class, 'preview']);
        Route::get('invoice-templates/{id}',            [InvoiceTemplateController::class, 'show']);
        Route::post('invoice-templates',                [InvoiceTemplateController::class, 'store']);
        Route::put('invoice-templates/{id}',            [InvoiceTemplateController::class, 'update']);
        Route::delete('invoice-templates/{id}',         [InvoiceTemplateController::class, 'destroy']);
        Route::put('invoice-templates/{id}/set-default', [InvoiceTemplateController::class, 'setDefault']);

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

            // ── Meta WhatsApp API Official ─────────────────────────────────────
            Route::prefix('meta')->group(function () {
                Route::get('phone-info',            [\App\Http\Controllers\MetaWhatsAppController::class, 'getPhoneInfo']);
                Route::get('templates',             [\App\Http\Controllers\MetaWhatsAppController::class, 'getTemplates']);
                Route::post('templates',            [\App\Http\Controllers\MetaWhatsAppController::class, 'createTemplate']);
                Route::delete('templates/{name}',   [\App\Http\Controllers\MetaWhatsAppController::class, 'deleteTemplate']);
                Route::get('conversation-window/{phone}', [\App\Http\Controllers\MetaWhatsAppController::class, 'checkConversationWindow']);
                Route::post('send-test',            [\App\Http\Controllers\MetaWhatsAppController::class, 'sendTest']);
                Route::get('logs',                  [\App\Http\Controllers\MetaWhatsAppController::class, 'getLogs']);
                Route::post('validate-phone',       [\App\Http\Controllers\MetaWhatsAppController::class, 'validatePhone']);

                // Automatizaciones: qué plantilla sale ante cada hecho del negocio
                Route::get('template-bindings',           [\App\Http\Controllers\WaTemplateBindingController::class, 'index']);
                Route::post('template-bindings',          [\App\Http\Controllers\WaTemplateBindingController::class, 'save']);
                Route::get('template-bindings/{event}/preview', [\App\Http\Controllers\WaTemplateBindingController::class, 'preview']);
                Route::post('template-bindings/test',     [\App\Http\Controllers\WaTemplateBindingController::class, 'test']);

                // Envíos masivos de una plantilla a los clientes
                Route::get('campaigns/options',      [\App\Http\Controllers\WaCampaignController::class, 'options']);
                Route::get('campaigns/audience',     [\App\Http\Controllers\WaCampaignController::class, 'audience']);
                Route::get('campaigns/clients',      [\App\Http\Controllers\WaCampaignController::class, 'clients']);
                Route::get('campaigns',              [\App\Http\Controllers\WaCampaignController::class, 'index']);
                Route::post('campaigns',             [\App\Http\Controllers\WaCampaignController::class, 'save']);
                Route::get('campaigns/{id}',         [\App\Http\Controllers\WaCampaignController::class, 'show'])->where('id', '[0-9]+');
                Route::post('campaigns/{id}/test',   [\App\Http\Controllers\WaCampaignController::class, 'test'])->where('id', '[0-9]+');
                Route::post('campaigns/{id}/send',   [\App\Http\Controllers\WaCampaignController::class, 'send'])->where('id', '[0-9]+');
                Route::post('campaigns/{id}/cancel', [\App\Http\Controllers\WaCampaignController::class, 'cancel'])->where('id', '[0-9]+');
            });

            // ── Bot WhatsApp ───────────────────────────────────────────────────
            Route::get('bot-config',  [\App\Http\Controllers\WaBotController::class, 'getConfig']);
            Route::put('bot-config',  [\App\Http\Controllers\WaBotController::class, 'updateConfig']);
            Route::delete('bot-config', [\App\Http\Controllers\WaBotController::class, 'deleteConfig']);
        });
    });
});

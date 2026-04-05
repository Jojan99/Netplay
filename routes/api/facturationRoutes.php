<?php

use App\Http\Controllers\FacturationController;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

Route::prefix('facturation')->group(function () {
    // Rutas públicas (sin JWT) usadas por portal de clientes
    Route::post('getDateFacturePending', [FacturationController::class, 'getDateFacturePending'])->withoutMiddleware('jwt.verify');
    Route::post('getDatePayFacture', [FacturationController::class, 'getDatePayFacture'])->withoutMiddleware('jwt.verify');

    // Ejecutar proceso de facturación manualmente — solo admin
    // Uso: GET /api/facturation/ejecutar-comando?periodo=1
    Route::get('ejecutar-comando', function (\Illuminate\Http\Request $request) {
        $periodo   = (int) $request->query('periodo', 1);
        $companyId = getSessionCompanyId();

        if ($periodo < 1 || $periodo > 4) {
            return response()->json(['message' => 'Periodo inválido. Debe ser entre 1 y 4'], 422);
        }

        // Obtener el día de factura configurado para este grupo
        $schedule = \App\Models\CompanyBillingSchedule::where('company_id', $companyId)
            ->where('grupo', $periodo)
            ->where('active', true)
            ->first();

        if (!$schedule) {
            return response()->json(['message' => "No existe configuración activa para el grupo {$periodo}"], 422);
        }

        Artisan::call('post:create', [
            'company_id'  => $companyId,
            'periodo'     => $periodo,
            'billing_day' => $schedule->billing_day,
        ]);

        return response()->json([
            'message'     => 'Proceso ejecutado correctamente',
            'company_id'  => $companyId,
            'periodo'     => $periodo,
            'billing_day' => $schedule->billing_day,
        ]);
    })->middleware('role:admin');

    // Operaciones de facturación: admin y contador
    Route::middleware('role:admin,contador')->group(function () {
        Route::post('createDetFacturation', [FacturationController::class, 'createDetFacturation']);
        Route::post('updateDetFacturation', [FacturationController::class, 'updateDetFacturation']);
        Route::post('createpaidFacturation', [FacturationController::class, 'createpaidFacturation']);
        Route::post('createDiscountFacturation', [FacturationController::class, 'createDiscountFacturation']);
        Route::post('createAboneFacturation', [FacturationController::class, 'createAboneFacturation']);
        Route::get('getDataInfoPenddingFacture', [FacturationController::class, 'getDataInfoPenddingFacture']);

        // Finance v2
        Route::get('clients',                   [FacturationController::class, 'clientsPaginated']);
        Route::get('clients/{cabId}/invoices',   [FacturationController::class, 'clientInvoices']);
        Route::post('invoices/{detId}/pay',      [FacturationController::class, 'payInvoice']);
        Route::post('invoices/{detId}/abonar',   [FacturationController::class, 'abonarInvoice']);
        Route::put('invoices/{detId}',           [FacturationController::class, 'updateInvoiceNew']);
        Route::get('export',                     [FacturationController::class, 'exportPaymentsCSV']);
        Route::get('logs',                       [FacturationController::class, 'paymentLogs']);

        // Compromisos de pago
        Route::get('commitments',                [FacturationController::class, 'listCommitments']);
        Route::post('commitments',               [FacturationController::class, 'createCommitment']);
        Route::put('commitments/{id}/cancel',    [FacturationController::class, 'cancelCommitment']);
        Route::put('commitments/{id}/fulfill',   [FacturationController::class, 'fulfillCommitment']);
    });
});

<?php

use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;


Route::prefix('user')->group(function () {
    // Rutas públicas (sin JWT) usadas por portal de clientes
    Route::get('getUserById/{id}', [UserController::class, 'getUserById']);
    Route::get('getUserByIdBost/{id}', [UserController::class, 'getUserByIdBost']);

    // Solo admin: crear, modificar y eliminar clientes
    Route::middleware('role:admin')->group(function () {
        Route::post('createUserData', [UserController::class, 'createUserData']);
        Route::put('updateUserData', [UserController::class, 'updateUserData']);
        Route::delete('deleteUserDataById/{id}', [UserController::class, 'DeleteUserData']);
        // Clientes eliminados: listarlos y volver a darlos de alta. Va antes de
        // '{id}/en-router' para que 'eliminados' no se lea como un id.
        // Cuántos clientes incluye el plan de la empresa y cuántos lleva.
        Route::get('cupo',             [UserController::class, 'cupo']);
        Route::get('eliminados',       [UserController::class, 'eliminados']);
        Route::post('{id}/reinstalar', [UserController::class, 'reinstalar'])->whereNumber('id');
        Route::get('{id}/reinstalar/ip', [UserController::class, 'reinstalarRevisarIp'])->whereNumber('id');
        // Qué tiene el cliente en el MikroTik, antes de eliminarlo.
        Route::get('{id}/en-router', [UserController::class, 'enRouter'])->whereNumber('id');
        // Facturación electrónica del cliente (cobra por la pasarela).
        Route::post('{id}/facturacion-electronica', [UserController::class, 'facturacionElectronica'])->whereNumber('id');
        // Corregir la cédula: se cambia también en el router, el CRM, el bot y los tickets.
        Route::post('{id}/documento', [UserController::class, 'cambiarDocumento'])->whereNumber('id');
        // Plataforma contra MikroTik: descuadres (solo lee) y su corrección, cliente por cliente.
        Route::get('router/descuadres', [UserController::class, 'descuadresConElRouter']);
        // Suspensión temporal que pide el cliente (viaje, temporada): cobra los días usados,
        // no factura mientras dura y al volver solo reactiva si está al día.
        Route::get('{id}/suspension-temporal', [UserController::class, 'suspensionTemporal'])->whereNumber('id');
        Route::get('{id}/suspension-temporal/simular', [UserController::class, 'simularSuspensionTemporal'])->whereNumber('id');
        Route::post('{id}/suspension-temporal', [UserController::class, 'programarSuspensionTemporal'])->whereNumber('id');
        Route::post('{id}/suspension-temporal/cancelar', [UserController::class, 'cancelarSuspensionTemporal'])->whereNumber('id');
        Route::post('{id}/router/aplicar-estado', [UserController::class, 'aplicarEstadoEnRouter'])->whereNumber('id');
        // Abrir la página web de la ONT del cliente por el puente de equipos.netvula.com.
        Route::post('{id}/equipo/pagina', [\App\Http\Controllers\PaginaDelEquipoController::class, 'abrir'])->whereNumber('id');
        // Usuario y clave de administración del equipo, para no adivinarlos (queda en el historial).
        Route::get('{id}/equipo/acceso', [\App\Http\Controllers\PaginaDelEquipoController::class, 'credenciales'])->whereNumber('id');
        // Trato especial: el descuento que se le aplica todos los meses.
        Route::post('{id}/descuento', [UserController::class, 'guardarDescuento'])->whereNumber('id');
        // Contactos adicionales del cliente (quién paga, respaldo, autorizado).
        Route::post('{id}/contactos', [\App\Http\Controllers\FichaContratoController::class, 'guardarContacto'])->whereNumber('id');
        Route::delete('{id}/contactos/{contacto}', [\App\Http\Controllers\FichaContratoController::class, 'borrarContacto'])->whereNumber('id')->whereNumber('contacto');
    });

    // Admin y contador: consultar clientes y generar PDF
    Route::middleware('role:admin,contador')->group(function () {
        Route::get('getUserAll', [UserController::class, 'getUserAll']);
        Route::get('lista', [UserController::class, 'lista']);
        Route::get('search', [UserController::class, 'search']);
        Route::get('getCountUser', [UserController::class, 'getCountUser']);
        Route::get('generatePdf', [UserController::class, 'generatePdf']);
        Route::get('getTotalPriceMonth', [UserController::class, 'getTotalPriceMonth']);
        Route::get('getTotalClientRegisterMonth', [UserController::class, 'getTotalClientRegisterMonth']);
        Route::get('getTrazaFacture', [UserController::class, 'getTrazaFacture']);
        Route::get('auditLog/{user_id}', [UserController::class, 'getAuditLog']);
        // Pestaña «Contrato» de la ficha: contratos, documentos, comodato y contactos.
        Route::get('{id}/contrato', [\App\Http\Controllers\FichaContratoController::class, 'show'])->whereNumber('id');
        Route::get('exportUsers', [UserController::class, 'exportUsers']);
    });
});

Route::get('clients/{userId}/statement', [\App\Http\Controllers\ClientStatementController::class, 'show'])->middleware('module:usuario,finanzas');

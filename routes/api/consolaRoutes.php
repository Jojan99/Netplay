<?php

use App\Http\Controllers\Consola\ConsolaAccesoController;
use App\Http\Controllers\Consola\ConsolaController;
use App\Http\Controllers\Consola\ConsolaCuponesController;
use App\Http\Controllers\Consola\ConsolaPlanesController;
use App\Http\Controllers\Consola\ConsolaSuscripcionesController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Consola de Netvula — la plataforma vista por su dueño
|--------------------------------------------------------------------------
|
| Vive en su propia dirección (config plataforma.consola_host, por defecto
| admin.netvula.com) y con sus propios usuarios: `plataforma_usuarios`, que no
| tienen empresa ni perfil y no salen de la tabla `users` del panel.
|
| Fuera de ese host estas rutas NO EXISTEN: con Route::domain() ni siquiera
| coinciden para otro Host, así que desde netplay.netvula.com o desde la raíz
| responden 404. Y en ese host no se sirve el panel de empresas ni el portal
| de clientes: de eso se encarga SoloFueraDeLaConsolaMiddleware sobre los
| demás grupos de rutas.
|
| El token de la consola es opaco y vive en `plataforma_sesiones`: un JWT del
| panel no vale acá, y uno de la consola no es un JWT válido allá.
*/

$host = strtolower(trim((string) config('plataforma.consola_host', '')));

// Sin host configurado la consola queda apagada: no se registra ninguna ruta.
if ($host === '') {
    return;
}

Route::domain($host)->prefix('consola')->group(function () {

    // ── Ingreso (sin sesión) ────────────────────────────────────────────
    Route::post('login', [ConsolaAccesoController::class, 'login'])->middleware('throttle:login');
    // El segundo paso: el código del authenticator (o uno de recuperación).
    Route::post('login/codigo', [ConsolaAccesoController::class, 'loginConCodigo'])->middleware('throttle:login');

    // ── Todo lo demás, con sesión de consola ────────────────────────────
    Route::middleware('consola')->group(function () {

        Route::get('yo',      [ConsolaAccesoController::class, 'yo']);
        Route::post('logout', [ConsolaAccesoController::class, 'logout']);
        Route::put('clave',   [ConsolaAccesoController::class, 'cambiarClave']);

        // ── Authenticator ───────────────────────────────────────────────
        Route::get('2fa',            [ConsolaAccesoController::class, 'verSegundoFactor']);
        Route::post('2fa/preparar',  [ConsolaAccesoController::class, 'prepararSegundoFactor']);
        Route::post('2fa/confirmar', [ConsolaAccesoController::class, 'confirmarSegundoFactor']);
        Route::post('2fa/quitar',    [ConsolaAccesoController::class, 'quitarSegundoFactor']);

        // ── Tablero y empresas ──────────────────────────────────────────
        Route::get('tablero',  [ConsolaController::class, 'tablero']);
        Route::get('empresas', [ConsolaController::class, 'empresas']);
        Route::get('empresas/{id}', [ConsolaController::class, 'empresa'])->whereNumber('id');
        Route::get('bitacora', [ConsolaController::class, 'bitacora']);

        // ── Operación sobre una empresa ─────────────────────────────────
        Route::post('empresas/{id}/suspender', [ConsolaController::class, 'suspender'])->whereNumber('id');
        Route::post('empresas/{id}/reactivar', [ConsolaController::class, 'reactivar'])->whereNumber('id');

        // ── Suscripción y facturación ───────────────────────────────────
        Route::put('empresas/{id}/suscripcion',    [ConsolaSuscripcionesController::class, 'guardar'])->whereNumber('id');
        Route::post('empresas/{id}/cupon',         [ConsolaSuscripcionesController::class, 'aplicarCupon'])->whereNumber('id');
        Route::delete('empresas/{id}/cupon',       [ConsolaSuscripcionesController::class, 'quitarCupon'])->whereNumber('id');
        Route::post('empresas/{id}/credito',       [ConsolaSuscripcionesController::class, 'credito'])->whereNumber('id');
        Route::get('empresas/{id}/cobros/simular', [ConsolaSuscripcionesController::class, 'simular'])->whereNumber('id');
        Route::post('empresas/{id}/cobros',        [ConsolaSuscripcionesController::class, 'generar'])->whereNumber('id');

        Route::get('cobros',               [ConsolaSuscripcionesController::class, 'cobros']);
        Route::post('cobros/marcar-moras', [ConsolaSuscripcionesController::class, 'marcarMoras']);
        Route::post('cobros/{id}/pagos',   [ConsolaSuscripcionesController::class, 'registrarPago'])->whereNumber('id');
        Route::post('cobros/{id}/anular',  [ConsolaSuscripcionesController::class, 'anular'])->whereNumber('id');

        // ── Planes de la plataforma ─────────────────────────────────────
        Route::get('planes',                      [ConsolaPlanesController::class, 'index']);
        Route::post('planes',                     [ConsolaPlanesController::class, 'store']);
        Route::put('planes/{id}',                 [ConsolaPlanesController::class, 'update'])->whereNumber('id');
        Route::delete('planes/{id}',              [ConsolaPlanesController::class, 'destroy'])->whereNumber('id');
        Route::get('planes/{id}/precios',         [ConsolaPlanesController::class, 'precios'])->whereNumber('id');
        Route::post('planes/{id}/aplicar-precio', [ConsolaPlanesController::class, 'aplicarPrecio'])->whereNumber('id');

        // ── Cupones ─────────────────────────────────────────────────────
        Route::get('cupones',           [ConsolaCuponesController::class, 'index']);
        Route::post('cupones',          [ConsolaCuponesController::class, 'store']);
        Route::put('cupones/{id}',      [ConsolaCuponesController::class, 'update'])->whereNumber('id');
        Route::delete('cupones/{id}',   [ConsolaCuponesController::class, 'destroy'])->whereNumber('id');
        Route::get('cupones/{id}/usos', [ConsolaCuponesController::class, 'usos'])->whereNumber('id');

        // ── Novedades para las empresas ─────────────────────────────────
        Route::get('novedades',              [\App\Http\Controllers\Consola\ConsolaNovedadesController::class, 'index']);
        Route::post('novedades',             [\App\Http\Controllers\Consola\ConsolaNovedadesController::class, 'store']);
        Route::put('novedades/{id}',         [\App\Http\Controllers\Consola\ConsolaNovedadesController::class, 'update'])->whereNumber('id');
        Route::post('novedades/{id}/publicar', [\App\Http\Controllers\Consola\ConsolaNovedadesController::class, 'publicar'])->whereNumber('id');
        Route::delete('novedades/{id}',      [\App\Http\Controllers\Consola\ConsolaNovedadesController::class, 'destroy'])->whereNumber('id');

        // ── Referidos ───────────────────────────────────────────────────
        Route::get('referidos',         [ConsolaSuscripcionesController::class, 'referidos']);
        Route::put('referidos/ajustes', [ConsolaSuscripcionesController::class, 'guardarAjustesReferidos']);
    });
});

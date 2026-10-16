<?php

use App\Http\Controllers\PaginaDelEquipoController;
use Illuminate\Support\Facades\Route;

// Las llama nginx desde equipos.netvula.com (ver PaginaDelEquipo). Sin sesión ni
// cifrado de cookies: el controlador sólo responde si nginx marcó la petición.
Route::get('pagina-equipo/entrar', [PaginaDelEquipoController::class, 'entrar']);
Route::get('pagina-equipo/validar', [PaginaDelEquipoController::class, 'validar']);
Route::get('pagina-equipo/sin-sesion', [PaginaDelEquipoController::class, 'sinSesion']);

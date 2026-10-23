<?php

namespace App\Http\Controllers;

use App\Models\TvPanel;
use App\Services\Inventario\AnalisisDeInventario;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Las cifras sueltas del Inicio que no tenían una pantalla que las diera de una vez:
 * altas y suspendidos, compromisos, comprobantes por revisar, bodega, instalaciones y TV.
 *
 * Cada bloque sale sólo si el perfil tiene el módulo; lo que no le toca llega en null y
 * el tablero no lo muestra. Así un técnico no ve la plata ni un contador la red.
 */
class InicioController extends Controller
{
    /** GET api/company/inicio */
    public function resumen(): JsonResponse
    {
        $user = JWTAuth::user();

        if (!$user) {
            return standardApiReponse('Sin sesión', null, 1, JsonResponse::HTTP_UNAUTHORIZED);
        }

        $empresa = (int) ($user->company_id ?? 0);
        $puede = $this->modulos($user);
        $mes = now()->startOfMonth();

        $datos = [
            'clientes' => null, 'instalaciones' => null, 'compromisos' => null,
            'comprobantes' => null, 'bodega' => null, 'tv' => null,
        ];

        if ($puede('usuario')) {
            $datos['clientes'] = [
                'altas_mes'   => DB::table('user_data')->where('company_id', $empresa)->where('created_at', '>=', $mes)->count(),
                'suspendidos' => DB::table('user_data')->where('company_id', $empresa)->where('active', 1)->where('status_internet_id', 2)->count(),
            ];
        }

        if ($puede('installations')) {
            $datos['instalaciones'] = [
                'hoy'        => DB::table('installation_orders')->where('company_id', $empresa)->whereDate('scheduled_date', today())->count(),
                'pendientes' => DB::table('installation_orders')->where('company_id', $empresa)->whereIn('status', ['pending', 'confirmed', 'in_progress'])->count(),
            ];
        }

        if ($puede('finanzas')) {
            $c = DB::table('payment_commitments')->where('company_id', $empresa)
                ->selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status');
            $datos['compromisos'] = [
                'vigentes'    => (int) ($c['pending'] ?? 0) + (int) ($c['active'] ?? 0),
                'cumplidos'   => (int) ($c['fulfilled'] ?? 0),
                'incumplidos' => (int) ($c['broken'] ?? 0),
            ];
            $datos['comprobantes'] = DB::table('payment_proofs')->where('company_id', $empresa)->where('status', 'pending')->count();
        }

        if ($puede('inventory')) {
            $items = (new AnalisisDeInventario($empresa))->items();
            $datos['bodega'] = [
                'agotados' => count(array_filter($items, fn ($i) => $i['estado'] === 'agotado')),
                'bajos'    => count(array_filter($items, fn ($i) => in_array($i['estado'], ['bajo', 'por_agotarse'], true))),
            ];
        }

        // Sin panel de TV conectado no hay nada que contar, ni siquiera un cero.
        if ($puede('television') && TvPanel::activa($empresa) && Schema::hasTable('tv_canales')) {
            $datos['tv'] = [
                'al_aire' => DB::table('tv_canales')->where('company_id', $empresa)->where('estado', 'al_aire')->count(),
                'caidos'  => DB::table('tv_canales')->where('company_id', $empresa)->where('vigilar', 1)->where('estado', '<>', 'al_aire')->count(),
                'lineas'  => DB::table('tv_lineas')->where('company_id', $empresa)->where('estado', 'activa')->count(),
            ];
        }

        return standardApiReponse('OK', $datos, 0, JsonResponse::HTTP_OK);
    }

    /** Igual que ModuleMiddleware: el ADMIN ve todo, el resto lo que su perfil tenga activo. */
    private function modulos($user): \Closure
    {
        $perfil = DB::table('profiles')->where('id', $user->profile_id)->value('name');

        if (strtoupper((string) $perfil) === 'ADMIN') {
            return fn (string $m) => true;
        }

        $activos = DB::table('profile_modules')->where('profile_id', $user->profile_id)->where('active', true)->pluck('module')->all();

        return fn (string $m) => in_array($m, $activos, true);
    }
}

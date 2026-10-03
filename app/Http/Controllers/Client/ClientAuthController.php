<?php

namespace App\Http\Controllers\Client;

use App\Constants\ApiResponseConstants;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Autenticación del portal de cliente.
 * Solo permite login a usuarios con profile_id = 1 (CLIENT/USER).
 * Devuelve el token JWT + datos del cliente (user_data).
 */
class ClientAuthController extends Controller
{
    /**
     * POST /api/client/login
     */
    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'username' => 'required|string',
            'password' => 'required|string',
        ], [
            'username.required' => 'El usuario es requerido',
            'password.required' => 'La contraseña es requerida',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Datos incompletos',
                'data'    => $validator->errors(),
                'status'  => ApiResponseConstants::ERROR,
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        // "Mantener la sesión abierta": 30 días en vez del día normal.
        $minutos = $request->boolean('recordar') ? 60 * 24 * 30 : (int) config('jwt.ttl');
        JWTAuth::factory()->setTTL($minutos);

        $dominio         = app(\App\Services\Plataforma\EmpresaDelDominio::class);
        $empresaDelSitio = $dominio->empresa($request);

        if ($dominio->subdominioPedido($request) !== null && !$empresaDelSitio) {
            return response()->json([
                'message' => 'Esta dirección no corresponde a ninguna empresa registrada.',
                'data'    => null,
                'status'  => ApiResponseConstants::ERROR,
            ], JsonResponse::HTTP_OK);
        }

        if (!$empresaDelSitio && $request->filled('empresa')) {
            $empresaDelSitio = \App\Models\Company::where('subdomain', strtolower((string) $request->input('empresa')))->first();
        }

        // El documento se repite entre empresas: el mismo cliente puede estar
        // en dos ISP. En el subdominio sólo cuentan los de esa empresa.
        $coinciden = \App\Models\User::where('username', $request->username)
            ->where('active', 1)
            ->when($empresaDelSitio, fn ($q) => $q->where('company_id', $empresaDelSitio->id))
            ->get()
            ->filter(fn ($u) => \Illuminate\Support\Facades\Hash::check((string) $request->password, (string) $u->password));

        // Validar por nombre del perfil (cada empresa tiene su propio profile_id para 'USER')
        $perfiles = DB::table('profiles')->whereIn('id', $coinciden->pluck('profile_id')->filter())->pluck('name', 'id');
        $clientes = $coinciden->filter(fn ($u) => strtoupper((string) ($perfiles[$u->profile_id] ?? '')) === 'USER')->values();

        if ($clientes->isEmpty()) {
            if ($coinciden->isNotEmpty()) {
                return response()->json([
                    'message' => 'Este portal es exclusivo para clientes',
                    'data'    => null,
                    'status'  => ApiResponseConstants::ERROR,
                ], JsonResponse::HTTP_FORBIDDEN);
            }

            return response()->json([
                'message' => 'Credenciales incorrectas',
                'data'    => null,
                'status'  => ApiResponseConstants::ERROR,
            ], JsonResponse::HTTP_OK);
        }

        if ($clientes->count() > 1) {
            $empresas = \App\Models\Company::whereIn('id', $clientes->pluck('company_id'))->orderBy('name')->get(['name', 'subdomain']);

            return response()->json([
                'message' => 'Tiene servicio con más de una empresa. Seleccione cuál quiere consultar.',
                'data'    => ['elegir_empresa' => $empresas->map(fn ($e) => ['nombre' => $e->name, 'subdominio' => $e->subdomain])->values()],
                'status'  => ApiResponseConstants::ERROR,
            ], JsonResponse::HTTP_OK);
        }

        return $this->abrirSesion($clientes->first(), $minutos, $dominio);
    }

    /**
     * POST /api/client/entrar-con-enlace
     *
     * Entrar al portal desde el enlace de pago que le llega por WhatsApp, sin usuario ni
     * contraseña (casi ningún cliente los recuerda). El enlace no vence y se puede reenviar,
     * así que además se piden los últimos cuatro dígitos de la cédula del titular, y la
     * sesión dura una hora: alcanza para cambiar la clave del WiFi o reiniciar el equipo.
     */
    public function entrarConEnlace(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'token'     => 'required|string|max:80',
            'documento' => 'required|string|max:20',
        ]);

        $error = fn (string $m) => response()->json(['message' => $m, 'data' => null, 'status' => ApiResponseConstants::ERROR], JsonResponse::HTTP_OK);

        $userId = \App\Services\ClientStatementService::userFromToken($datos['token']);
        if (!$userId) {
            return $error('El enlace no es válido. Pida uno nuevo por WhatsApp.');
        }

        // Cinco intentos por enlace cada quince minutos: cuatro dígitos se adivinan probando.
        $llave = 'portal-enlace:' . $userId;
        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($llave, 5)) {
            return $error('Demasiados intentos. Espere unos minutos y vuelva a probar.');
        }

        $user = \App\Models\User::where('id', $userId)->where('active', 1)->first();
        $ficha = DB::table('user_data')->where('user_id', $userId)->where('active', 1)->first(['dni']);
        $perfil = $user ? strtoupper((string) DB::table('profiles')->where('id', $user->profile_id)->value('name')) : '';

        $dni = preg_replace('/\D/', '', (string) ($ficha->dni ?? ''));
        $dado = preg_replace('/\D/', '', $datos['documento']);

        if (!$user || $perfil !== 'USER' || strlen($dni) < 4 || strlen($dado) < 4 || !hash_equals(substr($dni, -4), substr($dado, -4))) {
            \Illuminate\Support\Facades\RateLimiter::hit($llave, 15 * 60);

            return $error('Los dígitos no coinciden con la cédula del titular del servicio.');
        }

        \Illuminate\Support\Facades\RateLimiter::clear($llave);
        JWTAuth::factory()->setTTL(60);

        return $this->abrirSesion($user, 60, app(\App\Services\Plataforma\EmpresaDelDominio::class));
    }

    /** El token y los datos con que el portal arranca, igual para cualquier forma de entrar. */
    private function abrirSesion(\App\Models\User $user, int $minutos, \App\Services\Plataforma\EmpresaDelDominio $dominio): JsonResponse
    {
        try {
            $token = JWTAuth::fromUser($user);
        } catch (JWTException $e) {
            return response()->json([
                'message' => 'Error al crear la sesión: ' . $e->getMessage(),
                'data'    => null,
                'status'  => ApiResponseConstants::ERROR,
            ], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }

        // Cargar datos del cliente y empresa
        $userData = DB::table('user_data')
            ->where('user_id', $user->id)
            ->first();

        $company = DB::table('companies')
            ->where('id', $user->company_id)
            ->select('id', 'name', 'slug', 'logo', 'phone', 'email', 'address')
            ->first();

        // El portal muestra el logo de la empresa: el de la factura si no hay otro.
        if ($company) {
            $modelo = \App\Models\Company::find($company->id);
            $company->logo = $modelo ? $dominio->logoDe($modelo) : $company->logo;
        }

        return response()->json([
            'message' => 'Sesión iniciada correctamente',
            'data'    => [
                'access_token' => $token,
                'expires_in'   => $minutos * 60,
                'user_id'      => $user->id,
                'company_id'   => $user->company_id,
                'profile_id'   => $user->profile_id,
                'client'       => $userData,
                'company'      => $company,
            ],
            'status'  => ApiResponseConstants::SUCCESS,
        ], JsonResponse::HTTP_OK);
    }

    /**
     * GET /api/client/me
     * Retorna los datos del cliente autenticado.
     */
    public function me(Request $request): JsonResponse
    {
        $user = JWTAuth::user();

        $userData = DB::table('user_data')
            ->where('user_id', $user->id)
            ->first();

        $company = DB::table('companies')
            ->where('id', $user->company_id)
            ->select('id', 'name', 'slug', 'logo', 'phone', 'email', 'address')
            ->first();

        return response()->json([
            'message' => 'OK',
            'data'    => [
                'user_id'    => $user->id,
                'company_id' => $user->company_id,
                'client'     => $userData,
                'company'    => $company,
            ],
            'status' => ApiResponseConstants::SUCCESS,
        ], JsonResponse::HTTP_OK);
    }

    /**
     * POST /api/client/logout
     */
    public function logout(): JsonResponse
    {
        try {
            JWTAuth::invalidate(JWTAuth::getToken());
        } catch (JWTException $e) {
            // Token ya inválido — no es error crítico
        }

        // Además del token hay que soltar la sesión de servidor: si queda viva,
        // el usuario sigue autorizado por cookie aunque el token ya no valga.
        session()->flush();

        return response()->json([
            'message' => 'Sesión cerrada correctamente',
            'data'    => null,
            'status'  => ApiResponseConstants::SUCCESS,
        ], JsonResponse::HTTP_OK);
    }
}

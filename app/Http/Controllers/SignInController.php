<?php

namespace App\Http\Controllers;

use App\Constants\ApiResponseConstants;
use App\Constants\StatusConstants;
use App\Constants\TimeConstants;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Session\AuthenticateUserActiveRequest;
use App\UseCases\Oauth\Interfaces\ChangePasswordUseCaseInterface;
use App\UseCases\Oauth\Interfaces\SignInUseCaseInterface;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
/**
 * Clase controlador para las sesiones
 *
 * @package App\Http\Controllers
 * @author Network Golden <sa.networkgolden@gmail.com>
 * @copyright 2022/06/9
 */
class SignInController extends Controller
{
    /** Lo que dura la sesión cuando se marca "Mantener la sesión abierta": 30 días. */
    private const MINUTOS_RECORDAR = 60 * 24 * 30;

    /**
     * Método encargado de validar las credenciales de un usuario activo
     * y de retornar un token para la sesión de este
     *
     * @param AuthenticateUserActiveRequest $request
     * @param SignInUseCaseInterface        $SignInUseCaseInterface
     * @return object
     */
    public function signin(
        AuthenticateUserActiveRequest $request,
        // SignInUseCaseInterface       $SignInUseCaseInterface
    ): object {
        // "Mantener la sesión abierta": el token dura 30 días en vez del día
        // normal. Sin eso, al volver al panel al otro día pedía login otra vez.
        $minutos = $request->boolean('recordar') ? self::MINUTOS_RECORDAR : (int) config('jwt.ttl');
        JWTAuth::factory()->setTTL($minutos);

        $dominio         = app(\App\Services\Plataforma\EmpresaDelDominio::class);
        $enSubdominio    = $dominio->subdominioPedido($request) !== null;
        $empresaDelSitio = $dominio->empresa($request);

        if ($enSubdominio && !$empresaDelSitio) {
            return standardApiReponse(
                'Esta dirección no corresponde a ninguna empresa registrada. Revisá el enlace.',
                ApiResponseConstants::DATA_NULL,
                ApiResponseConstants::ERROR,
                JsonResponse::HTTP_OK
            );
        }

        // Fuera del subdominio se puede indicar la empresa: es la elección que
        // se ofrece cuando el mismo usuario está en varias.
        if (!$empresaDelSitio && $request->filled('empresa')) {
            $empresaDelSitio = \App\Models\Company::where('subdomain', strtolower((string) $request->input('empresa')))->first();
        }

        /*
         * El usuario (la cédula) no es único: la misma persona puede estar en
         * dos empresas. JWTAuth::attempt tomaba el primero que encontraba y, si
         * la clave era la de la otra empresa, respondía "contraseña incorrecta".
         * Se revisan todos los que coinciden; en un subdominio, sólo los de esa
         * empresa.
         */
        $coinciden = \App\Models\User::where('username', $request->user)
            ->when($empresaDelSitio, fn ($q) => $q->where('company_id', $empresaDelSitio->id))
            ->get()
            ->filter(fn ($u) => \Illuminate\Support\Facades\Hash::check((string) $request->password, (string) $u->password));

        $perfiles = DB::table('profiles')->whereIn('id', $coinciden->pluck('profile_id')->filter())->pluck('name', 'id');

        // El panel es para operadores. Un cliente (perfil USER) entraba igual
        // con su documento y contraseña; su lugar es el portal de clientes.
        $equipo  = $coinciden->filter(fn ($u) => strtoupper((string) ($perfiles[$u->profile_id] ?? '')) !== 'USER');
        $activos = $equipo->where('active', 1)->values();

        if ($activos->isEmpty()) {
            // Distinguir "cuenta sin confirmar" de "clave incorrecta": antes las dos
            // daban el mismo mensaje y parecía que la contraseña estaba mal.
            $pendiente = $equipo->firstWhere('active', 0);

            // La cuenta dada de baja también queda en active = 0, pero decirle
            // "confirmá tu correo" la manda a buscar un correo que no existe.
            if ($pendiente && (int) $pendiente->status === 1) {
                return standardApiReponse(
                    'Esta cuenta fue dada de baja por un administrador de la empresa. Si es un error, pedile que la reactive desde Equipo de trabajo.',
                    ApiResponseConstants::DATA_NULL,
                    ApiResponseConstants::ERROR,
                    JsonResponse::HTTP_OK
                );
            }

            if ($pendiente) {
                $empresa = DB::table('companies')->where('id', $pendiente->company_id)->first(['name', 'active', 'email']);
                return standardApiReponse(
                    'Tu cuenta todavía no está confirmada. Te enviamos un correo a ' . ($empresa->email ?? $pendiente->email) . ' para activarla; revisá también la carpeta de spam.',
                    ['needs_confirmation' => true, 'email' => $empresa->email ?? $pendiente->email, 'username' => $request->user],
                    ApiResponseConstants::ERROR,
                    JsonResponse::HTTP_OK
                );
            }

            if ($coinciden->isNotEmpty()) {
                return standardApiReponse(
                    'Este acceso es para el equipo de la empresa. Si sos cliente, ingresá por el portal de clientes.',
                    ['portal' => true],
                    ApiResponseConstants::ERROR,
                    JsonResponse::HTTP_OK
                );
            }

            return standardApiReponse(
                'Usuario o contraseña incorrectos.',
                ApiResponseConstants::DATA_NULL,
                ApiResponseConstants::ERROR,
                JsonResponse::HTTP_OK
            );
        }

        if ($activos->count() > 1) {
            $empresas = \App\Models\Company::whereIn('id', $activos->pluck('company_id'))->orderBy('name')->get(['name', 'subdomain']);

            return standardApiReponse(
                'Tu usuario está en más de una empresa. Elegí a cuál querés entrar.',
                ['elegir_empresa' => $empresas->map(fn ($e) => ['nombre' => $e->name, 'subdominio' => $e->subdomain])->values()],
                ApiResponseConstants::ERROR,
                JsonResponse::HTTP_OK
            );
        }

        $authenticatedUser = $activos->first();

        // Empresa suspendida por Netvula: su equipo no entra al panel.
        //
        // Es un corte comercial, no de servicio: los clientes de la empresa
        // siguen con internet, su portal sigue abierto y las tareas
        // automáticas (cortes por mora, facturación a sus clientes) siguen
        // corriendo. Sólo se cierra el panel del operador.
        if (self::empresaSuspendida((int) $authenticatedUser->company_id)) {
            return standardApiReponse(
                'El acceso de tu empresa a la plataforma está suspendido. Escribinos para reactivarlo.',
                ApiResponseConstants::DATA_NULL,
                ApiResponseConstants::ERROR,
                JsonResponse::HTTP_OK
            );
        }

        // Último ingreso: la consola necesita saber qué empresa dejó de usar
        // la plataforma. Si falta la columna todavía, no pasa nada.
        try {
            if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'ultimo_ingreso')) {
                DB::table('users')->where('id', $authenticatedUser->id)->update(['ultimo_ingreso' => now()]);
            }
        } catch (\Throwable $e) {
            \Log::warning('[Login] no se pudo anotar el último ingreso', ['user_id' => $authenticatedUser->id]);
        }

        // Entrando desde la raíz, la sesión se abre en el subdominio de la
        // empresa: el navegador guarda la sesión por dominio, así que se pasa
        // con un vale de un solo uso y no con el token en la URL.
        if ($dominio->activos() && !$enSubdominio && $authenticatedUser->company_id) {
            $destino = $dominio->urlDe((int) $authenticatedUser->company_id);

            if ($destino !== rtrim((string) config('app.url'), '/')) {
                $vale = app(\App\Services\AccesoDirectoService::class)->emitirParaUsuario($authenticatedUser, $minutos);

                return standardApiReponse(
                    'Entrando a tu empresa…',
                    ['ir_a' => $destino . '/entrar?vale=' . urlencode($vale)],
                    ApiResponseConstants::SUCCESS
                );
            }
        }

        try {
            $token = JWTAuth::fromUser($authenticatedUser);
        } catch (JWTException $e) {
            return standardApiReponse(
                'Failed to create token: '.$e->getMessage(),
                ApiResponseConstants::DATA_NULL,
                ApiResponseConstants::ERROR,
                JsonResponse::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        $profileName = (string) ($perfiles[$authenticatedUser->profile_id] ?? '');

        // Nombre y logo de la empresa para la barra del panel.
        $empresaDelUsuario = \App\Models\Company::find($authenticatedUser->company_id);

        $modules = \DB::table('profile_modules')
            ->where('profile_id', $authenticatedUser->profile_id)
            ->where('active', 1)
            ->pluck('module')
            ->toArray();

        $employee = \App\Models\Employee::where('user_id', $authenticatedUser->id)
            ->where('company_id', $authenticatedUser->company_id)
            ->first(['id', 'first_name', 'last_name']);
        $employeeId = $employee?->id;

        // El nombre para mostrar en el panel. El usuario es la cédula, y era lo
        // que aparecía en el saludo y en la barra superior.
        $datos  = DB::table('user_data')->where('user_id', $authenticatedUser->id)->first(['names', 'lastname']);
        $nombre = trim(($datos->names ?? '') . ' ' . ($datos->lastname ?? ''))
            ?: trim(($employee->first_name ?? '') . ' ' . ($employee->last_name ?? ''));

        return standardApiReponse(
            'The token has been created successfully',
            [
                'access_token' => $token,
                'userId'       => $authenticatedUser->id,
                'nombre'       => $nombre,
                'employee_id'  => $employeeId,
                'company_id'   => $authenticatedUser->company_id,
                'profile_id'   => $authenticatedUser->profile_id,
                'profile_name' => $profileName,
                'company_name' => $empresaDelUsuario->name ?? '',
                'company_logo' => $empresaDelUsuario ? ($dominio->logoDe($empresaDelUsuario) ?? '') : '',
                'expires_in'   => $minutos * 60,
                'modules'      => $modules,
                'user' => [
                    'user'  => $request->user,
                    'email' => $authenticatedUser->email,
                ]
            ],
            ApiResponseConstants::SUCCESS
        );
    }
    
    /** ¿Netvula le suspendió el acceso a esta empresa? */
    public static function empresaSuspendida(int $companyId): bool
    {
        static $hayColumna = null;
        $hayColumna ??= \Illuminate\Support\Facades\Schema::hasColumn('companies', 'plataforma_suspendida');

        if (!$hayColumna || !$companyId) {
            return false;
        }

        return (bool) DB::table('companies')->where('id', $companyId)->value('plataforma_suspendida');
    }

    /**
     * POST /api/oauth/changePassword
     * Cambia la contraseña del usuario autenticado.
     */
    public function changePassword(
        ChangePasswordRequest $request,
        ChangePasswordUseCaseInterface $changePasswordUseCase
    ): object {
        $result = $changePasswordUseCase->change($request);

        return standardApiReponse(
            $result['message'],
            $result['data'],
            $result['status'],
            JsonResponse::HTTP_OK
        );
    }

    /**
     * GET /api/oauth/me
     * Retorna información del perfil del usuario autenticado.
     */
    public function getMe(): JsonResponse
    {
        $user = JWTAuth::user();

        $userData = DB::table('user_data')
            ->where('user_id', $user->id)
            ->select('names', 'lastname', 'phone', 'address', 'dni')
            ->first();

        $profileName = DB::table('profiles')
            ->where('id', $user->profile_id)
            ->value('name');

        $companyName = DB::table('companies')
            ->where('id', $user->company_id)
            ->value('name');

        return standardApiReponse('OK', [
            'id'           => $user->id,
            'username'     => $user->username,
            'email'        => $user->email,
            'names'        => $userData->names    ?? '',
            'lastname'     => $userData->lastname ?? '',
            'phone'        => $userData->phone    ?? '',
            'address'      => $userData->address  ?? '',
            'dni'          => $userData->dni      ?? '',
            'profile_id'   => $user->profile_id,
            'profile_name' => $profileName        ?? '',
            'company_name' => $companyName        ?? '',
            'created_at'   => $user->created_at,
        ], 0, JsonResponse::HTTP_OK);
    }

    /**
     * PUT /api/oauth/profile
     * Actualiza datos personales del usuario autenticado.
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = JWTAuth::user();

        // Update email on users table if provided
        if ($request->filled('email')) {
            DB::table('users')->where('id', $user->id)->update(['email' => $request->input('email')]);
        }

        // Update user_data fields
        DB::table('user_data')->where('user_id', $user->id)->update(array_filter([
            'names'    => $request->input('names'),
            'lastname' => $request->input('lastname'),
            'phone'    => $request->input('phone'),
        ], fn($v) => $v !== null));

        return standardApiReponse('Perfil actualizado.', null, 0, JsonResponse::HTTP_OK);
    }

    /**
     * Método encargado de invalidar un token (logout)
     *
     * @return object
     */
    public function logout(): object
    {
        try {
            // Se obtiene y se desactiva el token
            JWTAuth::invalidate(JWTAuth::getToken());

            // Se borra la sesión del usuario
            session()->flush();

            // Respuesta en caso de éxito
            return standardApiReponse(
                'The token has been successfully inactivated',
                ApiResponseConstants::DATA_NULL,
                ApiResponseConstants::SUCCESS
            );
        } catch (JWTException $e) {
            // Respuesta en caso de excepción
            return standardApiReponse(
                'Failed to deactivate token: '.$e->getMessage(),
                ApiResponseConstants::DATA_NULL,
                ApiResponseConstants::ERROR,
                JsonResponse::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

}

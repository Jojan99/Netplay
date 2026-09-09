<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Acceso directo desde el enlace del correo de confirmación.
 *
 * Cuando alguien confirma su correo no tiene sentido mandarlo a escribir de
 * nuevo usuario y contraseña: ya demostró que controla la casilla. Pero el
 * enlace del correo no puede ser la sesión en sí, porque los correos se
 * reenvían, quedan en el historial y se registran en los servidores por los
 * que pasan.
 *
 * Por eso hay dos piezas separadas:
 *
 *  1. El enlace del correo lleva el token de verificación (un solo uso, se
 *     borra al confirmarse).
 *  2. Al confirmarse se emite un "vale" distinto, de un solo uso y que vive
 *     pocos minutos. El navegador lo canjea por un JWT con un POST, así el
 *     token de sesión nunca viaja en una URL ni queda en el historial.
 */
class AccesoDirectoService
{
    /** Un vale sirve una sola vez y por poco tiempo. */
    private const VIGENCIA_MINUTOS = 15;

    private const PREFIJO = 'acceso-directo:';

    /**
     * Emite un vale para el usuario administrador de una empresa.
     *
     * @return string|null null si la empresa no tiene un administrador activo.
     */
    public function emitirParaEmpresa(int $companyId): ?string
    {
        $usuario = $this->administradorDe($companyId);

        if (!$usuario) {
            return null;
        }

        $vale = Str::random(48);

        Cache::put(
            self::PREFIJO . hash('sha256', $vale),
            $usuario->id,
            now()->addMinutes(self::VIGENCIA_MINUTOS)
        );

        return $vale;
    }

    /**
     * Canjea el vale por una sesión. Devuelve null si no existe, ya se usó o
     * venció; el vale se consume siempre en el primer intento válido.
     *
     * @return array<string,mixed>|null Mismo formato que devuelve el login.
     */
    public function canjear(string $vale): ?array
    {
        $clave   = self::PREFIJO . hash('sha256', $vale);
        $usuarioId = Cache::pull($clave); // pull = leer y borrar: un solo uso

        if (!$usuarioId) {
            return null;
        }

        $usuario = User::find($usuarioId);

        if (!$usuario || !$usuario->active) {
            return null;
        }

        return $this->sesionPara($usuario);
    }

    /**
     * Arma la misma carga útil que devuelve el login, para que el frontend
     * pueda guardarla con el mismo AuthService sin ningún caso especial.
     *
     * @return array<string,mixed>
     */
    public function sesionPara(User $usuario): array
    {
        $token = JWTAuth::fromUser($usuario);

        $perfil = DB::table('profiles')->where('id', $usuario->profile_id)->value('name') ?? '';

        $modulos = DB::table('profile_modules')
            ->where('profile_id', $usuario->profile_id)
            ->where('active', 1)
            ->pluck('module')
            ->toArray();

        $empresa = DB::table('companies')->where('id', $usuario->company_id)
            ->first(['name', 'logo']);

        $empleadoId = Employee::where('user_id', $usuario->id)
            ->where('company_id', $usuario->company_id)
            ->value('id');

        return [
            'access_token' => $token,
            'userId'       => $usuario->id,
            'employee_id'  => $empleadoId,
            'company_id'   => $usuario->company_id,
            'profile_id'   => $usuario->profile_id,
            'profile_name' => $perfil,
            'company_name' => $empresa->name ?? '',
            'company_logo' => $empresa->logo ?? '',
            'expires_in'   => (int) config('jwt.ttl') * 60,
            'modules'      => $modulos,
            'user' => [
                'user'  => $usuario->username,
                'email' => $usuario->email,
            ],
        ];
    }

    /** El administrador de la empresa: el perfil ADMIN, y si no, el más viejo. */
    private function administradorDe(int $companyId): ?User
    {
        $perfilesAdmin = DB::table('profiles')
            ->where('company_id', $companyId)
            ->whereRaw('UPPER(name) = ?', ['ADMIN'])
            ->pluck('id');

        return User::where('company_id', $companyId)
            ->where('active', 1)
            ->when($perfilesAdmin->isNotEmpty(), fn ($q) => $q->whereIn('profile_id', $perfilesAdmin))
            ->orderBy('id')
            ->first();
    }
}

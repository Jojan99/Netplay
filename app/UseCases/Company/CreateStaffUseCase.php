<?php

namespace App\UseCases\Company;

use App\Constants\ApiResponseConstants;
use App\Http\Requests\Company\CreateStaffRequest;
use App\Repositories\Interfaces\UserRepositoryInterface;
use App\UseCases\Company\Interfaces\CreateStaffUseCaseInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CreateStaffUseCase implements CreateStaffUseCaseInterface
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
    ) {}

    public function create(CreateStaffRequest $request): mixed
    {
        try {
            $repetido = $this->userRepository->validateStaffEmail($request['email']);
            if ($repetido) {
                return [
                    'message' => $this->yaExiste($repetido, 'El correo ' . $request['email'] . ' ya está registrado en esta empresa'),
                    'data'    => ['campo' => 'email'],
                    'status'  => 1,
                ];
            }

            $repetido = $this->userRepository->validateStaffUsername($request['username']);
            if ($repetido) {
                return [
                    'message' => $this->yaExiste($repetido, 'Ese usuario ya está tomado en esta empresa'),
                    'data'    => ['campo' => 'username'],
                    'status'  => 1,
                ];
            }

            // Las dos filas van juntas: si falla la segunda, antes quedaba un
            // usuario sin ficha que después no aparecía en la lista del equipo.
            $user = DB::transaction(function () use ($request) {
                $user = $this->userRepository->createStaff($request);
                $this->userRepository->createStaffUserData($request, $user->id);

                return $user;
            });

        } catch (QueryException $e) {
            Log::error('Alta de personal: ' . $e->getMessage());

            return [
                'message' => 'No se pudo crear el usuario. Vuelva a intentarlo; si sigue igual, avísele al soporte de Netvula.',
                'data'    => ApiResponseConstants::DATA_NULL,
                'status'  => 1,
            ];
        }

        return [
            'message' => 'Usuario creado correctamente. Ya puede entrar con «' . $request['username'] . '».',
            'data'    => ['id' => $user->id],
            'status'  => 0,
        ];
    }

    /** El usuario repetido puede ser una cuenta eliminada: conviene decirlo. */
    private function yaExiste(mixed $usuario, string $base): string
    {
        $eliminado = isset($usuario->active) && (int) $usuario->active === 0;

        return $eliminado
            ? $base . ', en una cuenta desactivada. Reactivala desde la lista del equipo o use otro dato.'
            : $base . '.';
    }
}

<?php

namespace App\UseCases\Company;

use App\Constants\ApiResponseConstants;
use App\Http\Requests\Company\CreateStaffRequest;
use App\Repositories\Interfaces\UserRepositoryInterface;
use App\UseCases\Company\Interfaces\CreateStaffUseCaseInterface;
use Illuminate\Database\QueryException;

class CreateStaffUseCase implements CreateStaffUseCaseInterface
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
    ) {}

    public function create(CreateStaffRequest $request): mixed
    {
        try {
            if ($this->userRepository->validateStaffEmail($request['email'])) {
                return ['message' => 'El correo ya está registrado en esta empresa', 'data' => null, 'status' => 1];
            }

            if ($this->userRepository->validateStaffUsername($request['username'])) {
                return ['message' => 'El nombre de usuario ya está en uso', 'data' => null, 'status' => 1];
            }

            $user = $this->userRepository->createStaff($request);
            $this->userRepository->createStaffUserData($request, $user->id);

        } catch (QueryException $e) {
            return ['message' => 'Error al crear el usuario: ' . $e->getMessage(), 'data' => ApiResponseConstants::DATA_NULL, 'status' => 1];
        }

        return ['message' => 'Usuario creado exitosamente', 'data' => ApiResponseConstants::DATA_NULL, 'status' => 0];
    }
}

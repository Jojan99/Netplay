<?php

namespace App\UseCases\User;

use App\Constants\ApiResponseConstants;
use App\Constants\EncryptionKeyConstants;
use App\Http\Requests\User\CreateUserDataRequest;
use App\Repositories\Interfaces\CountryRepositoryInterface;
use App\Repositories\Interfaces\DniRepositoryInterface;
use App\Repositories\Interfaces\GenderRepositoryInterface;
use App\Repositories\Interfaces\NotificationRepositoryInterface;
use App\Repositories\Interfaces\TypeCurrencisRepositoryInterface;
use App\Repositories\Interfaces\UserRepositoryInterface;
use App\UseCases\EmailSend\SendMailUseCase;
use App\UseCases\User\Interfaces\CreateUserDataUseCaseInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;




/**
 * Clase del caso de uso signin
 *
 * @package App\UseCases\User
 * @author NetPlay <Netplay>
 * @copyright 2023/09/22
 */
class CreateUserDataUseCase implements CreateUserDataUseCaseInterface
{
    /**
     * Constructor de la clase
     *
     * @param UserRepositoryInterface $userRepository

     */

    public function __construct(
        private UserRepositoryInterface $userRepository,

    ) {
    }

    /**
     * @param CreateUserDataRequest $data
     * @return mixed
     */
    public function createUserData(CreateUserDataRequest $data): mixed
    {
        try {
            if ($data['password'] != $data['confirPassword']) return ['message' => 'Check confirm Password does not match', 'data' => 8, 'status' => 1];
            if ($this->userRepository->validateUserEmail($data['email'])) return ['message' => 'The email already exists', 'data' => 4, 'status' => 1];
            if ($this->userRepository->validateUserPhone($data['phone']))  return ['message' => 'The phone already exists', 'data' => 5, 'status' => 1];
            if ($this->userRepository->validateUserDni($data['dni'])) return ['message' => 'ID already exists', 'data' => 6, 'status' => 1];
            $user = $this->userRepository->createUser($data);
            if ($user) {
                $data['userId'] = $user['id'];
                $this->userRepository->createUserData($data);
            } else {
                return ['message' => 'Error creating user', 'data' => 9, 'status' => 1];
            }
        } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
            \Illuminate\Support\Facades\Artisan::call('cache:clear');
            return ['message' => 'Error decrypting token sponsor', 'data' => 10, 'status' => 1];
        } catch (QueryException $err) {
            return ['message' => 'An error occurred while creating the user: ' . $err->getMessage(), 'data' => ApiResponseConstants::DATA_NULL, 'status' => 1];
        }

        return ['message' => 'Usuario creado con éxito', 'status' => 0, 'data' => ApiResponseConstants::DATA_NULL];
    }
}

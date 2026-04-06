<?php

namespace App\UseCases\User;

use App\Constants\ApiResponseConstants;
use App\Http\Requests\User\CreateUserDataRequest;
use App\Repositories\Interfaces\UserRepositoryInterface;
use App\Repositories\Interfaces\FacturationRepositoryInterface;
use App\UseCases\User\Interfaces\CreateUserDataUseCaseInterface;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use App\Repositories\Interfaces\InternetInfoRepositoryInterface;
use App\Constants\ProfileConstants;
use App\UseCases\ManagementRouter\Interfaces\GetIpAvaliblesUseCaseInterface;
use App\Services\NotificationRouterService;

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
     * @param FacturationRepositoryInterface $facturationRepositoryInterface
     * @param InternetInfoRepositoryInterface $internetInfoRepository
     * @param GetIpAvaliblesUseCaseInterface $getIpAvaliblesUseCaseInterface

     */

    public function __construct(
        private UserRepositoryInterface $userRepository,
        private FacturationRepositoryInterface $FacturationRepositoryInterface,
        private InternetInfoRepositoryInterface $internetInfoRepository,
        private GetIpAvaliblesUseCaseInterface $getIpAvaliblesUseCaseInterface

    ) {
    }

    /**
     * @param CreateUserDataRequest $data
     * @return mixed
     */
    public function createUserData(CreateUserDataRequest $data): mixed
    {

        try {


            if(getSessionUserProfileId() == ProfileConstants::ADMIN){
                if ($this->userRepository->validateUserEmail($data['email'])) return ['message' => 'The email already exists', 'data' => 4, 'status' => 1];
                if ($this->userRepository->validateUserPhone($data['phone']))  return ['message' => 'The phone already exists', 'data' => 5, 'status' => 1];
                if ($this->userRepository->validateUserDni($data['dni'])) return ['message' => 'ID already exists', 'data' => 6, 'status' => 1];
                
                    \Log::info('REGISTERING USER IN MIKROTIK', [
                        'ip' => $data['ip_assignment_id'] ?? 'N/A',
                        'vlan' => $data['countryId'] ?? 'N/A',
                        'dni' => $data['dni'] ?? 'N/A'
                    ]);

                    $pasa = $this->getIpAvaliblesUseCaseInterface->registerIpInArp(
                        ip: $data['ip_assignment_id'],
                        mac: '',
                        vlan: $data['countryId'],
                        comment: $data['dni']
                    );

                    if(!$pasa){
                        \Log::error('FAILED TO REGISTER IP IN MIKROTIK', [
                            'ip' => $data['ip_assignment_id'],
                            'vlan' => $data['countryId'],
                            'dni' => $data['dni']
                        ]);
                        return ['message' => 'Error registrando usuario en Mikrotik. Contacte al administrador.', 'status' => 1, 'data' => 'MIKROTIK_SYNC_ERROR'];
                    }

                    \Log::info('USER MIKROTIK REGISTRATION SUCCESSFUL', [
                        'ip' => $data['ip_assignment_id'],
                        'dni' => $data['dni']
                    ]);

                $user = $this->userRepository->createUser($data);
                if ($user) {
                    $data['userId'] = $user['id'];

                    $data['ip_assignment_id'] = $this->internetInfoRepository->AssignemetIpUser($data['ip_assignment_id'],$user['id']);
               
                    $this->userRepository->createUserData($data);
    
                    $today = Carbon::now();

                    if ($data['group'] == 3) {
                        // Ambos cortes: día 15 y día 30
                        $fecha15 = (clone $today)->setDate($today->format('Y'), $today->format('m'), 15)->format('Y-m-d');
                        $fecha30 = (clone $today)->setDate($today->format('Y'), $today->format('m'), 30)->format('Y-m-d');
                        $this->FacturationRepositoryInterface->createCabFacturation($user['id'], 1, $fecha15);
                        $this->FacturationRepositoryInterface->createCabFacturation($user['id'], 2, $fecha30);
                    } else {
                        $dia = $data['group'] == 1 ? 15 : 30;
                        $fecha = (clone $today)->setDate($today->format('Y'), $today->format('m'), $dia)->format('Y-m-d');
                        $this->FacturationRepositoryInterface->createCabFacturation($user['id'], $data['group'], $fecha);
                    }
                } else {
                    return ['message' => 'Error creating user', 'data' => 9, 'status' => 1];
                }
            }else{
                return ['message' => 'Accion no permitida', 'status' => 1, 'data' => ''];
            }

        
        } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
            \Illuminate\Support\Facades\Artisan::call('cache:clear');
            return ['message' => 'Error decrypting token sponsor', 'data' => 10, 'status' => 1];
        } catch (QueryException $err) {
            return ['message' => 'An error occurred while creating the user: ' . $err->getMessage(), 'data' => ApiResponseConstants::DATA_NULL, 'status' => 1];
        }

        NotificationRouterService::dispatch(
            getSessionCompanyId(),
            'new_user',
            "👤 *Nuevo usuario registrado*\n\n" .
            "Nombre: *{$data['names']} {$data['lastname']}*\n" .
            "Cédula: *{$data['dni']}*\n" .
            "Teléfono: *{$data['phone']}*\n" .
            "Dirección: *{$data['address']}*"
        );

        return ['message' => 'Usuario creado con éxito', 'status' => 0, 'data' => ApiResponseConstants::DATA_NULL];
    }
}

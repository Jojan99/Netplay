<?php

namespace App\UseCases\User;

use App\Constants\ApiResponseConstants;
use App\Http\Requests\User\CreateUserDataRequest;
use App\Repositories\Interfaces\UserRepositoryInterface;
use App\Repositories\Interfaces\FacturationRepositoryInterface;
use App\UseCases\User\Interfaces\CreateUserDataUseCaseInterface;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use App\Repositories\Interfaces\InternetInfoRepositoryInterface;
use App\UseCases\ManagementRouter\Interfaces\GetIpAvaliblesUseCaseInterface;

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


            if(getSessionUserProfileId() == 2){
                if ($this->userRepository->validateUserEmail($data['email'])) return ['message' => 'The email already exists', 'data' => 4, 'status' => 1];
                if ($this->userRepository->validateUserPhone($data['phone']))  return ['message' => 'The phone already exists', 'data' => 5, 'status' => 1];
                if ($this->userRepository->validateUserDni($data['dni'])) return ['message' => 'ID already exists', 'data' => 6, 'status' => 1];
                
                    $pasa = $this->getIpAvaliblesUseCaseInterface->registerIpInArp(
                        ip: $data['ip_assignment_id'],
                        mac: '',
                        vlan: $data['countryId'],
                        comment: $data['dni']
                    );

                    if($pasa){
                $user = $this->userRepository->createUser($data);
                if ($user) {
                    $data['userId'] = $user['id'];

                    $data['ip_assignment_id'] = $this->internetInfoRepository->AssignemetIpUser($data['ip_assignment_id'],$user['id']);
               
                    $this->userRepository->createUserData($data);
    
                    if($data['group'] == 1){
                        $dia = 15;
                    }else{
                        $dia = 30;
                    }
    
                    $today = Carbon::now();
                    $today->setDate($today->format('Y'), $today->format('m'), $dia);
                    $fecha = $today->format('Y-m-d');
    
                    $this->FacturationRepositoryInterface->createCabFacturation($user['id'],$data['group'],$fecha);
                } else {
                    return ['message' => 'Error creating user', 'data' => 9, 'status' => 1];
                }
                    }else{
                            return ['message' => 'Hubo un error intentando crar el usuario en el mikrotik', 'status' => 1, 'data' => ''];
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

        return ['message' => 'Usuario creado con éxito', 'status' => 0, 'data' => ApiResponseConstants::DATA_NULL];
    }
}

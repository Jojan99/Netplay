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
use Illuminate\Support\Facades\DB;
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


            $profileName = strtoupper(
                DB::table('profiles')->where('id', getSessionUserProfileId())->value('name') ?? ''
            );

            if ($profileName === 'ADMIN') {
                if ($this->userRepository->validateUserEmail($data['email'])) return ['message' => 'The email already exists', 'data' => 4, 'status' => 1];
                if ($this->userRepository->validateUserPhone($data['phone']))  return ['message' => 'The phone already exists', 'data' => 5, 'status' => 1];
                if ($this->userRepository->validateUserDni($data['dni'])) return ['message' => 'ID already exists', 'data' => 6, 'status' => 1];
                
                    // Los dos tipos de conexión se dan de alta distinto: con IP
                    // fija el cliente vive en el ARP del router, con PPPoE se le
                    // crea una credencial y la IP se la da el pool.
                    $esPppoe = ($data['connection_type'] ?? 'static') === 'pppoe';

                    if ($esPppoe) {
                        $error = $this->altaPppoe($data);

                        if ($error) {
                            return ['message' => $error, 'status' => 1, 'data' => 'MIKROTIK_SYNC_ERROR'];
                        }

                        // Sin IP fija que asignar: la ficha de IP queda vacía.
                        $data['ip_assignment_id'] = null;
                    } else {
                        \Log::info('REGISTERING USER IN MIKROTIK', [
                            'ip' => $data['ip_assignment_id'] ?? 'N/A',
                            'vlan' => $data['vlan'] ?? 'N/A',
                            'dni' => $data['dni'] ?? 'N/A'
                        ]);

                        $pasa = $this->getIpAvaliblesUseCaseInterface->registerIpInArp(
                            ip: $data['ip_assignment_id'],
                            mac: '',
                            vlan: $data['vlan'] ?? '',
                            comment: $data['dni']
                        );

                        if(!$pasa){
                            \Log::error('FAILED TO REGISTER IP IN MIKROTIK', [
                                'ip' => $data['ip_assignment_id'],
                                'vlan' => $data['vlan'] ?? '',
                                'dni' => $data['dni']
                            ]);
                            return ['message' => 'Error registrando usuario en Mikrotik. Contacte al administrador.', 'status' => 1, 'data' => 'MIKROTIK_SYNC_ERROR'];
                        }

                        \Log::info('USER MIKROTIK REGISTRATION SUCCESSFUL', [
                            'ip' => $data['ip_assignment_id'],
                            'dni' => $data['dni']
                        ]);
                    }

                $user = $this->userRepository->createUser($data);
                if ($user) {
                    $data['userId'] = $user['id'];
                    $ipReal = $data['ip_assignment_id'] ?? 'N/A';

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
                try {
            NotificationRouterService::dispatch(
                getSessionCompanyId(),
                'new_user',
                "👤 *Nuevo usuario registrado*\n\n" .
                "Nombre: *{$data['names']} {$data['lastname']}*\n" .
                "Cédula: *{$data['dni']}*\n" .
                "Teléfono: *{$data['phone']}*\n" .
                "Dirección: *{$data['address']}*\n" .
                "IP: *{$ipReal}*\n" .
                "VLAN: *{$data['vlan']}*\n"
            );
        } catch (\Throwable $e) {
            \Log::error('Error enviando notificación new_user', [
                'error' => $e->getMessage(),
                'data'  => $data
            ]);
        }

        return ['message' => 'Usuario creado con éxito', 'status' => 0, 'data' => ApiResponseConstants::DATA_NULL];
    }

    /**
     * Da de alta la credencial PPPoE en el router.
     *
     * Devuelve el mensaje de error si algo falla, o null si salió bien. Se
     * crea antes que el cliente a propósito: si el router rechaza el alta no
     * queda un cliente en la plataforma que no existe en la red.
     *
     * @param  array<string,mixed>  $data
     */
    private function altaPppoe(array $data): ?string
    {
        $usuario = trim((string) ($data['pppoe_user'] ?? ''));
        $clave   = (string) ($data['pppoe_password'] ?? '');
        // Si no eligieron perfil se usa el del plan: en PPPoE la velocidad la
        // fija el perfil, así que el del plan es el que corresponde.
        $perfil = trim((string) ($data['pppoe_profile'] ?? ''));

        if ($perfil === '') {
            $perfil = (string) \Illuminate\Support\Facades\DB::table('internet_plans')
                ->where('id', $data['planInternet'] ?? 0)
                ->value('pppoe_profile');
        }

        $perfil = $perfil ?: 'default';

        if ($usuario === '' || $clave === '') {
            return 'Para una conexión PPPoE hacen falta el usuario y la contraseña.';
        }

        $token = \Illuminate\Support\Facades\DB::table('conection_routers')
            ->where('company_id', getSessionCompanyId())
            ->when($data['router_id'] ?? null, fn ($q) => $q->where('id', $data['router_id']))
            ->value('token');

        if (!$token) {
            return 'No hay un router configurado para dar de alta la conexión PPPoE.';
        }

        try {
            $servicio = new \App\Services\Red\ServicioPppoe(
                app(\App\Managers\Interfaces\ConectionRouterManagerInterface::class),
                $token
            );

            $servicio->crear($usuario, $clave, $perfil, (string) $data['dni']);

            \Log::info('[PPPoE] Cliente dado de alta', [
                'usuario' => $usuario, 'dni' => $data['dni'], 'perfil' => $perfil,
            ]);

            return null;
        } catch (\Throwable $e) {
            \Log::error('[PPPoE] No se pudo dar de alta', [
                'usuario' => $usuario, 'error' => $e->getMessage(),
            ]);

            return 'No se pudo crear el usuario PPPoE en el router: ' . $e->getMessage();
        }
    }
}

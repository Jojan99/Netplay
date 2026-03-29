<?php

namespace App\UseCases\ManagementRouter;

use App\Repositories\Interfaces\DniRepositoryInterface;
use Illuminate\Database\QueryException;
use App\Constants\ApiResponseConstants;
use App\Http\Requests\Gestions\GestionUserRequest;
use App\Managers\Interfaces\ConectionRouterManagerInterface;
use App\Repositories\Interfaces\ManagementRouterRepositoryInterface;
use App\UseCases\ManagementRouter\Interfaces\UpdateStatusUserUseCaseInterface;
use RouterOS\Exceptions\QueryException as ExceptionsQueryException;
use RouterOS\Query;


/**
 * Clase del caso de uso GetCountrysUseCase
 *
 * @package App\UseCases\Pqr
 * @author Network Golden <sa.networkgolden@gmail.com>
 * @copyright 2022/06/13
 */
class UpdateStatusUserUseCase implements UpdateStatusUserUseCaseInterface
{
    /**
     * Constructor de la clase
     *
     * @param DniRepositoryInterface $dniRepositoryInterface
     */
    protected  $connection;

    public function __construct(
        private ManagementRouterRepositoryInterface $managementRouterRepositoryInterface,
        private ConectionRouterManagerInterface $conectionRouterManagerInterface
    ) {
        $this->connection = $conectionRouterManagerInterface;
    }

    /**
     * @return mixed
     * @param GestionUserRequest $gestionUserRequest
     */
public function UpdateStatus(GestionUserRequest $gestionUserRequest): array
{
    $client = null;

    try {
        error_log('llega');

        $statusCmd = ($gestionUserRequest['status'] == 2)
            ? '/ip/arp/disable'
            : '/ip/arp/enable';

        // RESPUESTA DEL UPDATE (NO SOBREESCRIBIR)
        $routerResponse = $this->managementRouterRepositoryInterface->UpdateStatus($gestionUserRequest);

        error_log(json_encode($routerResponse));


        if (empty($routerResponse['username'])) {
            return [
                'message' => 'No se pudo obtener username para buscar en ARP',
                'status'  => 1,
                'data'    => null
            ];
        }

        // ✅ UNA SOLA CONEXIÓN
        $client = $this->connection->conection('b5c2f0e8-7e82-4a5c-a7d7-0ee8b2d7b905');

        // BUSCAR ARP POR USERNAME
        $query = (new Query('/ip/arp/print'))
            ->where('comment', $routerResponse['username']);

        $user = $client->query($query)->read();

        if (empty($user) || !isset($user[0]['.id'])) {
            return [
                'message' => 'Usuario no encontrado en ARP',
                'status'  => 1,
                'data'    => null
            ];
        }

        // ENABLE / DISABLE ARP
        $query = (new Query($statusCmd))
            ->equal('.id', $user[0]['.id']);

        $client->query($query)->read();

        return [
            'message' => 'consulta realizada con exito',
            'status'  => 0,
            'data'    => true
        ];

    } catch (\Throwable $err) {
        // Opcional: log real para ver causa exacta
        error_log('UpdateStatus error: ' . $err->getMessage());

        return [
            'message' => 'Ocurrio un error',
            'status'  => 1,
            'data'    => ApiResponseConstants::DATA_NULL
        ];
    } finally {
        // ✅ SIEMPRE CERRAR
        if ($client && method_exists($client, 'disconnect')) {
            $client->disconnect();
        }
    }
}


    public function validateMikrotikConnection(): bool
{
    try {
        // Intentar establecer una conexión con MikroTik
        $connection = $this->connection->conection('b5c2f0e8-7e82-4a5c-a7d7-0ee8b2d7b905');
        
        // Realizar una consulta de prueba
        $query = new Query('/system/resource/print');
        $response = $connection->query($query)->read();

        // Verificar si la respuesta es válida
        if (!empty($response)) {
            return true; // Conexión exitosa
        }
    } catch (ExceptionsQueryException $err) {
        error_log("Error de conexión a MikroTik: " . $err->getMessage());
    }
    
    return false; // Fallo en la conexión
}
}


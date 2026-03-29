<?php

namespace App\UseCases\ManagementRouter;

use App\Repositories\Interfaces\DniRepositoryInterface;
use Illuminate\Database\QueryException;
use App\Constants\ApiResponseConstants;
use App\Http\Requests\Gestions\GestionUserRequest;
use App\Managers\Interfaces\ConectionRouterManagerInterface;
use App\Repositories\Interfaces\ManagementRouterRepositoryInterface;
use App\UseCases\ManagementRouter\Interfaces\UpdateStatusUserUseCaseInterface;
use App\UseCases\ManagementRouter\Interfaces\validateMikrotikConnectionUseCaseInterface;
use RouterOS\Exceptions\QueryException as ExceptionsQueryException;
use RouterOS\Query;


/**
 * Clase del caso de uso GetCountrysUseCase
 *
 * @package App\UseCases\Pqr
 * @author Network Golden <sa.networkgolden@gmail.com>
 * @copyright 2022/06/13
 */
class validateMikrotikConnectionUseCase implements validateMikrotikConnectionUseCaseInterface
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
    public function validateMikrotik(): bool
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

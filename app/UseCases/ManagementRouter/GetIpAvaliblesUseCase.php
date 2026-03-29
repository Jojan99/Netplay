<?php

namespace App\UseCases\ManagementRouter;

use App\Repositories\Interfaces\DniRepositoryInterface;
use Illuminate\Database\QueryException;
use App\Constants\ApiResponseConstants;
use App\Http\Requests\Gestions\GestionUserRequest;
use App\Managers\Interfaces\ConectionRouterManagerInterface;
use App\Repositories\Interfaces\ManagementRouterRepositoryInterface;
use App\Repositories\Interfaces\UserRepositoryInterface;
use App\UseCases\ManagementRouter\Interfaces\GetIpAvaliblesUseCaseInterface;
use RouterOS\Exceptions\QueryException as ExceptionsQueryException;
use RouterOS\Query;

use function Safe\json_encode;

/**
 * Clase del caso de uso GetCountrysUseCase
 *
 * @package App\UseCases\Pqr
 * @author Network Golden <sa.networkgolden@gmail.com>
 * @copyright 2022/06/13
 */
class GetIpAvaliblesUseCase implements GetIpAvaliblesUseCaseInterface
{
    /**
     * Constructor de la clase
     *
     * @param DniRepositoryInterface $dniRepositoryInterface
     */
    protected  $connection;

    public function __construct(
        private ManagementRouterRepositoryInterface $managementRouterRepositoryInterface,
        private ConectionRouterManagerInterface $conectionRouterManagerInterface,
        private UserRepositoryInterface $userRepositoryInterface,

    ) {
        $this->connection = $conectionRouterManagerInterface;
    }

    /**
     * @return mixed
     * @param GestionUserRequest $gestionUserRequest
     */
public function GetIpAvalibles(GestionUserRequest $gestionUserRequest): mixed
{   
    try {

        if (getSessionUserProfileId() == 2) {
            return [
                'message' => 'Acción no permitida',
                'status'  => 1,
                'data'    => null
            ];
        }


        $vlan = $gestionUserRequest['vlan'];
        $routerId = 'b5c2f0e8-7e82-4a5c-a7d7-0ee8b2d7b905';

        $api = $this->connection->conection($routerId);

        /** 1️⃣ RED DE LA VLAN */
        $query = new Query('/ip/address/print');
        $query->add('=.proplist=address');
        $query->where('interface', $vlan);

        $address = $api->query($query)->read();

        if (empty($address)) {
            return [
                'message' => 'La VLAN no tiene red asignada',
                'status'  => 1,
                'data'    => null
            ];
        }

        [$gateway] = explode('/', $address[0]['address']);
        $networkBase = substr($gateway, 0, strrpos($gateway, '.') + 1);

        /** 2️⃣ ARP LIVIANO */
        $query = new Query('/ip/arp/print');
        $query->add('=.proplist=address');
        $query->where('interface', $vlan);

        $arpRows = $api->query($query)->read();
        $usedIps = array_column($arpRows, 'address');

        /** 3️⃣ IPs DISPONIBLES LIMITADAS */
        $availableIps = [];
        $maxIps = 20;

        for ($i = 2; $i <= 254; $i++) {

            $ip = $networkBase . $i;

            if ($ip !== $gateway && !in_array($ip, $usedIps, true)) {
                $availableIps[] = ['ip' => $ip];

                if (count($availableIps) >= $maxIps) {
                    break;
                }
            }
        }

        return [
            'message' => 'IPs disponibles encontradas',
            'status'  => 0,
            'data'    => [
                'vlan'    => $vlan,
                'gateway' => $gateway,
                'ips'     => $availableIps
            ]
        ];

    } catch (\Throwable $err) {

        return [
            'message' => 'Ocurrió un error al consultar el router',
            'status'  => 1,
            'data'    => null
        ];
    }
}



public function getLanSegments(): mixed
{
    try {

        // ✔ solo perfil 2
        if (getSessionUserProfileId() == 2) {
            return [
                'message' => 'Acción no permitida',
                'status'  => 1,
                'data'    => null
            ];
        }

        // ⏱️ importante


        $routerId = 'b5c2f0e8-7e82-4a5c-a7d7-0ee8b2d7b905';
        $api = $this->connection->conection($routerId);

        /**
         * 1️⃣ TRAER TODAS LAS IPs (sin filtro)
         */
    $query = new Query('/ip/address/print');
$query->add('=.proplist=address,interface');

$rows = $api->query($query)->read();


        $rows = $api->query($query)->read();
      

        error_log('IP ADDRESS RAW: ' . json_encode($rows));

        if (empty($rows)) {
            return [
                'message' => 'No se encontraron direcciones IP',
                'status'  => 1,
                'data'    => []
            ];
        }

        /**
         * 2️⃣ FILTRAR EN PHP (FORMA SEGURA)
         */
        $segments = [];

        foreach ($rows as $row) {

            if (!isset($row['address'], $row['interface'])) {
                continue;
            }
            // solo /24 /23 /22
            if (!preg_match('/\/(24|23|22)$/', $row['address'])) continue;
            // Ej: 192.168.107.1/24
            [$gateway, $mask] = explode('/', $row['address']);

            $networkBase = substr($gateway, 0, strrpos($gateway, '.')) . '.0';

            $segments[] = [
                'names'    => $row['interface'],
                'network' => $networkBase . '/' . $mask,
                'gateway' => $gateway,
                'mask'    => '/' . $mask
            ];
        }

        return [
            'message' => 'Segmentos LAN obtenidos correctamente',
            'status'  => 0,
            'data'    => $segments
        ];

    } catch (ExceptionsQueryException $err) {

        error_log('MIKROTIK ERROR: ' . $err->getMessage());

        return [
            'message' => 'Ocurrió un error al consultar el router',
            'status'  => 1,
            'data'    => null
        ];
    }
}


public function autorizarServicio(GestionUserRequest $request): array
{
    try {

        $dataUser = $this->userRepositoryInterface
            ->getUserById($request['service_id']);

        $routerId = 'b5c2f0e8-7e82-4a5c-a7d7-0ee8b2d7b905';
        $api = $this->connection->conection($routerId);

        // 🔹 Buscar ARP existente
        $query = new Query('/ip/arp/print');
        $query->where('comment', $dataUser['dni']);
        $query->add('=.proplist=.id');

        $exists = $api->query($query)->read();

        // ❌ NO existe → mensaje
        if (empty($exists)) {
            return [
                'message' => 'El cliente no tiene ARP registrado, no se puede actualizar',
                'data'    => null,
                'status'  => ApiResponseConstants::ERROR
            ];
        }

        // ✔️ Existe → actualizar MAC
        $arpId = $exists[0]['.id'];
        $mac   = $request['mac'] ?: '00:00:00:00:00:00';

        $query = new Query('/ip/arp/set');
        $query->equal('.id', $arpId);
        $query->equal('mac-address', $mac);

        $api->query($query)->read();

        return [
            'message' => 'MAC actualizada correctamente',
            'data'    => true,
            'status'  => ApiResponseConstants::SUCCESS
        ];

    } catch (\Throwable $e) {
        return [
            'message' => 'Error al actualizar MAC: ' . $e->getMessage(),
            'data'    => null,
            'status'  => ApiResponseConstants::ERROR
        ];
    }
}




public function registerIpInArp(
    string $ip,
    string $mac,
    string $vlan,
    string $comment
): bool {
    try {

        error_log($ip);
        error_log($mac);
        error_log($vlan);
        error_log($comment);

        $routerId = 'b5c2f0e8-7e82-4a5c-a7d7-0ee8b2d7b905';
        $api = $this->connection->conection($routerId);

        /**
         * 🔹 VALIDAR SI YA EXISTE EN ARP
         */
        $query = new Query('/ip/arp/print');
        $query->where('address', $ip);
        $query->add('=.proplist=.id');

        $exists = $api->query($query)->read();

        if (!empty($exists)) {
            // Ya existe, no volver a crear
            return true;
        }

        if($mac == ''){
          $mac = '00:00:00:00:00:00';
        }

        /**
         * 🔹 CREAR REGISTRO ARP
         */
        $query = new Query('/ip/arp/add');
        $query->equal('address', $ip);
        $query->equal('mac-address', $mac);
        $query->equal('interface', $vlan);
        $query->equal('comment', $comment);
        //$query->equal('published', 'yes'); // opcional, recomendado ISP

        $api->query($query)->read();

        return true;

    } catch (QueryException $e) {

        // \Log::error('MIKROTIK ARP ERROR', [
        //     'ip' => $ip,
        //     'mac' => $mac,
        //     'vlan' => $vlan,
        //     'error' => $e->getMessage()
        // ]);

        return false;
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


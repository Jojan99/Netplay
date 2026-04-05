<?php

namespace App\Http\Controllers;

use App\Constants\ApiResponseConstants;
use App\Http\Requests\Gestions\GestionUserRequest;
use App\Managers\Interfaces\ConectionRouterManagerInterface;
use App\Models\GestionIp;
use App\UseCases\Dni\Interfaces\GetDniUseCaseInterface;
use RouterOS\Client;
use RouterOS\Config;
use RouterOS\Query;
use Illuminate\Http\JsonResponse;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Facades\JWTAuth;

class DniController extends Controller
{


    protected  $connection;

    public function __construct(
        private ConectionRouterManagerInterface $conectionRouterManagerInterface,
        private \App\Repositories\Interfaces\RouterRepositoryInterface $routerRepositoryInterface,
    ) {
        $this->connection = $conectionRouterManagerInterface;
    }

    private function getCompanyRouterId(?int $companyId = null): string
    {
        // 1. Parámetro directo, 2. Sesión, 3. JWT manual (para SSE que no pasa por middleware)
        if (!$companyId) {
            $companyId = 1;
        }
        if (!$companyId) {
            try {
                $rawToken = JWTAuth::getToken() ?: request()->query('token');
                if ($rawToken) {
                    $companyId = JWTAuth::setToken($rawToken)->toUser()->company_id ?? null;
                }
            } catch (\Exception $e) {}
        }
        if (!$companyId) {
            throw new \RuntimeException('Sesión sin empresa asociada');
        }
        $token = $this->routerRepositoryInterface->getTokenByCompany($companyId);
        if (!$token) {
            throw new \RuntimeException('No hay router configurado para esta empresa');
        }
        return $token;
    }

    /**
     * @param GetDniUseCaseInterface $getDniUseCaseInterface
     * @return object
     */
    public function getDniAll(
        GetDniUseCaseInterface $getDniUseCaseInterface
    ): object {
        try {
            $result = $getDniUseCaseInterface->getDniAll();
        } catch (JWTException $e) {

            // Respuesta en caso de excepción
            return standardApiReponse(
                'Currency rates could not be queried: ' . $e->getMessage(),
                ApiResponseConstants::DATA_NULL,
                ApiResponseConstants::ERROR,
                JsonResponse::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        return standardApiReponse(
            $result['message'],
            $result['data'],
            $result['status'],
            JsonResponse::HTTP_OK
        );
    }

    public function pruebaMikro(): array
    {

        try {
            $response = $this->connection->conection($this->getCompanyRouterId())->qr('/ip/address/print');
        } catch (JWTException $e) {

            // Respuesta en caso de excepción
            return standardApiReponse(
                'Currency rates could not be queried: ' . $e->getMessage(),
                ApiResponseConstants::DATA_NULL,
                ApiResponseConstants::ERROR,
                JsonResponse::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        return [
            'message' => 'consulta realizada con exito',
            'status' => 0,
            'data' => $response
        ];
    }

    public function createUser(): array
    {

        try {

            $query =
                (new Query('/ip/arp/add'))
                ->equal('address', '192.168.9.3')
                ->equal('interface', 'TRONCAL 1')
                ->equal('mac-address', '00:00:00:00:40:27')
                ->equal('comment', 'Marcela Te Amo');

            $response = $this->connection->conection($this->getCompanyRouterId())->query($query)->read();
        } catch (JWTException $e) {

            // Respuesta en caso de excepción
            return standardApiReponse(
                'Currency rates could not be queried: ' . $e->getMessage(),
                ApiResponseConstants::DATA_NULL,
                ApiResponseConstants::ERROR,
                JsonResponse::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        return [
            'message' => 'consulta realizada con exito',
            'status' => 0,
            'data' => $response
        ];
    }

    /**
     * @param GestionUserRequest $gestionUserRequest
     * @return array
     */
    public function disableUser(GestionUserRequest $gestionUserRequest): array
    {


        try {
            $query =
            (new Query('/ip/arp/print'))
                ->where('comment', $gestionUserRequest['ip']);

                error_log("query".json_encode($query));

            error_log(json_encode($query));

            $user = $this->connection->conection($this->getCompanyRouterId())->query($query)->read();

            error_log(json_encode($user));
            if (!empty($user[0]['.id'])) {
                $query =
                (new Query('/ip/arp/enable'))
                    ->equal('.id', $user[0]['.id']);
    
                $response = $this->connection->conection($this->getCompanyRouterId())->query($query)->read();
            }else{
                $response = "error";
            }
        
        } catch (JWTException $e) {

            // Respuesta en caso de excepción
            return standardApiReponse(
                'Currency rates could not be queried: ' . $e->getMessage(),
                ApiResponseConstants::DATA_NULL,
                ApiResponseConstants::ERROR,
                JsonResponse::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        return [
            'message' => 'consulta realizada con exito',
            'status' => 0,
            'data' => $response
        ];
    }

    public function pruebaMikroAll(GetDniUseCaseInterface $getDniUseCaseInterface): array
    {
        try {
            $result = $getDniUseCaseInterface->conection();
            error_log("result" . json_encode($result));
            $response = $result->qr('/ip/address/print');

            print_r($response, true);
        } catch (JWTException $e) {

            // Respuesta en caso de excepción
            return standardApiReponse(
                'Currency rates could not be queried: ' . $e->getMessage(),
                ApiResponseConstants::DATA_NULL,
                ApiResponseConstants::ERROR,
                JsonResponse::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        return [
            'message' => 'consulta realizada con exito',
            'status' => 0,
            'data' => $response
        ];
    }
      /**
     * @param GestionUserRequest $gestionUserRequest
     */
    public function pruebaMikroPing(GestionUserRequest $gestionUserRequest)
    {
        @ini_set('implicit_flush', 1);
        @ini_set('output_buffering', 'off');
        @ini_set('zlib.output_compression', 'off');
    
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
    
        // Headers SSE
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
    
        try {

            $query =
            (new Query('/ip/arp/print'))
                ->where('comment', $gestionUserRequest['dni']);


            $user = $this->connection->conection($this->getCompanyRouterId())->query($query)->read();
            $ip = $user[0]['address'];
            $connection = $this->connection->conection($this->getCompanyRouterId());
    
            for ($i = 0; $i < $gestionUserRequest['count']; $i++) {
                // Verificar si la conexión sigue activa con un ping ligero
                try {
                    $connection->query(new Query('/system/identity/print'))->read();
                } catch (\Exception $e) {
                    echo "data: " . json_encode(["error" => "Conexión perdida con MikroTik"]) . "\n\n";
                    flush();
                    break;
                }
    
                // Realizar el ping
                $query = (new Query('/ping'))
                    ->equal('address', $ip)
                    ->equal('count', '1');  
    
                $response = $connection->query($query)->read();
    
                if (!empty($response)) {
                    echo "data: " . json_encode($response[0]) . "\n\n";
                    flush();
                }
    
                // Pausa de 1 segundo para evitar bloqueos
                usleep(1000000);
            }
    
            // Señal de finalización
            echo "data: " . json_encode(["message" => "done"]) . "\n\n";
            flush();
        } catch (\Exception $e) {
            echo "data: " . json_encode(["error" => $e->getMessage()]) . "\n\n";
            flush();
        }
    
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
    
        exit();
    }


public function pruebaMikroPingBots(GestionUserRequest $gestionUserRequest)
{
    try {

        $query = (new Query('/ip/arp/print'))
            ->where('comment', $gestionUserRequest['dni']);

        $user = $this->connection
            ->conection($this->getCompanyRouterId())
            ->query($query)
            ->read();

        if(empty($user)){
            return response()->json([
                "resultado" => "Fail",
                "promedio_ms" => 0,
                "pings_recibidos" => 0,
                "pings_enviados" => $gestionUserRequest['count'] ?? 5
            ]);
        }

        $ip = $user[0]['address'];

        $connection = $this->connection
            ->conection($this->getCompanyRouterId());

        $count = $gestionUserRequest['count'] ?? 5;

        $latencias = [];
        $received = 0;

        for ($i = 0; $i < $count; $i++) {

            $query = (new Query('/ping'))
                ->equal('address', $ip)
                ->equal('count', '1');

            $response = $connection->query($query)->read();

            if (!empty($response)) {

                $ping = $response[0];

                if(isset($ping['time'])){

                    preg_match('/(\d+)ms/', $ping['time'], $ms);

                    if(isset($ms[1])){
                        $latencias[] = (int)$ms[1];
                    }

                    $received++;
                }
            }

            usleep(500000);
        }

        $avg = 0;

        if($received > 0){
            $avg = array_sum($latencias) / count($latencias);
        }

        $status = "Fail";

        if($received == $count && $avg < 80){
            $status = "OK";
        }

        return response()->json([
            "resultado" => $status,
            "promedio_ms" => round($avg,2),
            "pings_recibidos" => $received,
            "pings_enviados" => $count
        ]);

    } catch (\Exception $e) {

        return response()->json([
            "resultado" => "Fail",
            "promedio_ms" => 0,
            "pings_recibidos" => 0,
            "pings_enviados" => $gestionUserRequest['count'] ?? 5
        ]);
    }
}

public function diagnosticoConexionBot(GestionUserRequest $request)
{
    try {

        $dni = $request['dni'];
        $count = $request['count'] ?? 5;

        $connection = $this->connection
            ->conection($this->getCompanyRouterId());

        /*
        ================================
        1️⃣ BUSCAR CLIENTE EN ARP
        ================================
        */

        $query = (new Query('/ip/arp/print'))
            ->where('comment', $dni);

        $arpUser = $connection->query($query)->read();

        if(empty($arpUser)){
            return response()->json([
                "resultado" => "SIN_CONECTIVIDAD",
                "promedio_ms" => 0,
                "pings_recibidos" => 0,
                "pings_enviados" => $count
            ]);
        }

        $ip = $arpUser[0]['address'];

        /*
        ================================
        2️⃣ HACER PING
        ================================
        */

        $latencias = [];
        $received = 0;

        for ($i = 0; $i < $count; $i++) {

            $queryPing = (new Query('/ping'))
                ->equal('address', $ip)
                ->equal('count', '1');

            $response = $connection->query($queryPing)->read();

            if (!empty($response)) {

                $ping = $response[0];

                if(isset($ping['time'])){

                    preg_match('/(\d+)ms/', $ping['time'], $ms);

                    if(isset($ms[1])){
                        $latencias[] = (int)$ms[1];
                    }

                    $received++;
                }
            }

            usleep(500000);
        }

        /*
        ================================
        3️⃣ CALCULAR PROMEDIO
        ================================
        */

        $avg = 0;

        if($received > 0){
            $avg = array_sum($latencias) / count($latencias);
        }

        /*
        ================================
        4️⃣ DIAGNÓSTICO
        ================================
        */

        $estado = "SIN_RESPUESTA_RED";

        if($received >= 4){
            $estado = "OK";
        }
        elseif($received >= 2){
            $estado = "INTERMITENTE";
        }

        return response()->json([
            "resultado" => $estado,
            "ip_cliente" => $ip,
            "promedio_ms" => round($avg,2),
            "pings_recibidos" => $received,
            "pings_enviados" => $count
        ]);

    } catch (\Exception $e) {

        return response()->json([
            "resultado" => "ERROR",
            "mensaje" => $e->getMessage()
        ],500);
    }
}

}
    
    
    
    
    // Enviar la consulta y recibir la respuesta


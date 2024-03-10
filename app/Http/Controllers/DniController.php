<?php

namespace App\Http\Controllers;

use App\Constants\ApiResponseConstants;
use App\IManagerConection;
use App\UseCases\Dni\Interfaces\GetDniUseCaseInterface;
use RouterOS\Client;
use RouterOS\Config;
use RouterOS\Query;
use Illuminate\Http\JsonResponse;
use Tymon\JWTAuth\Exceptions\JWTException;

class DniController extends Controller
{
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

    public function pruebaMikro(): array {

try{
        $config = new \RouterOS\Config([
            'host' => '190.144.128.35',
            'user' => 'admin',
            'pass' => 'net4dm1n1str4d0r',
            'port' => 8724,
        ]);
        $client = new \RouterOS\Client($config);

        $response = $client->qr('/ip/address/print');


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


    public function pruebaMikroAll(GetDniUseCaseInterface $getDniUseCaseInterface): object {
        try {
            $result = $getDniUseCaseInterface->conection();
            error_log("result".json_encode($result));
            $response = $result->qr('/ip/address/print');

            print_r($response,true);

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

    public function pruebaMikroPing(){


        $config = new \RouterOS\Config([
            'host' => '190.144.128.35',
            'user' => 'admin',
            'pass' => 'net4dm1n1str4d0r',
            'port' => 8724,
            'timeout' => 60,
        ]);
        $client = new \RouterOS\Client($config);

        $query = new Query('/ping');
        $query->equal('address', '192.168.9.12');
        $query->equal('count', '4');

        // Enviar la consulta y recibir la respuesta
        $responses = $client->qr($query);
        // Enviar la consulta y recibir la respuesta

        return [
            'message' => 'consulta realizada con exito',
            'status' => 0,
            'data' => $responses
        ];

        }
        // Enviar la consulta y recibir la respuesta

}

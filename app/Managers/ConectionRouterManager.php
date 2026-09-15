<?php

namespace App\Managers;

use App\Managers\Interfaces\ConectionRouterManagerInterface;
use App\Repositories\Interfaces\RouterRepositoryInterface;
use Illuminate\Support\Facades\Log;

class ConectionRouterManager implements ConectionRouterManagerInterface
{

    protected  $data;

    public function __construct(private RouterRepositoryInterface $routerRepositoryInterface)
    {
        $this->data = $routerRepositoryInterface;
    }


    private function getDataConection($token):mixed{

        return $this->data->getCredentialRouter($token);
    }


    public function conection($token): mixed
{
    $data = $this->getDataConection($token);

    $parsed = \App\Helpers\RouterHostParser::parse($data[0]['host'], $data[0]['port'] ?? null);

    $config = [
        'host' => $parsed['host'],
        'user' => $data[0]['user'],
        'pass' => $data[0]['pass'],
        'port' => $parsed['port'],
        // Conectar: 2 intentos de 5 s. Con los valores de la librería (10
        // intentos) y 30 s por intento, un router apagado dejaba la pantalla
        // varios minutos en "Consultando router…". Leer sigue teniendo 30 s.
        'timeout'        => 5,
        'attempts'       => 2,
        'delay'          => 1,
        'socket_timeout' => 30,
    ];

    \Log::info('Intentando conectar a MikroTik', [
        'host' => $config['host'],
        'user' => $config['user'],
        'port' => $config['port'],
    ]);
    

    try {
        $client = new \RouterOS\Client($config);

        \Log::info('Conexión exitosa a MikroTik');

        return $client;

    } catch (\Exception $e) {

        \Log::error('Error conectando a MikroTik', [
            'message' => $e->getMessage(),
        ]);

        throw $e;
    }
}
}



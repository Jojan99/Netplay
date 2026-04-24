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

    $config = [
        'host' => $data[0]['host'],
        'user' => $data[0]['user'],
        'pass' => $data[0]['pass'],
        'port' => $data[0]['port'],
        'timeout' => 30,
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



<?php

namespace App\Managers;

use App\Managers\Interfaces\ConectionRouterManagerInterface;
use App\Repositories\Interfaces\RouterRepositoryInterface;

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


    public function conection($token):mixed{
        $data = $this->getDataConection($token);

        error_log(json_encode($data));

        $defaultConfig = [
            'host' => $data[0]['host'],
            'user' => $data[0]['user'],
            'pass' => $data[0]['pass'],
            'port' => $data[0]['port'],
            'timeout' => 60,
        ];
    
        $client = new \RouterOS\Client($defaultConfig);
    
       return $client;
    }
}



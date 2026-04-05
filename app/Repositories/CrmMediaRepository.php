<?php

namespace App\Repositories;

use App\Repositories\Interfaces\CrmMediaRepositoryInterface;
use Illuminate\Support\Facades\Http;

class CrmMediaRepository implements CrmMediaRepositoryInterface
{
    private string $instance;
    private string $token;

    public function __construct()
    {
        $this->instance = config('services.whatsapp.instance');
        $this->token    = config('services.whatsapp.token');
    }

    public function upload(string $fileUrl): string
    {
        $response = Http::asForm()->post(
            "https://api.ultramsg.com/{$this->instance}/media/upload",
            [
                'token' => $this->token,
                'file'  => $fileUrl,
            ]
        )->json();

        if (!isset($response['media'])) {
            throw new \Exception('Error subiendo media a UltraMsg');
        }

        return $response['media'];
    }
}

<?php

namespace App\Repositories;

use App\Models\ConectionRouter;
use App\Repositories\Interfaces\RouterRepositoryInterface;

class RouterRepository implements RouterRepositoryInterface


{
  /**
     * @return mixed
     */
    public function getCredentialRouter($token): mixed
    {
        return ConectionRouter::where('token', $token)->get();
    }

    /**
     * @param int $companyId
     * @return mixed
     */
    public function getTokenByCompany(int $companyId): mixed
    {
        $router = ConectionRouter::where('company_id', $companyId)->first();
        return $router ? $router->token : null;
    }

    public function getRouterByCompany(int $companyId): mixed
    {
        return ConectionRouter::where('company_id', $companyId)->first();
    }

    public function saveRouterConfig(int $companyId, array $data): mixed
    {
        $router = ConectionRouter::where('company_id', $companyId)->first();

        $payload = [
            'host'       => $data['host'],
            'user'       => $data['user'],
            'pass'       => $data['pass'],
            'port'       => $data['port'] ?? 8728,
            'company_id' => $companyId,
        ];

        // Generate token from host:user
        $payload['token'] = md5($companyId . $data['host'] . $data['user']);

        if ($router) {
            $router->update($payload);
            return $router->fresh();
        }

        return ConectionRouter::create($payload);
    }
}
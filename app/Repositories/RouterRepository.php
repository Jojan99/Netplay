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

    public function getRoutersByCompany(int $companyId): mixed
    {
        return ConectionRouter::where('company_id', $companyId)
            ->orderBy('id')
            ->get(['id', 'name', 'host', 'user', 'port', 'token']);
    }

    public function getRouterById(int $id, int $companyId): mixed
    {
        return ConectionRouter::where('id', $id)->where('company_id', $companyId)->first();
    }

    public function saveRouterConfig(int $companyId, array $data): mixed
    {
        $router = ConectionRouter::where('company_id', $companyId)->first();

        $payload = [
            'name'       => $data['name'] ?? null,
            'host'       => $data['host'],
            'user'       => $data['user'],
            'pass'       => $data['pass'],
            'port'       => $data['port'] ?? 8728,
            'company_id' => $companyId,
            'token'      => md5($companyId . $data['host'] . $data['user']),
        ];

        if ($router) {
            $router->update($payload);
            return $router->fresh();
        }

        return ConectionRouter::create($payload);
    }

    public function createRouter(int $companyId, array $data): mixed
    {
        return ConectionRouter::create([
            'name'       => $data['name'] ?? null,
            'host'       => $data['host'],
            'user'       => $data['user'],
            'pass'       => $data['pass'],
            'port'       => $data['port'] ?? 8728,
            'company_id' => $companyId,
            'token'      => md5($companyId . $data['host'] . $data['user'] . microtime()),
        ]);
    }

    public function updateRouter(int $id, int $companyId, array $data): mixed
    {
        $router = ConectionRouter::where('id', $id)->where('company_id', $companyId)->first();
        if (!$router) return null;

        $payload = array_filter([
            'name' => $data['name'] ?? $router->name,
            'host' => $data['host'] ?? $router->host,
            'user' => $data['user'] ?? $router->user,
            'port' => $data['port'] ?? $router->port,
        ], fn($v) => $v !== null);

        if (!empty($data['pass'])) {
            $payload['pass'] = $data['pass'];
        }

        $payload['token'] = md5($companyId . ($payload['host'] ?? $router->host) . ($payload['user'] ?? $router->user));

        $router->update($payload);
        return $router->fresh();
    }

    public function deleteRouter(int $id, int $companyId): bool
    {
        return (bool) ConectionRouter::where('id', $id)->where('company_id', $companyId)->delete();
    }
}
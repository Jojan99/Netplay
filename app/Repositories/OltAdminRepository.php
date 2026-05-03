<?php

namespace App\Repositories;

use App\Models\OltAdmin;
use App\Repositories\Interfaces\OltAdminRepositoryInterface;

class OltAdminRepository implements OltAdminRepositoryInterface
{
    public function getAllByCompany(): array
    {
        return OltAdmin::where('company_id', getSessionCompanyId())
            ->get()
            ->toArray();
    }

    public function findById(int $id): ?array
    {
        $olt = OltAdmin::where('id', $id)
            ->where('company_id', getSessionCompanyId())
            ->first();

        return $olt?->toArray();
    }

    public function create(array $data): array
    {
        $olt = OltAdmin::create([
            'company_id'          => getSessionCompanyId(),
            'name'                => $data['name'],
            'brand'               => $data['brand'] ?? 'huawei',
            'host'                => $data['host'],
            'port'                => $data['port'] ?? 22,
            'username'            => $data['username'],
            'password'            => $data['password'],
            'access_mode'         => $data['access_mode'] ?? 'jump',
            'jump_host'           => $data['jump_host'] ?? null,
            'jump_port'           => $data['jump_port'] ?? 22,
            'jump_user'           => $data['jump_user'] ?? null,
            'jump_pass'           => $data['jump_pass'] ?? null,
            'ont_lineprofile_id'  => $data['ont_lineprofile_id'] ?? 10,
            'ont_srvprofile_id'   => $data['ont_srvprofile_id']  ?? 10,
            'default_vlan'        => $data['default_vlan'] ?? 200,
        ]);

        return $olt->toArray();
    }

    public function update(int $id, array $data): bool
    {
        $allowed = [
            'name', 'brand', 'host', 'port', 'username', 'password',
            'access_mode', 'jump_host', 'jump_port', 'jump_user', 'jump_pass',
            'ont_lineprofile_id', 'ont_srvprofile_id', 'default_vlan',
        ];

        return (bool) OltAdmin::where('id', $id)
            ->where('company_id', getSessionCompanyId())
            ->update(array_intersect_key($data, array_flip($allowed)));
    }

    public function delete(int $id): bool
    {
        return (bool) OltAdmin::where('id', $id)
            ->where('company_id', getSessionCompanyId())
            ->delete();
    }
}

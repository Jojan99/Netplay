<?php

namespace App\Repositories;

use App\Models\ClientContract;
use App\Models\Contract;
use App\Repositories\Interfaces\ContractRepositoryInterface;

class ContractRepository implements ContractRepositoryInterface
{
    public function getAll(): mixed
    {
        return Contract::where('company_id', getSessionCompanyId())
            ->orderByDesc('created_at')
            ->get();
    }

    public function getById(int $id): mixed
    {
        return Contract::where('id', $id)
            ->where('company_id', getSessionCompanyId())
            ->firstOrFail();
    }

    public function create(array $data): mixed
    {
        return Contract::create([
            'company_id'          => getSessionCompanyId(),
            'title'               => $data['title'],
            'content'             => $data['content'],
            'active'              => $data['active'] ?? true,
            'installation_value'  => $data['installation_value'] ?? null,
        ]);
    }

    public function update(int $id, array $data): mixed
    {
        $contract = $this->getById($id);
        $contract->update($data);
        return $contract->fresh();
    }

    public function delete(int $id): mixed
    {
        $contract = $this->getById($id);
        return $contract->delete();
    }

    public function assignToClient(int $contractId, int $userId, bool $requireDocuments = false): mixed
    {
        return ClientContract::create([
            'company_id'         => getSessionCompanyId(),
            'contract_id'        => $contractId,
            'user_id'            => $userId,
            'status'             => 'pending',
            'token'              => bin2hex(random_bytes(32)),
            'require_documents'  => $requireDocuments,
        ]);
    }

    public function getByToken(string $token): mixed
    {
        return ClientContract::with(['contract', 'user'])
            ->where('token', $token)
            ->firstOrFail();
    }

    public function getClientContract(int $clientContractId): mixed
    {
        return ClientContract::with(['contract', 'user'])
            ->where('id', $clientContractId)
            ->where('company_id', getSessionCompanyId())
            ->firstOrFail();
    }

    public function getClientContractsByUser(int $userId): mixed
    {
        return ClientContract::with('contract')
            ->where('user_id', $userId)
            ->where('company_id', getSessionCompanyId())
            ->orderByDesc('created_at')
            ->get();
    }

    public function sign(int $clientContractId, string $signature): mixed
    {
        $cc = ClientContract::where('id', $clientContractId)->firstOrFail();
        $cc->update([
            'signature' => $signature,
            'status'    => 'signed',
            'signed_at' => now(),
        ]);
        return $cc->fresh();
    }
}

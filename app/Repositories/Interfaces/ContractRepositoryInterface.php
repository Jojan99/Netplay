<?php

namespace App\Repositories\Interfaces;

interface ContractRepositoryInterface
{
    public function getAll(): mixed;
    public function getById(int $id): mixed;
    public function create(array $data): mixed;
    public function update(int $id, array $data): mixed;
    public function delete(int $id): mixed;

    public function assignToClient(int $contractId, int $userId, bool $requireDocuments = false): mixed;
    public function getClientContract(int $clientContractId): mixed;
    public function getClientContractsByUser(int $userId): mixed;
    public function sign(int $clientContractId, string $signature): mixed;
    public function getByToken(string $token): mixed;
}

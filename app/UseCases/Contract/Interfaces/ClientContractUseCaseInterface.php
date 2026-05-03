<?php

namespace App\UseCases\Contract\Interfaces;

use Illuminate\Http\Request;

interface ClientContractUseCaseInterface
{
    public function assign(Request $request): mixed;
    public function getByUser(int $userId): mixed;
    public function getById(int $clientContractId): mixed;
    public function sign(int $clientContractId, Request $request): mixed;
    public function generatePdf(int $clientContractId): mixed;
}

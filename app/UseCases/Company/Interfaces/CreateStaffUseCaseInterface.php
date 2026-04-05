<?php

namespace App\UseCases\Company\Interfaces;

use App\Http\Requests\Company\CreateStaffRequest;

interface CreateStaffUseCaseInterface
{
    public function create(CreateStaffRequest $request): mixed;
}

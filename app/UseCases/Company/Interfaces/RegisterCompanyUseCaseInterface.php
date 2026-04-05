<?php

namespace App\UseCases\Company\Interfaces;

use App\Http\Requests\Company\RegisterCompanyRequest;

interface RegisterCompanyUseCaseInterface
{
    public function register(RegisterCompanyRequest $data): mixed;
}

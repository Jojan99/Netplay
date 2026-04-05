<?php

namespace App\UseCases\Company\Interfaces;

use App\Http\Requests\Company\UpdateBillingConfigRequest;

interface UpdateBillingConfigUseCaseInterface
{
    public function update(UpdateBillingConfigRequest $request): mixed;
}

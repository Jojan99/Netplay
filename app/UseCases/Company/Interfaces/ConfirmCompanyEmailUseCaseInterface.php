<?php

namespace App\UseCases\Company\Interfaces;

interface ConfirmCompanyEmailUseCaseInterface
{
    public function confirm(string $token): mixed;
}

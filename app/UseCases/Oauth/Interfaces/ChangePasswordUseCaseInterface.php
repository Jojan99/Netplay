<?php

namespace App\UseCases\Oauth\Interfaces;

use App\Http\Requests\Auth\ChangePasswordRequest;

interface ChangePasswordUseCaseInterface
{
    public function change(ChangePasswordRequest $data): mixed;
}

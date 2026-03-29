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
}
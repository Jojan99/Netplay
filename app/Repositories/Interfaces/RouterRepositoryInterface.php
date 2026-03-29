<?php

namespace App\Repositories\Interfaces;
interface RouterRepositoryInterface 

{
    public function getCredentialRouter($token): mixed;
}
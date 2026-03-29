<?php

namespace App\Constants;

class ProfileConstants
{
    const USER     = 1;
    const ADMIN    = 2;
    const TECNICO  = 3;
    const CONTADOR = 4;

    const ROLE_MAP = [
        'user'     => self::USER,
        'admin'    => self::ADMIN,
        'tecnico'  => self::TECNICO,
        'contador' => self::CONTADOR,
    ];
}

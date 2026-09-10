<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\TrimStrings as Middleware;

class TrimStrings extends Middleware
{
    /**
     * The names of the attributes that should not be trimmed.
     *
     * @var array<int, string>
     */
    protected $except = [
        'current_password',
        'password',
        'password_confirmation',
        // El SDP de una llamada termina en salto de línea y cada línea se
        // separa con \r\n. Al recortarlo se pierde el terminador de la última
        // línea y el navegador del otro lado rechaza la sesión entera con
        // "Invalid SDP line": la llamada sonaba pero el audio no abría nunca.
        'sdp',
        'payload.sdp',
        'payload.candidate',
    ];
}

<?php

namespace App\Rules;

use App\Services\Red\AprovisionamientoDeOnt;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Un usuario o una clave que terminan dentro de un equipo.
 *
 * El PPPoE y la cuenta de administración de la ONT viajan por TR-069 y, en
 * varias marcas, por una línea de consola. Una eñe, una tilde, una comilla o
 * un espacio los parten en algún punto del camino y el equipo contesta
 * «Invalid arguments», que no le dice nada a nadie. No se mide el largo: aquí
 * lo que importa son los signos.
 */
class ClaveDeEquipo implements ValidationRule
{
    public function __construct(private string $que = 'Este dato') {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '' || !is_string($value)) {
            return;
        }

        if ($problema = AprovisionamientoDeOnt::problemaDeUnaClaveDeEquipo($value, $this->que)) {
            $fail($problema);
        }
    }
}

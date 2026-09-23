<?php

namespace App\Rules;

use App\Services\Red\AprovisionamientoDeOnt;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** El nombre de la red, con la misma regla en todos lados. */
class NombreWifi implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (!is_string($value)) {
            $fail('El nombre de la red no es un texto.');

            return;
        }

        if ($problema = AprovisionamientoDeOnt::problemaDelNombreWifi($value)) {
            $fail($problema);
        }
    }
}

<?php

namespace App\Rules;

use App\Services\Red\AprovisionamientoDeOnt;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * La clave del WiFi, con una sola regla para toda la plataforma.
 *
 * Se usa en el aprovisionamiento, en la ficha del cliente y en el portal: si
 * la regla viviera en cada formulario, tarde o temprano uno se quedaría atrás
 * y volvería a colarse una clave que el equipo rechaza sin explicar por qué.
 */
class ClaveWifi implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (!is_string($value)) {
            $fail('La clave del WiFi no es un texto.');

            return;
        }

        if ($problema = AprovisionamientoDeOnt::problemaDeLaClaveWifi($value)) {
            $fail($problema);
        }
    }
}

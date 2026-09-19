<?php

namespace App\Services\Cobranza;

/**
 * La IA no pudo responder por un motivo pasajero (límite del plan gratis,
 * demanda alta, caída momentánea). No es culpa de la conversación: se vuelve
 * a intentar en la próxima revisión, sin pasar el caso a una persona.
 */
class IaOcupada extends \RuntimeException {}

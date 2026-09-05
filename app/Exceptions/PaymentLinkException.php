<?php

namespace App\Exceptions;

/**
 * Error de un link de pago cuyo mensaje SÍ puede mostrarse al cliente final.
 *
 * Existe como clase propia a propósito: usar RuntimeException aquí haría que
 * un QueryException (que hereda de PDOException → RuntimeException) se colara
 * como "mensaje amigable" y expusiera SQL en una página pública.
 */
class PaymentLinkException extends \Exception
{
}

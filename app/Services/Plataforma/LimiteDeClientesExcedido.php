<?php

namespace App\Services\Plataforma;

/** La empresa ya llenó los clientes que incluye su plan. El mensaje es para quien lo intentó. */
class LimiteDeClientesExcedido extends \RuntimeException
{
    /** @param array<string,mixed> $estado */
    public function __construct(public readonly array $estado, string $mensaje)
    {
        parent::__construct($mensaje);
    }
}

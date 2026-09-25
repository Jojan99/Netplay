<?php

namespace App\Services\WaBot;

/**
 * Lo que un bloque le dice al motor después de correr: seguir a otro bloque,
 * quedarse esperando al cliente, terminar, o pasar a un agente.
 *
 * @internal Sólo lo usa MotorDeFlujos.
 */
class Resultado
{
    private function __construct(
        public readonly ?string $siguiente,
        /** @var array<string,mixed> */
        public readonly array $datos,
        public readonly bool $espera = false,
        public readonly bool $termina = false,
        public readonly ?string $transferir = null,
    ) {
    }

    /** @param array<string,mixed> $datos */
    public static function sigue(?string $siguiente, array $datos): self
    {
        return new self($siguiente, $datos);
    }

    /** @param array<string,mixed> $datos */
    public static function espera(array $datos): self
    {
        return new self(null, $datos, espera: true);
    }

    /** @param array<string,mixed> $datos */
    public static function termina(array $datos): self
    {
        return new self(null, $datos, termina: true);
    }

    /** @param array<string,mixed> $datos */
    public static function transfiere(array $datos, string $area): self
    {
        return new self(null, $datos, transferir: $area);
    }
}

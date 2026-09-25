<?php

namespace App\Services\WaBot;

/**
 * Dónde quedó el flujo cuando el motor dejó de correr.
 *
 * Es lo que se guarda en la sesión: o está esperando que el cliente conteste en
 * un bloque, o terminó, o hay que pasarle la conversación a una persona.
 */
class Parada
{
    private function __construct(
        /** El bloque que está esperando respuesta, o null si no espera nada. */
        public readonly ?string $bloque,
        /** @var array<string,mixed> Las variables como quedaron. */
        public readonly array $datos,
        public readonly bool $termino,
        /** El área a la que hay que pasar la conversación, si corresponde. */
        public readonly ?string $transferirA = null,
    ) {
    }

    /** @param array<string,mixed> $datos */
    public static function esperando(string $bloque, array $datos): self
    {
        return new self($bloque, $datos, false);
    }

    /** @param array<string,mixed> $datos */
    public static function terminada(array $datos): self
    {
        return new self(null, $datos, true);
    }

    /** @param array<string,mixed> $datos */
    public static function transferida(array $datos, string $area): self
    {
        return new self(null, $datos, true, $area);
    }
}

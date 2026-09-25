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
        /**
         * En qué flujo quedó. Puede no ser el que arrancó: un bloque «Ir a otro
         * flujo» cambia de flujo a mitad de camino, y la sesión tiene que
         * guardar el nuevo o la respuesta del cliente se buscaría en el viejo.
         */
        public readonly ?string $flujo,
        /** @var array<string,mixed> Las variables como quedaron. */
        public readonly array $datos,
        public readonly bool $termino,
        /** El área a la que hay que pasar la conversación, si corresponde. */
        public readonly ?string $transferirA = null,
    ) {
    }

    /** @param array<string,mixed> $datos */
    public static function esperando(string $bloque, array $datos, ?string $flujo = null): self
    {
        return new self($bloque, $flujo, $datos, false);
    }

    /** @param array<string,mixed> $datos */
    public static function terminada(array $datos, ?string $flujo = null): self
    {
        return new self(null, $flujo, $datos, true);
    }

    /** @param array<string,mixed> $datos */
    public static function transferida(array $datos, string $area, ?string $flujo = null): self
    {
        return new self(null, $flujo, $datos, true, $area);
    }
}

<?php

namespace App\Services\Cobranza;

/**
 * La IA del asistente de cobranza, sea cual sea el proveedor.
 *
 * Todo el módulo habla en un solo formato (el de mensajes con bloques de
 * texto y herramientas: tool_use / tool_result) y guarda el historial así.
 * Cada proveedor traduce a su API:
 *   - anthropic: Claude (de pago).
 *   - compatible: cualquier API con el formato de OpenAI —Google Gemini (con
 *     plan gratis), Groq (gratis con límites), OpenRouter, OpenAI—; se elige
 *     con la URL, la clave y el modelo del .env.
 *
 * COBRANZA_IA=compatible|anthropic elige cuál. Sin clave del elegido, el
 * asistente queda apagado: la plataforma detecta y avisa igual.
 */
abstract class Ia
{
    /**
     * @param  list<array<string,mixed>>  $mensajes
     * @param  list<array<string,mixed>>  $herramientas  name, description, input_schema
     * @return array{content: list<array<string,mixed>>, stop_reason: ?string}
     */
    abstract public function mensaje(string $sistema, array $mensajes, array $herramientas = [], int $maxTokens = 700): array;

    public static function proveedor(): string
    {
        return (string) config('services.cobranza_ia.proveedor', 'compatible') === 'anthropic' ? 'anthropic' : 'compatible';
    }

    public static function disponible(): bool
    {
        return self::proveedor() === 'anthropic'
            ? (string) config('services.anthropic.key') !== ''
            : (string) config('services.cobranza_ia.key') !== '';
    }

    /**
     * Límite o demanda alta (429, 5xx, sin conexión): se espera y se reintenta
     * hasta 3 veces. Si sigue, IaOcupada: la revisión lo vuelve a probar luego.
     */
    protected static function conReintentos(callable $pedir): \Illuminate\Http\Client\Response
    {
        $esperas = (array) config('services.cobranza_ia.esperas', [2, 5]);

        for ($intento = 0; ; $intento++) {
            try {
                $r = $pedir();
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                $r = null;
            }

            $pasajero = !$r || $r->status() === 429 || $r->status() >= 500;

            if (!$pasajero) {
                return $r;
            }

            if ($intento >= count($esperas)) {
                throw new IaOcupada($r
                    ? 'La IA está ocupada o llegó a su límite (' . $r->status() . '). Se reintenta en la próxima revisión.'
                    : 'No se pudo conectar con la IA. Se reintenta en la próxima revisión.');
            }

            sleep((int) $esperas[$intento]);
        }
    }

    /** La del .env. Las pruebas reemplazan Ia::class en el contenedor. */
    public static function crear(): self
    {
        return self::proveedor() === 'anthropic' ? new Claude() : new IaCompatible();
    }
}

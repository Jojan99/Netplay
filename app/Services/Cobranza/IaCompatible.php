<?php

namespace App\Services\Cobranza;

use Illuminate\Support\Facades\Http;

/**
 * Cualquier IA con la API en formato OpenAI (chat/completions con "tools"):
 *   - Google Gemini: https://generativelanguage.googleapis.com/v1beta/openai/chat/completions
 *     (clave de Google AI Studio; los modelos Flash tienen plan gratis con límites).
 *   - Groq: https://api.groq.com/openai/v1/chat/completions (gratis con límites).
 *   - OpenRouter, OpenAI, etc.
 *
 * Traduce del formato del módulo (bloques text / tool_use / tool_result) al de
 * OpenAI y la respuesta de vuelta, así el historial guardado sirve con
 * cualquier proveedor.
 */
class IaCompatible extends Ia
{
    /**
     * COBRANZA_IA_MODELO puede ser una lista separada por comas: si un modelo
     * llegó a su cupo del día (429 de cuota), se pasa al siguiente. En el plan
     * gratis de Gemini cada modelo tiene su propio cupo diario, así se suman.
     */
    public function mensaje(string $sistema, array $mensajes, array $herramientas = [], int $maxTokens = 700): array
    {
        $modelos = array_values(array_filter(array_map('trim', explode(',', (string) config('services.cobranza_ia.modelo')))));
        $ultimo = null;

        // Dos pasadas por la cadena: si todos estaban en su límite por minuto,
        // se espera un poco y se prueba otra vez (el cupo por minuto se libera
        // rápido; el del día no).
        for ($pasada = 0; $pasada < 2; $pasada++) {
            $porMinuto = false;
            $agotados = (array) \Illuminate\Support\Facades\Cache::get('cobranza:ia:agotados', []);

            foreach ($modelos as $modelo) {
                // Un modelo que ya dio "cuota agotada" hoy no se vuelve a probar.
                if (isset($agotados[$modelo]) && $agotados[$modelo] === now('America/Los_Angeles')->toDateString()) {
                    continue;
                }

                try {
                    return $this->conModelo($modelo, $sistema, $mensajes, $herramientas, $maxTokens);
                } catch (IaOcupada $e) {
                    $ultimo = $e;

                    if (str_contains($e->getMessage(), 'cuota')) {
                        // Los cupos del plan gratis se reinician a medianoche del Pacífico.
                        $agotados[$modelo] = now('America/Los_Angeles')->toDateString();
                        \Illuminate\Support\Facades\Cache::put('cobranza:ia:agotados', $agotados, now()->addDay());
                    } elseif (str_contains($e->getMessage(), 'minuto')) {
                        $porMinuto = true;
                    }
                }
            }

            if (!$porMinuto) {
                break;
            }

            sleep((int) config('services.cobranza_ia.espera_minuto', 8));
        }

        throw $ultimo ?? new IaOcupada('Todos los modelos de IA llegaron a su cupo de hoy. Se reintenta en la próxima revisión.');
    }

    private function conModelo(string $modelo, string $sistema, array $mensajes, array $herramientas, int $maxTokens): array
    {
        $clave = (string) config('services.cobranza_ia.key');

        if ($clave === '') {
            throw new \RuntimeException('Falta COBRANZA_IA_KEY en el servidor.');
        }

        $url = (string) config('services.cobranza_ia.url');

        $cuerpo = [
            'model'      => $modelo,
            'messages'   => array_merge([['role' => 'system', 'content' => $sistema]], $this->mensajes($mensajes)),
            // Los modelos que "piensan" (Gemini 3) gastan de este mismo máximo
            // antes de escribir: con poco margen, la respuesta salía cortada.
            'max_tokens' => max($maxTokens, 2500),
            'temperature' => 0.4,
        ];

        // En Gemini, razonamiento corto: responde más rápido y gasta menos.
        if (str_contains($url, 'generativelanguage.googleapis.com') && str_starts_with($modelo, 'gemini')) {
            $cuerpo['reasoning_effort'] = 'low';
        }

        if ($herramientas) {
            $cuerpo['tools'] = array_map(fn ($h) => ['type' => 'function', 'function' => [
                'name'        => $h['name'],
                'description' => $h['description'] ?? '',
                'parameters'  => $this->esquema($h['input_schema'] ?? []),
            ]], $herramientas);
        }

        $pedir = fn () => Http::timeout(60)->withToken($clave)->acceptJson()->post($url, $cuerpo);
        $primera = $pedir();

        // Cuota del día agotada: esperar no sirve, se pasa al siguiente modelo.
        if ($primera->status() === 429 && str_contains((string) $primera->body(), 'PerDay')) {
            throw new IaOcupada("El modelo {$modelo} llegó a su cuota del día.");
        }

        // Límite por minuto: el siguiente modelo tiene su propio cupo.
        if ($primera->status() === 429) {
            throw new IaOcupada("El modelo {$modelo} llegó a su límite por minuto.");
        }

        // Un historial con llamadas de otro modelo puede no traer la firma que
        // Gemini 3 exige: se reenvía con la firma comodín que Google documenta.
        if ($primera->status() === 400 && str_contains((string) $primera->body(), 'thought_signature')) {
            $cuerpo['messages'] = $this->conFirmaComodin($cuerpo['messages']);
            $primera = $pedir();
        }

        $r = $primera->status() === 429 || $primera->status() >= 500 ? self::conReintentos($pedir) : $primera;

        if (!$r->successful()) {
            $error = $r->json('error.message') ?? $r->json('0.error.message') ?? $r->body();
            throw new \RuntimeException('La IA respondió ' . $r->status() . ': ' . mb_substr((string) $error, 0, 200));
        }

        $msg = (array) ($r->json('choices.0.message') ?? []);
        $bloques = [];

        if (is_string($msg['content'] ?? null) && trim($msg['content']) !== '') {
            $bloques[] = ['type' => 'text', 'text' => trim($msg['content'])];
        }

        foreach ((array) ($msg['tool_calls'] ?? []) as $i => $t) {
            $args = $t['function']['arguments'] ?? '{}';
            $bloque = [
                'type'  => 'tool_use',
                'id'    => (string) (($t['id'] ?? '') !== '' ? $t['id'] : 'call_' . bin2hex(random_bytes(6)) . "_{$i}"),
                'name'  => (string) ($t['function']['name'] ?? ''),
                'input' => is_array($args) ? $args : ((array) json_decode((string) $args, true) ?: []),
            ];

            // Gemini 3 manda una firma de su razonamiento con cada llamada y
            // exige recibirla de vuelta en el turno siguiente (si no: error 400).
            if (!empty($t['extra_content'])) {
                $bloque['extra_content'] = $t['extra_content'];
            }

            $bloques[] = $bloque;
        }

        $conHerramientas = collect($bloques)->contains(fn ($b) => $b['type'] === 'tool_use');

        return ['content' => $bloques, 'stop_reason' => $conHerramientas ? 'tool_use' : 'end_turn'];
    }

    private function conFirmaComodin(array $mensajes): array
    {
        return array_map(function ($m) {
            foreach ($m['tool_calls'] ?? [] as $i => $t) {
                $m['tool_calls'][$i]['extra_content'] = ['google' => ['thought_signature' => 'skip_thought_signature_validator']];
            }

            return $m;
        }, $mensajes);
    }

    /** Del formato del módulo al de OpenAI. */
    private function mensajes(array $mensajes): array
    {
        $salida = [];

        foreach ($mensajes as $m) {
            $contenido = $m['content'];

            if (is_string($contenido)) {
                $salida[] = ['role' => $m['role'], 'content' => $contenido];
                continue;
            }

            if ($m['role'] === 'assistant') {
                $texto = implode("\n", array_map(fn ($b) => $b['text'], array_filter($contenido, fn ($b) => ($b['type'] ?? '') === 'text')));
                $llamadas = array_values(array_map(fn ($b) => array_filter([
                    'id' => $b['id'], 'type' => 'function',
                    'function' => ['name' => $b['name'], 'arguments' => json_encode((object) ($b['input'] ?? []), JSON_UNESCAPED_UNICODE)],
                    'extra_content' => $b['extra_content'] ?? null,
                ], fn ($v) => $v !== null), array_filter($contenido, fn ($b) => ($b['type'] ?? '') === 'tool_use')));

                $salida[] = array_filter(['role' => 'assistant', 'content' => $texto !== '' ? $texto : null, 'tool_calls' => $llamadas ?: null], fn ($v) => $v !== null)
                    + ['content' => ''];
                continue;
            }

            // Resultados de herramientas: un mensaje "tool" por cada uno.
            foreach ($contenido as $b) {
                if (($b['type'] ?? '') === 'tool_result') {
                    $salida[] = ['role' => 'tool', 'tool_call_id' => $b['tool_use_id'], 'content' => (string) $b['content']];
                } elseif (($b['type'] ?? '') === 'text') {
                    $salida[] = ['role' => 'user', 'content' => (string) $b['text']];
                }
            }
        }

        return $salida;
    }

    /** Un esquema vacío tiene que ser un objeto, no una lista. */
    private function esquema(array $s): array
    {
        if (!isset($s['properties']) || $s['properties'] === [] ) {
            $s['properties'] = (object) [];
        }

        $s['type'] ??= 'object';

        return $s;
    }
}

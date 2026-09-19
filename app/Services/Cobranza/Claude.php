<?php

namespace App\Services\Cobranza;

use Illuminate\Support\Facades\Http;

/**
 * Cliente mínimo de la API de mensajes de Anthropic (Claude), con
 * herramientas. Sólo habla con el servidor: la clave vive en el .env y nunca
 * llega al panel.
 */
class Claude extends Ia
{
    /**
     * @param  list<array<string,mixed>>  $mensajes   formato de la API (role/content)
     * @param  list<array<string,mixed>>  $herramientas
     * @return array{content: list<array<string,mixed>>, stop_reason: ?string}
     */
    public function mensaje(string $sistema, array $mensajes, array $herramientas = [], int $maxTokens = 700): array
    {
        if ((string) config('services.anthropic.key') === '') {
            throw new \RuntimeException('Falta ANTHROPIC_API_KEY en el servidor.');
        }

        $cuerpo = [
            'model'      => (string) config('services.anthropic.modelo', 'claude-sonnet-5'),
            'max_tokens' => $maxTokens,
            'system'     => $sistema,
            'messages'   => self::limpiar($mensajes),
        ];

        if ($herramientas) {
            $cuerpo['tools'] = $herramientas;
        }

        $r = self::conReintentos(fn () => Http::timeout(60)
            ->withHeaders([
                'x-api-key'         => (string) config('services.anthropic.key'),
                'anthropic-version' => '2023-06-01',
            ])
            ->acceptJson()
            ->post((string) config('services.anthropic.url'), $cuerpo));

        if (!$r->successful()) {
            // El cuerpo de error de la API no trae la clave: se puede registrar.
            throw new \RuntimeException('La IA respondió ' . $r->status() . ': ' . mb_substr((string) ($r->json('error.message') ?? $r->body()), 0, 200));
        }

        return [
            'content'     => (array) ($r->json('content') ?? []),
            'stop_reason' => $r->json('stop_reason'),
        ];
    }

    /** El historial puede traer datos de otro proveedor (la firma de Gemini): Claude los rechaza. */
    private static function limpiar(array $mensajes): array
    {
        return array_map(function ($m) {
            if (is_array($m['content'])) {
                $m['content'] = array_map(fn ($b) => array_diff_key($b, ['extra_content' => 1]), $m['content']);
            }

            return $m;
        }, $mensajes);
    }
}

<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Rate limiter para envío de WhatsApp masivo.
 * Simula comportamiento humano con delays aleatorios, lotes y pausas.
 */
class WhatsAppRateLimiterService
{
    /**
     * Configuración por defecto para envío masivo de facturas.
     * Ajustado para ser indetectable por Meta.
     */
    private array $config = [
        // Delay entre mensajes individuales (segundos)
        'min_delay' => 3,
        'max_delay' => 8,

        // Tamaño de lotes (cuántos mensajes seguidos antes de una pausa larga)
        'batch_size' => 10,

        // Pausa entre lotes (segundos)
        'batch_min_pause' => 30,
        'batch_max_pause' => 90,

        // Máximo de mensajes por minuto (soft limit)
        'max_per_minute' => 15,

        // Máximo de mensajes por hora (soft limit)
        'max_per_hour' => 300,

        // Horario permitido para envío masivo (evita 3 AM)
        'allowed_hours_start' => 7,  // 7:00 AM
        'allowed_hours_end' => 21, // 9:00 PM
    ];

    private int $sentCount = 0;
    private float $batchStartTime;
    private int $batchCount = 0;

    public function __construct(array $overrides = [])
    {
        $this->config = array_merge($this->config, $overrides);
        $this->batchStartTime = microtime(true);
    }

    /**
     * Ejecuta un callback con rate limiting aplicado.
     * Usa este método para enviar cada mensaje dentro de un loop.
     *
     * @param callable $callback Función que envía el mensaje
     * @param array $context Contexto para logs (opcional)
     * @return mixed Resultado del callback
     */
    public function sendWithRateLimit(callable $callback, array $context = []): mixed
    {
        // 1. Verificar horario permitido
        if (!$this->isWithinAllowedHours()) {
            $wait = $this->secondsUntilAllowed();
            Log::info('[WA_RATE_LIMIT] Fuera de horario permitido. Esperando ' . $wait . ' segundos.', $context);
            sleep($wait);
        }

        // 2. Si completamos un lote, hacer pausa larga
        if ($this->batchCount >= $this->config['batch_size']) {
            $this->pauseBetweenBatches();
            $this->batchCount = 0;
            $this->batchStartTime = microtime(true);
        }

        // 3. Delay aleatorio entre mensajes
        $delay = $this->randomDelay();
        Log::debug('[WA_RATE_LIMIT] Delay de ' . $delay . 's antes de enviar.', $context);
        sleep($delay);

        // 4. Ejecutar callback
        $result = $callback();

        $this->sentCount++;
        $this->batchCount++;

        Log::debug('[WA_RATE_LIMIT] Mensaje enviado. Total: ' . $this->sentCount, $context);

        return $result;
    }

    /**
     * Retorna el delay aleatorio entre mensajes (en segundos).
     * Distribución sesgada hacia delays más largos para parecer humano.
     */
    public function randomDelay(): int
    {
        $min = $this->config['min_delay'];
        $max = $this->config['max_delay'];

        // Usar distribución sesgada: más probable delays intermedios-altos
        $random = mt_rand() / mt_getrandmax();
        $skewed = pow($random, 0.7); // Sesgo hacia valores más altos

        return (int) round($min + ($skewed * ($max - $min)));
    }

    /**
     * Pausa entre lotes con duración aleatoria.
     */
    public function pauseBetweenBatches(): void
    {
        $pause = mt_rand($this->config['batch_min_pause'], $this->config['batch_max_pause']);
        Log::info('[WA_RATE_LIMIT] Pausa entre lotes de ' . $pause . ' segundos.');
        sleep($pause);
    }

    /**
     * Verifica si estamos dentro del horario permitido.
     */
    public function isWithinAllowedHours(): bool
    {
        $hour = (int) now()->format('H');
        return $hour >= $this->config['allowed_hours_start']
            && $hour < $this->config['allowed_hours_end'];
    }

    /**
     * Calcula segundos hasta el próximo horario permitido.
     */
    public function secondsUntilAllowed(): int
    {
        $now = now();
        $hour = (int) $now->format('H');

        if ($hour < $this->config['allowed_hours_start']) {
            // Esperar hasta las 7 AM de hoy
            return $now->diffInSeconds(
                $now->copy()->setTime($this->config['allowed_hours_start'], 0, 0)
            );
        }

        // Esperar hasta las 7 AM del siguiente día
        return $now->diffInSeconds(
            $now->copy()->addDay()->setTime($this->config['allowed_hours_start'], 0, 0)
        );
    }

    /**
     * Obtiene estadísticas del proceso actual.
     */
    public function getStats(): array
    {
        return [
            'sent_count' => $this->sentCount,
            'batch_count' => $this->batchCount,
            'elapsed_seconds' => round(microtime(true) - $this->batchStartTime, 2),
        ];
    }

    /**
     * Calcula el tiempo estimado total para enviar N mensajes.
     */
    public function estimateTime(int $totalMessages): array
    {
        $avgDelay = ($this->config['min_delay'] + $this->config['max_delay']) / 2;
        $batches = ceil($totalMessages / $this->config['batch_size']);
        $avgBatchPause = ($this->config['batch_min_pause'] + $this->config['batch_max_pause']) / 2;

        $messageTime = $totalMessages * $avgDelay;
        $pauseTime = max(0, ($batches - 1)) * $avgBatchPause;
        $totalSeconds = $messageTime + $pauseTime;

        return [
            'total_messages' => $totalMessages,
            'estimated_batches' => (int) $batches,
            'estimated_seconds' => (int) $totalSeconds,
            'estimated_minutes' => round($totalSeconds / 60, 1),
            'estimated_hours' => round($totalSeconds / 3600, 2),
        ];
    }
}

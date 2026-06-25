<?php

namespace App\Helpers;

class RouterHostParser
{
    /**
     * Separa host y puerto si el host viene en formato "host:puerto".
     * Soporta IPv6 entre corchetes [..]:port.
     * Si no hay puerto en el host, retorna el puerto proporcionado o el default.
     *
     * @return array{host: string, port: int}
     */
    public static function parse(string $host, ?int $port = null, int $defaultPort = 8728): array
    {
        $parsedPort = $port;

        if (str_contains($host, ':')) {
            // IPv6 entre corchetes, ej: [::1]:8728
            if (str_starts_with($host, '[')) {
                $pos = strrpos($host, ']');
                if ($pos !== false && isset($host[$pos + 1]) && $host[$pos + 1] === ':') {
                    $parsedPort = (int) substr($host, $pos + 2);
                    $host = substr($host, 1, $pos - 1);
                }
            } else {
                $lastColon = strrpos($host, ':');
                $parsedPort = (int) substr($host, $lastColon + 1);
                $host = substr($host, 0, $lastColon);
            }
        }

        return [
            'host' => $host,
            'port' => $parsedPort ?? $port ?? $defaultPort,
        ];
    }
}

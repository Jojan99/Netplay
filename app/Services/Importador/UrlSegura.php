<?php

namespace App\Services\Importador;

/**
 * La URL de la API que pega la empresa, revisada antes de que el servidor la
 * consulte: sólo http(s) y sólo hacia direcciones públicas. Sin esto el
 * formulario serviría para que el servidor le pegue a su propia red interna.
 */
class UrlSegura
{
    public static function validar(string $url): string
    {
        $url = trim($url);

        if ($url !== '' && !preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }

        $partes = parse_url($url);
        $esquema = strtolower($partes['scheme'] ?? '');
        $host = strtolower($partes['host'] ?? '');

        if (!in_array($esquema, ['http', 'https'], true) || $host === '' || isset($partes['user']) || isset($partes['pass'])) {
            throw new \RuntimeException('La dirección de la API no es válida.');
        }

        if (isset($partes['port']) && !in_array((int) $partes['port'], [80, 443, 8080, 8443, 8000, 8081, 8888], true)) {
            throw new \RuntimeException('El puerto de la dirección de la API no está permitido.');
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);

        if (!$ips) {
            throw new \RuntimeException("No se encontró el servidor {$host}. Revisá la dirección.");
        }

        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new \RuntimeException('La dirección de la API tiene que ser pública (no de una red interna).');
            }
        }

        $base = $esquema . '://' . $host . (isset($partes['port']) ? ':' . $partes['port'] : '');
        $ruta = rtrim($partes['path'] ?? '', '/');

        return $base . $ruta;
    }
}

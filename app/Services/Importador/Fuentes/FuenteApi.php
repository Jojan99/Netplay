<?php

namespace App\Services\Importador\Fuentes;

use Illuminate\Support\Facades\Http;

/**
 * Lo común a las API de origen: el pedido HTTP (reemplazable en las pruebas)
 * y los errores contados en castellano.
 */
abstract class FuenteApi
{
    /** @var callable(string,string,array,?array):array{status:int,json:mixed} */
    protected $transporte;

    /**
     * @param callable|null $transporte  fn(método, url, headers, cuerpo) => ['status' => int, 'json' => mixed]
     */
    public function __construct(protected string $url, protected string $token, ?callable $transporte = null)
    {
        $this->transporte = $transporte ?? [self::class, 'http'];
    }

    /** Comprueba que la URL y el token sirven. Devuelve un texto para mostrar. */
    abstract public function probar(): string;

    /**
     * Todos los clientes, ya normalizados.
     *
     * @param  callable(string):void  $avance     texto para la pantalla
     * @param  callable():bool        $debeParar
     * @return array<int, array<string,mixed>>
     */
    abstract public function clientes(callable $avance, callable $debeParar): array;

    /** @return array{status:int,json:mixed} */
    protected function pedir(string $metodo, string $url, array $headers = [], ?array $cuerpo = null): array
    {
        $intentos = 0;

        while (true) {
            $r = ($this->transporte)($metodo, $url, $headers, $cuerpo);

            // Demasiados pedidos: se espera y se reintenta unas veces.
            if (($r['status'] ?? 0) === 429 && $intentos < 5) {
                $intentos++;
                sleep(min(30, 2 ** $intentos));
                continue;
            }

            $status = (int) ($r['status'] ?? 0);

            if ($status === 401 || $status === 403) {
                throw new \RuntimeException('La plataforma rechazó el token o la clave de la API (sin permiso). Revisá que esté bien copiada y que tenga permiso para ver clientes.');
            }
            if ($status === 404) {
                throw new \RuntimeException('No se encontró la API en esa dirección. Revisá la URL.');
            }
            if ($status === 0) {
                throw new \RuntimeException('No se pudo conectar con la plataforma: ' . ($r['error'] ?? 'sin respuesta') . '.');
            }
            if ($status >= 400) {
                throw new \RuntimeException("La plataforma respondió con un error ({$status}).");
            }

            return $r;
        }
    }

    /** @return array{status:int,json:mixed,error?:string} */
    public static function http(string $metodo, string $url, array $headers, ?array $cuerpo): array
    {
        try {
            $cliente = Http::withHeaders($headers + ['Accept' => 'application/json'])
                ->timeout(60)
                ->connectTimeout(15)
                ->withOptions(['allow_redirects' => false]);

            $respuesta = $metodo === 'POST'
                ? $cliente->asJson()->post($url, $cuerpo ?? [])
                : $cliente->get($url);

            return ['status' => $respuesta->status(), 'json' => $respuesta->json()];
        } catch (\Throwable $e) {
            return ['status' => 0, 'json' => null, 'error' => mb_substr($e->getMessage(), 0, 150)];
        }
    }
}

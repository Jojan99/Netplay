<?php

namespace App\Services\WaBot;

/**
 * Lo que el bot mandaría, sin mandarlo.
 *
 * Es lo que hace probable un flujo: se corre en la pantalla del constructor,
 * se ve la conversación completa con sus botones, y nadie recibe un WhatsApp.
 * Antes la única prueba posible era publicar el bot y escribirle desde un
 * teléfono.
 */
class CanalSimulado implements Canal
{
    /** @var list<array<string,mixed>> */
    private array $salida = [];

    /** @return list<array<string,mixed>> */
    public function mensajes(): array
    {
        return $this->salida;
    }

    public function texto(string $texto): void
    {
        if (trim($texto) !== '') {
            $this->salida[] = ['tipo' => 'texto', 'texto' => $texto];
        }
    }

    public function botones(string $cuerpo, array $botones, string $encabezado = ''): void
    {
        $this->salida[] = [
            'tipo'       => 'botones',
            'texto'      => $cuerpo,
            'encabezado' => $encabezado ?: null,
            'botones'    => array_slice(array_map(fn ($b) => [
                'id'    => (string) ($b['id'] ?? ''),
                'title' => mb_substr((string) ($b['title'] ?? 'Opción'), 0, CanalDeWhatsapp::LARGO_BOTON),
            ], array_values($botones)), 0, CanalDeWhatsapp::MAX_BOTONES),
        ];
    }

    public function lista(string $cuerpo, array $secciones, string $textoBoton = 'Ver opciones'): void
    {
        $this->salida[] = [
            'tipo'       => 'lista',
            'texto'      => $cuerpo,
            'boton'      => $textoBoton,
            'secciones'  => $secciones,
        ];
    }

    public function imagen(string $url, string $pie = ''): void
    {
        $this->salida[] = ['tipo' => 'imagen', 'url' => $url, 'texto' => $pie];
    }

    public function documento(string $url, string $nombre, string $pie = ''): void
    {
        $this->salida[] = ['tipo' => 'documento', 'url' => $url, 'nombre' => $nombre, 'texto' => $pie];
    }

    public function enlace(string $cuerpo, string $url, string $textoBoton): void
    {
        $this->salida[] = ['tipo' => 'enlace', 'texto' => $cuerpo, 'url' => $url, 'boton' => $textoBoton];
    }
}

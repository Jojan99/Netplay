<?php

namespace App\Services\WaBot;

/**
 * Por dónde salen los mensajes del bot.
 *
 * El motor de flujos no sabe si lo que manda va a WhatsApp o a una pantalla de
 * prueba: eso es lo que permite probar un flujo sin escribirle a un cliente.
 * Sin esto, la única forma de saber si el flujo que se dibujó funciona era
 * mandárselo a alguien de verdad.
 */
interface Canal
{
    public function texto(string $texto): void;

    /**
     * Botones de respuesta rápida. Meta admite 3 como máximo y 20 caracteres
     * por botón; recortar es del canal, no del flujo.
     *
     * @param  list<array{id: string, title: string}>  $botones
     */
    public function botones(string $cuerpo, array $botones, string $encabezado = ''): void;

    /**
     * Lista desplegable. Meta admite 10 filas en total, títulos de 24
     * caracteres y descripciones de 72.
     *
     * @param  list<array{title: string, rows: list<array{id: string, title: string, description?: string}>}>  $secciones
     */
    public function lista(string $cuerpo, array $secciones, string $textoBoton = 'Ver opciones'): void;

    public function imagen(string $url, string $pie = ''): void;

    public function documento(string $url, string $nombre, string $pie = ''): void;

    /** Botón que abre una dirección dentro de WhatsApp. */
    public function enlace(string $cuerpo, string $url, string $textoBoton): void;
}

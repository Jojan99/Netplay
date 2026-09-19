<?php

namespace App\Services\Red;

/**
 * El operador pidió parar una tarea de acceso remoto y se llegó a un punto
 * donde parar no deja nada a medias (antes de mandarle comandos a la OLT).
 */
class TareaDetenida extends \RuntimeException
{
}

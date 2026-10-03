<?php

namespace App\Services\FacturaElectronica\Proveedores;

/**
 * El proveedor respondió que no. $temporal distingue lo que vale la pena
 * reintentar solo (caída, límite de peticiones) de lo que exige que alguien
 * corrija un dato (validación, resolución vencida).
 */
class ErrorDelProveedor extends \RuntimeException
{
    public function __construct(string $mensaje, public bool $temporal = false, public ?array $respuesta = null)
    {
        parent::__construct($mensaje);
    }
}

<?php
namespace App\IManagerConection\IManagerConection;

/**
 * Clase interfaz del caso de uso para obtener la información de pqrs en el sistema
 *
 * @package App\UseCases\Menus\Interfaces
 * @author Network Golden <sa.networkgolden@gmail.com>
 * @copyright 2022/06/13
 */
interface IManagerConection{

        /**
     * @return mixed
     */
    public function conection(): mixed;
}
<?php

namespace App\UseCases\Notifications\Interfaces;

use App\Http\Requests\Search\SearchRequest;

/**
 * Clase interfaz del caso de uso para obtener la información de pqrs en el sistema
 *
 * @package App\UseCases\Menus\Interfaces
 * @author Network Golden <sa.networkgolden@gmail.com>
 * @copyright 2022/06/13
 */
interface SendNotificationRememberUseCaseInterface
{
    /**
     * @return mixed
     */
    public function sendNotificationRemeReminder(): mixed;
}

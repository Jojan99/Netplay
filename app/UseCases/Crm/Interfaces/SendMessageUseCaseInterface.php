<?php

namespace App\UseCases\Crm\Interfaces;

interface SendMessageUseCaseInterface
{
    /**
     * Ejecuta el envío de un mensaje dentro de una conversación
     *
     * @param int $conversationId
     * @param string $content
     * @param int|null $agentId
     * @return mixed
     */
    public function execute(
        int $conversationId,
        string $content,
        ?int $agentId
    );
}

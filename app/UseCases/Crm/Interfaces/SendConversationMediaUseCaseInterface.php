<?php

namespace App\UseCases\Crm\Interfaces;

use Illuminate\Http\UploadedFile;

interface SendConversationMediaUseCaseInterface
{
    public function execute(
    int $conversationId,
    string $type,
    UploadedFile $file,
    int $userId
):  array;
}

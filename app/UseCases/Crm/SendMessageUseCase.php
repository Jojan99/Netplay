<?php

namespace App\UseCases\Crm;

use App\Constants\ApiResponseConstants;
use App\Events\InboxMessageEvent;
use App\Events\NewMessageEvent;
use App\Repositories\Interfaces\ConversationRepositoryInterface;
use App\Services\WhatsAppService;
use App\UseCases\Crm\Interfaces\SendMessageUseCaseInterface;
use App\Events\InboxUpdatedEvent;
use App\Services\WatchChimpService;

class SendMessageUseCase implements SendMessageUseCaseInterface
{
    public function __construct(
        private ConversationRepositoryInterface $conversationRepository
    ) {}

    public function execute(
    int $conversationId,
    string $content,
    ?int $agentId = null,
    ?int $quotedMessageId = null,
    string $type = 'text',
    array $extra = []
) {
    // 🔥 USUARIO REAL (SIEMPRE)
    $agentId = $agentId ?? getSessionUserId();

    if (!$agentId) {
            return [
                'message' => 'Usuario no autenticado',
                'status' => 1,
                'data' => ApiResponseConstants::DATA_NULL
            ];
    }

    // 🔍 obtener conversación
    $conversation = $this->conversationRepository->find($conversationId);

    if (!$conversation) {
           return [
                'message' => 'Conversación no encontrada',
                'status' => 1,
                'data' => ApiResponseConstants::DATA_NULL
            ];
    }

    // 🔥 AUTO-ASIGNACIÓN SI ES NEW
    if ($conversation->status === 'new') {

        $this->conversationRepository->updateAssignedUser(
            $conversation->id,
            $agentId
        );

        $this->conversationRepository->updateStatus(
            $conversation->id,
            'in_progress'
        );

        $this->conversationRepository->insertAssignment([
            'conversation_id' => $conversation->id,
            'from_user_id'    => null,
            'to_user_id'      => $agentId,
            'reason'          => 'auto_assign_first_reply'
        ]);

    }
        $newStatus = 'in_progress';


    // Responder citando: sólo si el mensaje citado pertenece a esta conversación
    $quoted = $quotedMessageId ? $this->conversationRepository->findMessageForQuote($quotedMessageId, $conversation->id) : null;

    // Tipos especiales: sticker (content = URL del webp), ubicación (extra: latitude, longitude, name, address), contacto
    $messageType = in_array($type, ['sticker', 'location', 'contact', 'reaction'], true) ? $type : 'text';
    // Reacción: content = emoji ('' para quitarla), extra.target_message_id = mensaje reaccionado
    $target = $messageType === 'reaction' && !empty($extra['target_message_id'])
        ? $this->conversationRepository->findMessageForQuote((int)$extra['target_message_id'], $conversation->id) : null;
    if ($messageType === 'reaction' && !$target) {
        return ['status' => 'error', 'message' => 'Mensaje a reaccionar no encontrado'];
    }
    $mediaUrl = null;
    if ($messageType === 'sticker') { $mediaUrl = $content; $content = null; }
    if ($messageType === 'location') {
        $lat = $extra['latitude'] ?? null; $lng = $extra['longitude'] ?? null;
        $content = "📍 Ubicación: lat {$lat}, lng {$lng}";
        $where = trim(implode(' · ', array_filter([$extra['name'] ?? null, $extra['address'] ?? null])));
        if ($where) $content .= "\n" . $where;
    }
    if ($messageType === 'contact') {
        $content = "👤 Contacto: " . ($extra['contact_name'] ?? $extra['contact_phone'] ?? '') . "\n" . ($extra['contact_phone'] ?? '');
    }

    // 4️⃣ guardar mensaje
    $message = $this->conversationRepository->createMessage([
        'conversation_id'   => $conversation->id,
        'sender_user_id'    => $agentId,
        'sender_type'       => 'agent',
        'content'           => $content,
        'message_type'      => $messageType,
        'quoted_message_id' => $target['id'] ?? $quoted['id'] ?? null,
    ]);
    if ($mediaUrl) { $message->media_url = $mediaUrl; $message->mime_type = 'image/webp'; $message->save(); }

    // 5️⃣ broadcast chat
    broadcast(new NewMessageEvent($message, $conversation->id, $quoted));

    // 6️⃣ broadcast inbox
    // 🔥 Broadcast inbox con estado final real
        broadcast(new InboxUpdatedEvent(
            $conversation->id,
            $newStatus,
            'agent',
            $conversation->provider ?? 'netplay'
        ));


        // $whats = new WatchChimpService();

        //     $whats->replyText(
        //     $conversation->phone,
        //     $content,
        //     "wamid.HBgMNTczMTQ0NTgwODQ0FQIAEhgUM0ExMkJBRjlCRjJDQjIzNEEwMUYA"
        // );

    //WhatsApp
    // Misma empresa, dos mecanismos posibles (Meta API o Netplay WhatsApp): se debe usar
    // el provider real de ESTA conversación, nunca un valor global de la empresa.
    $whatsAppService = new WhatsAppService(
        $conversation->company_id,
        false,
        $conversation->provider ?? 'netplay'
    );

    $quotedArg = $quoted ? ['id' => $quoted['external_id'], 'fromMe' => $quoted['sender_type'] !== 'customer', 'text' => $quoted['content']] : null;
    $whats = match ($messageType) {
        'sticker'  => $whatsAppService->sendSticker($conversation->phone, $mediaUrl, $quotedArg),
        'location' => $whatsAppService->sendLocation($conversation->phone, (float)($extra['latitude'] ?? 0), (float)($extra['longitude'] ?? 0), $extra['name'] ?? null, $extra['address'] ?? null, $quotedArg),
        'contact'  => $whatsAppService->sendContact($conversation->phone, $extra['contact_name'] ?? null, (string)($extra['contact_phone'] ?? ''), $quotedArg),
        'reaction' => $whatsAppService->sendReaction($conversation->phone, (string)$target['external_id'], $target['sender_type'] !== 'customer', $content),
        default    => $whatsAppService->mensajeInformativo($conversation->phone, $content, $quotedArg),
    };

    // Guardar el id de WhatsApp para seguir los acks (entregado / leído)
    $externalId = is_array($whats) ? ($whats['messageId'] ?? ($whats['messages'][0]['id'] ?? null)) : null;
    $failed = !is_array($whats) || (($whats['status'] ?? null) === 'error') || (isset($whats['success']) && $whats['success'] === false) || isset($whats['error']);
    $this->conversationRepository->setMessageExternalId($message->id, $externalId, $failed ? 'failed' : 'sent');
    if ($failed) broadcast(new \App\Events\MessageStatusEvent($conversation->id, $message->id, 'failed'));

    return is_array($whats) ? $whats + ['message_id' => $message->id] : $whats;
}
}

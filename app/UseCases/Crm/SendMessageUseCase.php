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
    // Votar en una encuesta: no crea mensaje, sólo envía el voto y lo registra
    if ($type === 'poll_vote') {
        $target = $this->conversationRepository->findMessageForQuote((int)($extra['target_message_id'] ?? 0), $conversation->id);
        if (!$target || $target['message_type'] !== 'poll' || !$target['external_id']) return ['status' => 'error', 'message' => 'Encuesta no encontrada'];
        $options = array_values(array_filter((array)($extra['options'] ?? []), 'is_string'));
        $whatsAppService = new WhatsAppService($conversation->company_id, false, $conversation->provider ?? 'netplay');
        $r = $whatsAppService->sendPollVote($conversation->phone, (string)$target['external_id'], $options);
        if (!is_array($r) || ($r['status'] ?? null) !== 'ok') return ['status' => 'error', 'message' => $r['message'] ?? $r['error'] ?? 'No se pudo votar'];
        $this->conversationRepository->upsertPollVote($target['id'], 'agent', 'agent', null, $options);
        broadcast(new \App\Events\PollVoteEvent($conversation->id, $target['id'], $this->conversationRepository->getPollVotes($target['id'])));
        return $r + ['votes' => $this->conversationRepository->getPollVotes($target['id'])];
    }

    $messageType = in_array($type, ['sticker', 'location', 'contact', 'reaction', 'poll'], true) ? $type : 'text';
    if ($messageType === 'poll') {
        $pollOptions = array_values(array_filter(array_map('trim', (array)($extra['options'] ?? []))));
        if (count($pollOptions) < 2) return ['status' => 'error', 'message' => 'La encuesta necesita al menos 2 opciones'];
        $content = "📊 Encuesta: " . trim($content) . "\n" . implode("\n", array_map(fn($o) => '• ' . $o, $pollOptions));
        if ((int)($extra['selectable'] ?? 1) !== 1) $content .= "\n(varias opciones)";
    }
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
        'poll'     => $whatsAppService->sendPoll($conversation->phone, trim((string)($extra['question'] ?? '')) ?: explode("\n", $content)[0], $pollOptions, (int)($extra['selectable'] ?? 1)),
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

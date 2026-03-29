<?php

namespace App\UseCases\Crm;

use App\Events\NewMessageEvent;
use App\Events\InboxUpdatedEvent;
use App\Repositories\Interfaces\ConversationRepositoryInterface;
use App\UseCases\Crm\Interfaces\ReceiveConversationMessageUseCaseInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\DB;

class ReceiveConversationMessageUseCase
    implements ReceiveConversationMessageUseCaseInterface
{
    public function __construct(
        private ConversationRepositoryInterface $repository
    ) {
        $this->whatsAppService = new WhatsAppService();

    }

public function execute(array $payload): array
{
    Log::info('[Webhook recibido]', $payload);

    // ─── Validar estructura del webhook de Netplay ───
    if (empty($payload) || !isset($payload['event']) || !isset($payload['data'])) {
        throw new \Exception('Invalid payload: estructura inválida');
    }

    // Solo procesar mensajes recibidos
    if ($payload['event'] !== 'message.received') {
        Log::info('[Webhook ignorado]', ['event' => $payload['event']]);
        return ['status' => 'ignored', 'event' => $payload['event']];
    }

    $data = $payload['data'];

    // ─── Teléfono ───
    // Viene como "573001234567@s.whatsapp.net" o "573001234567@g.us"
    $rawFrom = $data['from'] ?? null;
    if (!$rawFrom) {
        throw new \Exception('Invalid payload: phone');
    }

    // Limpiar el @s.whatsapp.net o @g.us
    $phone = explode('@', $rawFrom)[0];

    // Si es grupo ignorar (opcional, quita este bloque si quieres procesar grupos)
    if ($data['isGroup'] ?? false) {
        Log::info('[Webhook grupo ignorado]', ['from' => $rawFrom]);
        return ['status' => 'ignored', 'reason' => 'group_message'];
    }

    // ─── Nombre ───
    $names = $data['fromName'] ?? 'Cliente';

    // ─── ID del mensaje ───
    $externalId = $data['messageId'] ?? null;

    // ─── Evitar duplicados ───
    if ($externalId) {
        $exists = DB::table('crm_messages')
            ->where('external_id', $externalId)
            ->exists();

        if ($exists) {
            Log::info('[DUPLICADO IGNORADO]', ['external_id' => $externalId]);
            return ['conversation_id' => null, 'status' => 'duplicate_ignored'];
        }
    }

    // ─── Crear o recuperar conversación ───
    $conversationId = $this->repository
        ->getOrCreateConversationByPhone($phone, $names);

    // ─── Detectar tipo de mensaje ───
    $type     = $data['type'] ?? 'text';
    $content  = null;
    $mediaUrl = null;

    switch ($type) {

        case 'text':
            $content  = $data['content'] ?? null;
            break;

        case 'image':
            $mediaUrl = $data['url'] ?? null;
            $content  = $data['caption'] ?? null;
            break;

        case 'video':
            $mediaUrl = $data['url'] ?? null;
            $content  = $data['caption'] ?? null;
            break;

        case 'audio':
            $mediaUrl = $data['url'] ?? null;
            $content  = null;
            break;

        case 'document':
            $mediaUrl = $data['url'] ?? null;
            $content  = $data['caption'] ?? null;
            break;

        case 'sticker':
            $mediaUrl = $data['url'] ?? null;
            $content  = null;
            break;

        case 'location':
            $content = "📍 Ubicación: lat {$data['latitude']}, lng {$data['longitude']}";
            break;

        case 'contact':
            $content = "👤 Contacto: " . ($data['content'] ?? 'Desconocido');
            break;

        case 'reaction':
            $content = "👍 Reacción: " . ($data['content'] ?? '');
            break;

        default:
            $type    = 'text';
            $content = $data['content'] ?? '[Mensaje no soportado]';
    }

    // ─── Guardar mensaje ───
    $message = $this->repository->storeMessage([
        'conversation_id' => $conversationId,
        'sender_type'     => 'customer',
        'message_type'    => $type,
        'content'         => $content,
        'media_url'       => $mediaUrl,
        'external_id'     => $externalId,
        'created_at'      => now(),
    ]);

    // ─── Auto mensaje solo en primer mensaje ───
    if ($this->repository->isFirstMessage($conversationId)) {
        $this->whatsAppService->mensajeInformativo(
            $phone,
            "👋 Hola, gracias por contactar a *Netplay*.\n\nEn breve uno de nuestros asesores continuará la conversación contigo."
        );
    }

    // ─── Broadcast mensaje nuevo ───
    broadcast(new NewMessageEvent($message, $conversationId));

    // ─── Actualizar inbox ───
    $currentStatus = DB::table('crm_conversations')
        ->where('id', $conversationId)
        ->value('status');

    broadcast(new InboxUpdatedEvent($conversationId, $currentStatus));

    return [
        'conversation_id' => $conversationId,
        'status'          => 'processed'
    ];
}
    /* =====================================================
     * 🔍 RESOLVER MIME / EXTENSION DESDE URL (HEAD REQUEST)
     * ===================================================== */
    private function resolveMediaMetadata(string $url): array
    {
        try {
            $headers = get_headers($url, 1);

            $contentType = $headers['Content-Type'] ?? null;

            if (is_array($contentType)) {
                $contentType = end($contentType);
            }

            $extension = match ($contentType) {
                'image/jpeg'            => 'jpg',
                'image/png'             => 'png',
                'image/webp'            => 'webp',
                'video/mp4'             => 'mp4',
                'audio/ogg'             => 'ogg',
                'audio/mpeg'            => 'mp3',
                'application/pdf'       => 'pdf',
                'application/zip'       => 'zip',
                'application/x-rar'     => 'rar',
                'application/vnd.ms-excel' => 'xls',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
                'application/msword'    => 'doc',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
                default                 => null
            };

            return [
                $contentType,
                $extension,
                $extension ? "archivo.$extension" : null
            ];

        } catch (\Throwable) {
            return [null, null, null];
        }
    }
}

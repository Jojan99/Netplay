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

    // ─── Validar estructura del webhook ───
    if (empty($payload) || !isset($payload['event']) || !isset($payload['data'])) {
        throw new \Exception('Invalid payload: estructura inválida');
    }

    // Solo procesar mensajes recibidos
    if ($payload['event'] !== 'message.received') {
        Log::info('[Webhook ignorado]', ['event' => $payload['event']]);
        return ['status' => 'ignored', 'event' => $payload['event']];
    }

    $data = $payload['data'];

    Log::info('[Mensaje campos disponibles]', [
        'type'   => $data['type'] ?? null,
        'keys'   => array_keys($data),
    ]);

    // ─── Teléfono ───
    $rawFrom = $data['from'] ?? null;
    if (!$rawFrom) {
        throw new \Exception('Invalid payload: phone');
    }

    // Limpiar el @s.whatsapp.net / @g.us / @lid
    $phone = explode('@', $rawFrom)[0];

    // Ignorar grupos
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
        $duplicate = DB::table('crm_messages')
            ->where('external_id', $externalId)
            ->select('id', 'conversation_id')
            ->first();

        if ($duplicate) {
            Log::info('[DUPLICADO IGNORADO]', ['external_id' => $externalId]);
            // Igual broadcast para que el frontend actualice si estaba esperando
            try {
                $convStatus = DB::table('crm_conversations')
                    ->where('id', $duplicate->conversation_id)
                    ->value('status');
                broadcast(new InboxUpdatedEvent((int)$duplicate->conversation_id, $convStatus ?? 'new'));
            } catch (\Throwable) {}
            return ['conversation_id' => $duplicate->conversation_id, 'status' => 'duplicate_ignored'];
        }
    }

    // ─── Crear o recuperar conversación ───
    $conversationId = $this->repository
        ->getOrCreateConversationByPhone($phone, $names);

    // ─── Detectar tipo de mensaje ───
    $type     = $data['type'] ?? 'text';
    $content  = null;
    $mediaUrl = null;

    // Helper para resolver la URL de media con múltiples campos posibles
    $resolveUrl = fn() => $data['url']
        ?? $data['mediaUrl']
        ?? $data['media_url']
        ?? $data['link']
        ?? $data['fileUrl']
        ?? null;

    switch ($type) {

        case 'text':
            $content = $data['content'] ?? $data['body'] ?? null;
            break;

        case 'image':
            $mediaUrl = $resolveUrl();
            $content  = $data['caption'] ?? null;
            if (!$mediaUrl && !$content) {
                $content = '[Imagen recibida]';
            }
            break;

        case 'video':
            $mediaUrl = $resolveUrl();
            $content  = $data['caption'] ?? null;
            if (!$mediaUrl && !$content) {
                $content = '[Video recibido]';
            }
            break;

        case 'audio':
            $mediaUrl = $resolveUrl();
            if (!$mediaUrl) {
                $content = '[Audio recibido]';
            }
            break;

        case 'document':
            $mediaUrl = $resolveUrl();
            $content  = $data['caption'] ?? $data['filename'] ?? null;
            if (!$mediaUrl && !$content) {
                $content = '[Documento recibido]';
            }
            break;

        case 'sticker':
            $mediaUrl = $resolveUrl();
            // Los stickers frecuentemente no traen 'url' directo
            if (!$mediaUrl) {
                $content = '[Sticker recibido]';
                Log::info('[Sticker sin URL]', ['data_keys' => array_keys($data)]);
            }
            break;

        case 'location':
            $lat     = $data['latitude']  ?? $data['lat'] ?? '?';
            $lng     = $data['longitude'] ?? $data['lng'] ?? '?';
            $content = "📍 Ubicación: lat {$lat}, lng {$lng}";
            break;

        case 'contact':
            $content = "👤 Contacto: " . ($data['content'] ?? $data['name'] ?? 'Desconocido');
            break;

        case 'reaction':
            $content = "👍 Reacción: " . ($data['content'] ?? $data['reaction'] ?? '');
            break;

        default:
            Log::warning('[Tipo de mensaje desconocido]', ['type' => $type, 'data' => $data]);
            $type    = 'text';
            $content = $data['content'] ?? $data['body'] ?? '[Mensaje no soportado: ' . ($data['type'] ?? 'unknown') . ']';
    }

    // Seguridad: si content y mediaUrl siguen vacíos, logueamos para debug
    if (!$content && !$mediaUrl) {
        Log::warning('[Mensaje sin contenido ni media]', [
            'type'      => $type,
            'externalId'=> $externalId,
            'data_keys' => array_keys($data),
        ]);
    }

    // ─── Guardar mensaje ───
    $mimeType = $data['mimetype'] ?? $data['mime_type'] ?? $data['mimeType'] ?? null;

    $message = $this->repository->storeMessage([
        'conversation_id' => $conversationId,
        'sender_type'     => 'customer',
        'message_type'    => $type,
        'content'         => $content,
        'media_url'       => $mediaUrl,
        'mime_type'       => $mimeType,
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
        'status'          => 'processed',
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

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
use App\Models\Company;

class ReceiveConversationMessageUseCase
    implements ReceiveConversationMessageUseCaseInterface
{
    public function __construct(
        private ConversationRepositoryInterface $repository
    ) {}

public function execute(array $payload): array
{
    Log::info('[Webhook recibido]', $payload);

    // ─── Validar estructura del webhook ───
    if (empty($payload) || !isset($payload['event']) || !isset($payload['data'])) {
        throw new \Exception('Invalid payload: estructura inválida');
    }

    // Acks de entrega / lectura de mensajes enviados por el agente
    if ($payload['event'] === 'message.status') {
        $d = $payload['data'];
        $status = $d['status'] ?? null;
        if (!empty($d['messageId']) && in_array($status, ['sent', 'delivered', 'read', 'failed'], true)) {
            $hit = $this->repository->applyMessageStatus((string) $d['messageId'], $status);
            if ($hit) broadcast(new \App\Events\MessageStatusEvent($hit['conversation_id'], $hit['id'], $status));
        }
        return ['status' => 'ok', 'event' => 'message.status'];
    }

    // Votos de encuesta (descifrados por el servicio Node)
    if ($payload['event'] === 'poll.vote') {
        $d = $payload['data'];
        $msg = !empty($d['pollMessageId']) ? DB::table('crm_messages')->where('external_id', $d['pollMessageId'])->first(['id', 'conversation_id']) : null;
        if ($msg) {
            $fromMe = !empty($d['fromMe']);
            $name = $fromMe ? null : DB::table('crm_conversations as c')->join('crm_customers as cu', 'cu.id', '=', 'c.customer_id')->where('c.id', $msg->conversation_id)->value('cu.name');
            $this->repository->upsertPollVote((int)$msg->id, $fromMe ? 'agent' : (string)($d['voterJid'] ?? $d['voterPhone'] ?? 'customer'), $fromMe ? 'agent' : 'customer', $name, (array)($d['selected'] ?? []));
            broadcast(new \App\Events\PollVoteEvent((int)$msg->conversation_id, (int)$msg->id, $this->repository->getPollVotes((int)$msg->id)));
        }
        return ['status' => 'ok', 'event' => 'poll.vote'];
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
    $phone = $data['phone'] ?? null;
    if (!$phone) {
        throw new \Exception('Invalid payload: phone');
    }

    // Ignorar grupos
    if ($data['isGroup'] ?? false) {
        Log::info('[Webhook grupo ignorado]', ['phone' => $phone]);
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
                // sender=agent: el panel no debe sonar ni sumar no leídos por un webhook repetido
                broadcast(new InboxUpdatedEvent((int)$duplicate->conversation_id, $convStatus ?? 'new', 'agent', $provider ?? null));
            } catch (\Throwable) {}
            return ['conversation_id' => $duplicate->conversation_id, 'status' => 'duplicate_ignored'];
        }
    }

    // ─── Crear o recuperar conversación ───
    $provider = ($payload['provider'] ?? null) === 'meta' || ($payload['instanceId'] ?? null) === 'meta_official_api'
        ? 'meta'
        : 'netplay';
    $companyId = isset($payload['company_id']) ? (int) $payload['company_id'] : null;

    // Netplay envía instanceId, no company_id. Resolver la empresa por la instancia
    // evita crear clientes/conversaciones sin compañía y no altera el flujo de Meta.
    if ($provider === 'netplay' && !$companyId && !empty($payload['instanceId'])) {
        $companyId = Company::where('wa_instance_id', $payload['instanceId'])->value('id');

        Log::info('[Netplay Webhook] Empresa resuelta por instancia', [
            'instance_id' => $payload['instanceId'],
            'company_id' => $companyId,
        ]);
    }

    if (!$companyId) {
        throw new \Exception('No se pudo resolver la empresa del webhook');
    }

    // ─── Descartar los eventos vacíos ANTES de crear nada ───
    //
    // WhatsApp manda eventos internos que Baileys no clasifica (ajustes de
    // mensajes temporales, borrados, vencimiento de "ver una vez"): llegan con
    // type "unknown" y sin texto ni archivo.
    //
    // La comprobación va acá y no más abajo a propósito: si se descarta el
    // mensaje después de getOrCreateConversationByPhone, la conversación ya
    // quedó creada y aparece vacía en la bandeja del agente.
    $tiposConocidos = ['text', 'image', 'video', 'audio', 'document', 'sticker',
                       'location', 'contact', 'poll', 'event', 'reaction'];

    if (!in_array($data['type'] ?? '', $tiposConocidos, true)
        && empty($data['content']) && empty($data['body'])
        && empty($data['url']) && empty($data['mediaUrl']) && empty($data['filepath'])) {
        Log::info('[Evento vacío ignorado]', [
            'type'      => $data['type'] ?? null,
            'phone'     => $phone,
            'data_keys' => array_keys($data),
        ]);

        return ['status' => 'ignored', 'reason' => 'evento_sin_contenido'];
    }

    // ─── Puerta de identificación ───
    //
    // Antes de crear la conversación se comprueba si hay que pedirle la cédula
    // a esta persona. Mientras se pregunta, el mensaje queda retenido y no se
    // crea nada en el CRM: el agente no ve chats de gente sin identificar.
    // Cuando responde, la puerta devuelve los mensajes retenidos para que
    // entren en orden, como si hubieran llegado recién.
    $puerta = app(\App\Services\Crm\PuertaIdentificacion::class);
    $mensajesPrevios = [];

    if (!($payload['_saltar_identificacion'] ?? false)) {
        $decision = $puerta->evaluar($companyId, $provider, $phone, $data['content'] ?? null, $payload);

        if ($decision['accion'] === \App\Services\Crm\PuertaIdentificacion::RETIENE) {
            return ['status' => 'retenido_identificacion', 'phone' => $phone];
        }

        // Los retenidos incluyen el mensaje actual al final; se procesan los
        // anteriores y este sigue por el camino normal de abajo.
        $mensajesPrevios = array_slice($decision['retenidos'] ?? [], 0, -1);
        $identidad = $decision;
    } else {
        $identidad = ['dni' => null, 'user_id' => null, 'nombre' => null];
    }

    $conversationId = $this->repository
        ->getOrCreateConversationByPhone($phone, $names, $companyId, $provider);

    // Vincular el cliente real a la ficha del CRM, que hasta ahora solo tenía
    // el teléfono. Es lo que permite abrir la ficha del cliente desde el chat.
    if (!empty($identidad['dni']) || !empty($identidad['user_id'])) {
        $this->vincularCliente($conversationId, $identidad);
    }

    // Soltar lo que el cliente escribió mientras se le preguntaba
    foreach ($mensajesPrevios as $previo) {
        try {
            $previo['_saltar_identificacion'] = true;
            $this->execute($previo);
        } catch (\Throwable $e) {
            Log::warning('[Identificación] No se pudo soltar un mensaje retenido', [
                'phone' => $phone, 'error' => $e->getMessage(),
            ]);
        }
    }

    // ─── Registrar LID / JID → teléfono real en whatsapp-service ───
    // Cada mensaje que llega le dice al servicio de WhatsApp cuál es
    // el número real de este remitente, así cuando reenvíe comprobantes
    // al grupo ya sabe quién es sin depender de búsquedas posteriores.
    try {
        $instanceId = $payload['instanceId'] ?? null;
        $fromJid    = $data['from'] ?? null;
        if ($instanceId && $fromJid) {
            $jid = explode('@', $fromJid)[0] ?? null;
            if ($jid && $jid !== $phone) {
                $waBaseUrl = rtrim(config('services.netplay_whatsapp.base_url', 'http://181.48.150.43:3001/crm'), '/');
                $registerUrl = str_replace('/crm', '', $waBaseUrl) . "/instances/{$instanceId}/register-phone";
                // Este endpoint dejó de estar abierto: va firmado con la clave
                // maestra, igual que el resto del aprovisionamiento.
                \Illuminate\Support\Facades\Http::timeout(10)
                    ->withHeaders(['x-master-key' => (string) config('services.netplay_whatsapp.master_key')])
                    ->post($registerUrl, [
                        'lid'   => $jid,
                        'phone' => $phone,
                        'name'  => $names,
                    ]);
                Log::info('[LID Register] Mapeo enviado a whatsapp-service', [
                    'instance_id' => $instanceId,
                    'jid'         => $jid,
                    'phone'       => $phone,
                ]);
            }
        }
    } catch (\Throwable $e) {
        Log::warning('[LID Register] Error registrando LID', ['error' => $e->getMessage()]);
    }

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
            $extraLoc = trim(implode(' · ', array_filter([$data['name'] ?? null, $data['address'] ?? null])));
            if ($extraLoc) $content .= "\n" . $extraLoc;
            if (!empty($data['live'])) $content .= "\n(ubicación en tiempo real)";
            break;

        case 'contact':
            // Formato: "👤 Contacto: Nombre\n+573001234567" (el panel lo usa para escribirle o abrir su ficha)
            $content = "👤 Contacto: " . ($data['content'] ?? $data['name'] ?? 'Desconocido');
            if (!empty($data['phone'])) $content .= "\n" . $data['phone'];
            if (!empty($data['contacts']) && count($data['contacts']) > 1) {
                foreach (array_slice($data['contacts'], 1) as $c) {
                    $content .= "\n" . ($c['name'] ?? 'Contacto') . (!empty($c['phone']) ? ' ' . $c['phone'] : '');
                }
            }
            break;

        case 'poll':
            $opts = array_map(fn($o) => '• ' . $o, $data['options'] ?? []);
            $content = "📊 Encuesta: " . ($data['content'] ?? 'Sin título') . ($opts ? "\n" . implode("\n", $opts) : '');
            if ((int)($data['selectableCount'] ?? 1) !== 1) $content .= "\n(varias opciones)";
            break;

        case 'event':
            $when = !empty($data['startTime']) ? Carbon::parse($data['startTime'])->timezone('America/Bogota')->format('d/m/Y H:i') : null;
            $where = trim(implode(' · ', array_filter([$data['locationName'] ?? null, $data['locationAddress'] ?? null])));
            $content = "📅 Evento: " . ($data['content'] ?? 'Sin título')
                . ($when ? "\n🕒 {$when}" : '')
                . ($where ? "\n📍 {$where}" : '')
                . (!empty($data['description']) ? "\n" . $data['description'] : '')
                . (!empty($data['joinLink']) ? "\n🔗 " . $data['joinLink'] : '')
                . (!empty($data['canceled']) ? "\n(cancelado)" : '');
            break;

        case 'reaction':
            $content = trim((string)($data['content'] ?? $data['reaction'] ?? ''));
            if (!empty($data['reactionTo'])) {
                $reactedId = DB::table('crm_messages')->where('external_id', $data['reactionTo'])->value('id');
            }
            break;

        default:
            Log::warning('[Tipo de mensaje desconocido]', ['type' => $type, 'data' => $data]);
            $type    = 'text';
            $content = $data['content'] ?? $data['body'] ?? null;
    }

    // Sin texto y sin archivo no hay nada que mostrar.
    //
    // WhatsApp manda eventos internos que Baileys no clasifica (ajustes de
    // mensajes temporales, borrados, vencimiento de "ver una vez"). Antes se
    // guardaban como "[Mensaje no soportado: unknown]" y el agente veía una
    // burbuja vacía que no dice nada y ensucia la conversación. Se descartan.
    if (!$content && !$mediaUrl) {
        Log::info('[Mensaje sin contenido ignorado]', [
            'type'       => $type,
            'externalId' => $externalId,
            'data_keys'  => array_keys($data),
        ]);

        return [
            'status'          => 'ignored',
            'reason'          => 'sin_contenido',
            'conversation_id' => $conversationId,
        ];
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
        'quoted_message_id' => $reactedId ?? null,   // reacción: id del mensaje reaccionado
        'created_at'      => now(),
    ]);

    $settings = \App\Support\CrmSettings::for((int)$companyId);
    $isFirst  = $this->repository->isFirstMessage($conversationId);

    // ─── Asignación automática al agente con menos chats abiertos ───
    if ($settings['auto_assign']) {
        try { $this->autoAssign($conversationId, (int)$companyId); } catch (\Throwable $e) {
            Log::warning('[Auto-asignación] falló', ['conversation_id' => $conversationId, 'error' => $e->getMessage()]);
        }
    }

    // ─── Fuera de horario: aviso automático (una vez cada 4 h por conversación) ───
    if ($settings['off_hours_enabled'] && !\App\Support\CrmSettings::isOpenNow($settings)) {
        $recent = DB::table('crm_messages')->where('conversation_id', $conversationId)->where('sender_type', 'system')
            ->where('created_at', '>=', now()->subHours(4))->exists();
        if (!$recent) {
            try {
                $text = $settings['off_hours_message'] ?: \App\Support\CrmSettings::DEFAULT_OFF_HOURS;
                (new WhatsAppService($companyId, false, $provider))->mensajeInformativo($phone, $text);
                $sys = $this->repository->storeMessage([
                    'conversation_id' => $conversationId, 'sender_type' => 'system', 'message_type' => 'text',
                    'content' => $text, 'status' => 'sent', 'created_at' => now(),
                ]);
                broadcast(new NewMessageEvent($sys, $conversationId));
            } catch (\Throwable $e) {
                Log::warning('[Fuera de horario] No se pudo enviar el aviso', ['conversation_id' => $conversationId, 'error' => $e->getMessage()]);
            }
        }
    }

    // ─── Auto mensaje solo en primer mensaje ───
    if ($isFirst) {
        try {
            // Misma empresa, dos mecanismos posibles: responder siempre por el provider
            // real de este webhook (meta o netplay), nunca por un valor global de la empresa.
            (new WhatsAppService($companyId, false, $provider))->mensajeInformativo(
                $phone,
                $settings['welcome_message'] ?: \App\Support\CrmSettings::DEFAULT_WELCOME
            );
        } catch (\Throwable $e) {
            Log::warning('[Auto-mensaje] No se pudo enviar mensaje de bienvenida', [
                'conversation_id' => $conversationId,
                'phone'           => $phone,
                'error'           => $e->getMessage(),
            ]);
            // No fallar el webhook si el auto-mensaje no se puede enviar
        }
    }

    // ─── Broadcast mensaje nuevo ───
    broadcast(new NewMessageEvent($message, $conversationId));

    // ─── Actualizar inbox ───
    $currentStatus = DB::table('crm_conversations')
        ->where('id', $conversationId)
        ->value('status');

    broadcast(new InboxUpdatedEvent($conversationId, $currentStatus, 'customer', $provider));

    return [
        'conversation_id' => $conversationId,
        'status'          => 'processed',
    ];
}

/**
 * Reparte la conversación (si no tiene agente) al agente activo con menos chats abiertos.
 */
private function autoAssign(int $conversationId, int $companyId): void
{
    $conv = DB::table('crm_conversations')->where('id', $conversationId)->first(['assigned_user_id', 'status']);
    if (!$conv || $conv->assigned_user_id || $conv->status === 'closed') return;

    $agent = DB::table('crm_agents as a')
        ->where('a.active', 1)
        ->where('a.company_id', $companyId)
        ->leftJoin('crm_conversations as c', function ($j) { $j->on('c.assigned_user_id', '=', 'a.user_id')->whereIn('c.status', ['new', 'in_progress']); })
        ->groupBy('a.user_id')
        ->selectRaw('a.user_id, COUNT(c.id) as open_count')
        ->orderBy('open_count')->orderByRaw('RAND()')
        ->first();
    if (!$agent) return;

    DB::table('crm_conversations')->where('id', $conversationId)->update(['assigned_user_id' => $agent->user_id, 'updated_at' => now()]);
    DB::table('crm_conversation_assignments')->insert([
        'conversation_id' => $conversationId, 'from_user_id' => null, 'to_user_id' => $agent->user_id,
        'reason' => 'auto_assign_inbound', 'created_at' => now(),
    ]);
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

/**
 * Deja el cliente de Netplay pegado a la ficha del CRM.
 *
 * crm_customers solo guardaba el teléfono; sin esto el agente no puede abrir
 * la ficha del cliente desde el chat porque no hay a quién apuntar.
 */
private function vincularCliente(int $conversationId, array $identidad): void
{
    try {
        $customerId = \Illuminate\Support\Facades\DB::table('crm_conversations')
            ->where('id', $conversationId)->value('customer_id');

        if (!$customerId) {
            return;
        }

        $cambios = array_filter([
            'user_id' => $identidad['user_id'] ?? null,
            'dni'     => $identidad['dni'] ?? null,
            'name'    => $identidad['nombre'] ?? null,
        ], fn($v) => $v !== null && $v !== '');

        if ($cambios) {
            \Illuminate\Support\Facades\DB::table('crm_customers')
                ->where('id', $customerId)
                ->update($cambios + ['updated_at' => now()]);
        }
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::warning('[Identificación] No se pudo vincular el cliente', [
            'conversation_id' => $conversationId, 'error' => $e->getMessage(),
        ]);
    }
}
}

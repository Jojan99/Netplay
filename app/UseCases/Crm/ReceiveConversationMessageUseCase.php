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
use App\Services\WhatsApp\LineasDeWhatsApp as Lineas;

class ReceiveConversationMessageUseCase
    implements ReceiveConversationMessageUseCaseInterface
{
    public function __construct(
        private ConversationRepositoryInterface $repository
    ) {}

/**
 * Mensaje por id externo de WhatsApp, dentro de la empresa si se conoce.
 * Sin empresa se busca global, como antes (luego el webhook se rechaza igual).
 */
/**
 * La conversación abierta de ese teléfono, si la hay.
 *
 * El bot contesta al mismo número por el que escribió el cliente, así que
 * alcanza con buscar su ficha. No se crea nada: si todavía no hay
 * conversación es porque el mensaje del cliente no llegó, y la respuesta del
 * bot sola no cuenta ninguna historia.
 */
private function conversacionDelTelefono(int $companyId, string $telefono): ?int
{
    $solo = preg_replace('/\D+/', '', $telefono);

    if ($solo === '') {
        return null;
    }

    // Con y sin el 57: las fichas viejas lo guardaron de las dos formas y el
    // servicio manda el jid completo.
    $formas = array_values(array_unique([$solo, ltrim($solo, '57'), '57' . ltrim($solo, '57')]));

    $cliente = DB::table('crm_customers')
        ->where('company_id', $companyId)
        ->whereIn(DB::raw("REPLACE(REPLACE(REPLACE(phone,' ',''),'+',''),'-','')"), $formas)
        ->value('id');

    if (!$cliente) {
        return null;
    }

    return DB::table('crm_conversations')
        ->where('company_id', $companyId)
        ->where('customer_id', $cliente)
        ->whereIn('status', ['new', 'in_progress'])
        ->orderByDesc('id')
        ->value('id');
}

private function mensajePorExternalId(string $externalId, $companyId, array $cols): ?object
{
    $q = DB::table('crm_messages as m')->where('m.external_id', $externalId);
    if ($companyId) {
        $q->join('crm_conversations as cv', 'cv.id', '=', 'm.conversation_id')
          ->where('cv.company_id', (int) $companyId);
    }
    return $q->first(array_map(fn ($c) => 'm.' . $c, $cols));
}

public function execute(array $payload): array
{
    Log::info('[Webhook recibido]', $payload);

    // ─── Validar estructura del webhook ───
    if (empty($payload) || !isset($payload['event']) || !isset($payload['data'])) {
        throw new \Exception('Invalid payload: estructura inválida');
    }

    // ─── Empresa del webhook ───
    // Se resuelve antes que nada: el id de un mensaje de WhatsApp o el jid de un
    // grupo se repiten entre empresas (p.ej. una línea le escribe a otra), así que
    // toda búsqueda por ellos va filtrada por empresa.
    $provider = ($payload['provider'] ?? null) === 'meta' || ($payload['instanceId'] ?? null) === 'meta_official_api'
        ? 'meta'
        : 'netplay';
    $companyId = isset($payload['company_id']) ? (int) $payload['company_id'] : null;

    // Netplay envía instanceId, no company_id. La empresa se resuelve por el
    // CATÁLOGO de líneas y no por companies.wa_instance_id: esa columna guarda
    // una sola instancia, así que un mensaje entrado por la segunda línea de la
    // empresa no resolvía nada y se perdía sin llegar a ninguna bandeja.
    // Mientras la migración no corra, la búsqueda cae en la columna vieja.
    $instanceId = $provider === 'netplay' ? ($payload['instanceId'] ?? null) : null;

    if ($instanceId && !$companyId) {
        $companyId = Lineas::empresaDeInstancia((string) $instanceId);

        if ($payload['event'] === 'message.received') Log::info('[Netplay Webhook] Empresa resuelta por instancia', [
            'instance_id' => $instanceId,
            'company_id'  => $companyId,
        ]);
    }

    // La línea concreta por la que entró. Se guarda en la conversación para que
    // la respuesta salga por el MISMO número: contestar desde otra línea parte
    // el hilo del cliente y es lo que hacía que dos líneas se mezclaran.
    $linea   = $instanceId && $companyId ? Lineas::porInstancia((string) $instanceId, (int) $companyId) : null;
    $lineaId = $linea ? (int) $linea->id : null;

    // Acks de entrega / lectura de mensajes enviados por el agente
    if ($payload['event'] === 'message.status') {
        $d = $payload['data'];
        $status = $d['status'] ?? null;
        if (!empty($d['messageId']) && in_array($status, ['sent', 'delivered', 'read', 'failed'], true)) {
            $hit = $this->repository->applyMessageStatus((string) $d['messageId'], $status, $companyId ? (int) $companyId : null);
            if ($hit) broadcast(new \App\Events\MessageStatusEvent($hit['conversation_id'], $hit['id'], $status));
        }
        return ['status' => 'ok', 'event' => 'message.status'];
    }

    // El cliente (o el agente desde el teléfono) borró un mensaje
    if ($payload['event'] === 'message.deleted') {
        $d = $payload['data'];
        $msg = !empty($d['messageId'])
            ? $this->mensajePorExternalId((string) $d['messageId'], $companyId, ['id', 'conversation_id'])
            : null;

        if ($msg) {
            // La fila no se borra: un mensaje citado más arriba en el hilo
            // quedaría apuntando a la nada. Se marca, como hace WhatsApp.
            DB::table('crm_messages')->where('id', $msg->id)->update([
                'deleted_at' => now(),
                'deleted_by' => $d['deletedBy'] ?? 'customer',
            ]);

            broadcast(new \App\Events\MessageRevisedEvent(
                (int) $msg->conversation_id, (int) $msg->id, 'deleted', null
            ));
        }

        return ['status' => 'ok', 'event' => 'message.deleted'];
    }

    // El cliente editó un mensaje
    if ($payload['event'] === 'message.edited') {
        $d = $payload['data'];
        $msg = !empty($d['messageId'])
            ? $this->mensajePorExternalId((string) $d['messageId'], $companyId, ['id', 'conversation_id', 'content', 'content_original'])
            : null;

        if ($msg) {
            DB::table('crm_messages')->where('id', $msg->id)->update([
                // Se conserva lo que decía antes: el agente tiene que poder
                // saber qué le habían escrito realmente.
                'content_original' => $msg->content_original ?? $msg->content,
                'content'          => $d['content'] ?? $msg->content,
                'edited_at'        => now(),
            ]);

            broadcast(new \App\Events\MessageRevisedEvent(
                (int) $msg->conversation_id, (int) $msg->id, 'edited', $d['content'] ?? null
            ));
        }

        return ['status' => 'ok', 'event' => 'message.edited'];
    }

    // Lo que el bot le contestó al cliente.
    //
    // Sin esto, el agente abría la conversación y veía sólo lo que escribió
    // el cliente: «Buenas tardes», «María Fernanda altuve 1043136391»,
    // «Gracias». Las preguntas del bot no estaban en ninguna parte, así que
    // no había forma de entender por qué mandó su cédula ni qué le habían
    // prometido. El hilo llegaba cortado a la mitad.
    if ($payload['event'] === 'bot.message') {
        $d = $payload['data'];
        $telefono = (string) ($d['phone'] ?? $d['jid'] ?? '');
        $texto = trim((string) ($d['content'] ?? ''));

        if ($telefono === '' || $texto === '') {
            return ['status' => 'ignored', 'event' => 'bot.message', 'reason' => 'sin_datos'];
        }

        $conversacion = $this->conversacionDelTelefono($companyId, $telefono);

        if (!$conversacion) {
            // Todavía no hay conversación: la abre el primer mensaje del
            // cliente, que siempre llega antes que la respuesta del bot.
            return ['status' => 'ignored', 'event' => 'bot.message', 'reason' => 'sin_conversacion'];
        }

        // El mismo texto dos veces seguidas es un reintento del servicio, no
        // dos mensajes: el bot repite si la primera entrega no se confirmó.
        $repetido = DB::table('crm_messages')
            ->where('conversation_id', $conversacion)
            ->where('sender_type', 'bot')
            ->where('content', $texto)
            ->where('created_at', '>=', now()->subMinutes(2))
            ->exists();

        if ($repetido) {
            return ['status' => 'ignored', 'event' => 'bot.message', 'reason' => 'repetido'];
        }

        $id = DB::table('crm_messages')->insertGetId([
            'conversation_id' => $conversacion,
            'sender_type'     => 'bot',
            'message_type'    => 'text',
            'content'         => $texto,
            'external_id'     => $d['messageId'] ?? null,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        DB::table('crm_conversations')->where('id', $conversacion)
            ->update(['last_message_at' => now(), 'updated_at' => now()]);

        if ($mensaje = \App\Models\CrmMessage::find($id)) {
            broadcast(new \App\Events\NewMessageEvent($mensaje, $conversacion));
        }

        return ['status' => 'ok', 'event' => 'bot.message', 'conversation_id' => $conversacion];
    }

    // Votos de encuesta (descifrados por el servicio Node)
    if ($payload['event'] === 'poll.vote') {
        $d = $payload['data'];
        $msg = !empty($d['pollMessageId']) ? $this->mensajePorExternalId((string) $d['pollMessageId'], $companyId, ['id', 'conversation_id']) : null;
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

    /**
     * ¿Lo escribió el agente desde el teléfono o WhatsApp Web?
     *
     * WhatsApp sincroniza a todos los dispositivos también lo que sale. Esos
     * mensajes antes se descartaban en el servicio de WhatsApp, así que la
     * conversación del CRM quedaba con un hueco: quien la retomaba no sabía
     * qué se le había contestado al cliente.
     */
    $deAgente = (bool) ($data['fromMe'] ?? false);

    Log::info('[Mensaje campos disponibles]', [
        'type'   => $data['type'] ?? null,
        'keys'   => array_keys($data),
    ]);

    // ─── Teléfono ───
    $phone = $data['phone'] ?? null;
    if (!$phone) {
        throw new \Exception('Invalid payload: phone');
    }

    // ─── Grupos ───
    //
    // Antes se descartaban todos. Ahora entran, pero solo los que la empresa
    // marcó para seguir: una línea puede estar en grupos ajenos a la atención
    // y traerlos todos llenaría la bandeja de ruido.
    //
    // Van a su propia sección, no mezclados con los chats de clientes.
    $esGrupo   = (bool) ($data['isGroup'] ?? false);
    $grupoJid  = $data['groupJid'] ?? ($esGrupo ? $phone : null);

    if ($esGrupo) {
        if (!$grupoJid) {
            return ['status' => 'ignored', 'reason' => 'grupo_sin_jid'];
        }

        // Sin empresa no hay grupo seguido: el mismo grupo puede estar en varias líneas.
        $seguido = !$companyId ? null : DB::table('crm_grupos_seguidos')
            ->where('company_id', $companyId)
            ->where('jid', $grupoJid)
            ->where('activo', 1)
            ->first(['company_id', 'nombre']);

        if (!$seguido) {
            Log::info('[Webhook grupo no seguido]', ['jid' => $grupoJid]);
            return ['status' => 'ignored', 'reason' => 'grupo_no_seguido'];
        }
    }

    // ─── Nombre ───
    $names = $data['fromName'] ?? 'Cliente';

    // ─── ID del mensaje ───
    $externalId = $data['messageId'] ?? null;

    // ─── Evitar duplicados ───
    if ($externalId) {
        $duplicate = $this->mensajePorExternalId((string) $externalId, $companyId, ['id', 'conversation_id']);

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

    if (!$esGrupo && !($payload['_saltar_identificacion'] ?? false)) {
        // La pregunta sale por la línea por la que escribió el cliente.
        $decision = $puerta->evaluar($companyId, $provider, $phone, $data['content'] ?? null, $payload, $instanceId);

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

    $conversationId = $esGrupo
        ? $this->repository->getOrCreateGroupConversation(
            $grupoJid,
            $data['groupName'] ?? ($seguido->nombre ?? 'Grupo'),
            (int) $companyId,
            $lineaId
          )
        : $this->repository->getOrCreateConversationByPhone($phone, $names, $companyId, $provider, $lineaId);

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
                $waBaseUrl = rtrim(config('services.netplay_whatsapp.base_url', 'http://127.0.0.1:3001/crm'), '/');
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

    // Lo que el CRM manda vuelve como mensaje propio: WhatsApp se lo
    // sincroniza a todos los dispositivos, incluido el nuestro. Sin esto cada
    // respuesta del panel se vería dos veces.
    //
    // No se puede comparar por el id del mensaje porque el CRM no guarda el que
    // le devuelve WhatsApp al enviar; se compara por contenido dentro de una
    // ventana corta, que es lo que alcanza: nadie manda el mismo texto dos
    // veces en el mismo minuto por dos caminos distintos.
    if ($deAgente && $content !== null && $content !== '') {
        $eco = DB::table('crm_messages')
            ->where('conversation_id', $conversationId)
            ->where('sender_type', 'agent')
            ->where('content', $content)
            ->where('created_at', '>=', now()->subMinutes(3))
            ->exists();

        if ($eco) {
            Log::info('[CRM] Eco de un mensaje propio, ya estaba guardado', ['conversation_id' => $conversationId]);

            return ['conversation_id' => $conversationId, 'status' => 'eco_propio'];
        }
    }

    $message = $this->repository->storeMessage([
        'conversation_id' => $conversationId,
        'wa_linea_id'     => $lineaId,
        // Lo que sale desde el teléfono o WhatsApp Web lo escribió el agente,
        // no el cliente: si entrara como 'customer' la conversación se leería
        // al revés.
        'sender_type'     => $deAgente ? 'agent' : 'customer',
        'agent_signature' => $deAgente ? 'Desde WhatsApp' : null,
        'message_type'    => $type,
        'content'         => $content,
        'media_url'       => $mediaUrl,
        'mime_type'       => $mimeType,
        'external_id'     => $externalId,
        'quoted_message_id' => $reactedId ?? null,   // reacción: id del mensaje reaccionado
        // En un grupo cada mensaje lo escribe alguien distinto: sin esto el
        // hilo se leería como si hablara una sola persona.
        'participant_phone' => $esGrupo ? ($data['participantPhone'] ?? null) : null,
        'participant_name'  => $esGrupo ? ($data['participantName'] ?? null) : null,
        'created_at'      => now(),
    ]);

    // ─── Cobranza: si el asistente le está cobrando a este número, contesta él ───
    //
    // Sin bienvenida, sin aviso de fuera de horario y sin asignar agente: la
    // conversación la lleva el asistente hasta que la pase a una persona. La
    // respuesta se arma después de contestarle al webhook (la IA tarda).
    if (!$esGrupo && !$deAgente && $provider === 'netplay') {
        try {
            $caso = \App\Models\CobranzaCaso::conversandoCon((int) $companyId, (string) $phone);

            if ($caso) {
                if ((int) $caso->conversation_id !== (int) $conversationId) {
                    $caso->fill(['conversation_id' => $conversationId])->save();
                }

                \App\Jobs\ResponderCobranza::dispatchAfterResponse((int) $caso->id, (int) $message->id);

                broadcast(new NewMessageEvent($message, $conversationId));
                broadcast(new InboxUpdatedEvent($conversationId, (string) DB::table('crm_conversations')->where('id', $conversationId)->value('status'), 'customer', $provider));

                return ['conversation_id' => $conversationId, 'status' => 'processed', 'cobranza' => $caso->id];
            }
        } catch (\Throwable $e) {
            Log::warning('[Cobranza] No se pudo pasar el mensaje al asistente', ['phone' => $phone, 'error' => $e->getMessage()]);
        }
    }

    // El agente contestó desde afuera: la conversación ya está atendida. No
    // corresponde saludar, ni avisar que está fuera de horario, ni asignarla a
    // otro, ni dejar que el bot le hable encima al cliente.
    if ($deAgente) {
        $this->calmarAlBot((int) $companyId, (string) $phone, (string) ($provider ?? 'netplay'));

        DB::table('crm_conversations')->where('id', $conversationId)
            ->where('status', 'new')
            ->update(['status' => 'in_progress', 'updated_at' => now()]);

        broadcast(new NewMessageEvent($message, $conversationId));
        broadcast(new InboxUpdatedEvent(
            $conversationId,
            (string) DB::table('crm_conversations')->where('id', $conversationId)->value('status'),
            'agent',
            $provider,
        ));

        return ['conversation_id' => $conversationId, 'status' => 'processed', 'origen' => 'agente_externo'];
    }

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
                // Por la misma línea por la que escribió, no por la principal.
                (new WhatsAppService($companyId, false, $provider, $instanceId))->mensajeInformativo($phone, $text);
                $sys = $this->repository->storeMessage([
                    'conversation_id' => $conversationId, 'wa_linea_id' => $lineaId, 'sender_type' => 'system', 'message_type' => 'text',
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
            // real de este webhook (meta o netplay) y por la línea real por la que
            // entró, nunca por un valor global de la empresa.
            (new WhatsAppService($companyId, false, $provider, $instanceId))->mensajeInformativo(
                $phone,
                \App\Support\CrmSettings::withCompany(
                    $settings['welcome_message'] ?: \App\Support\CrmSettings::DEFAULT_WELCOME,
                    (int) $companyId
                )
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
 * Calla al bot con ese número.
 *
 * Si una persona está contestando desde su teléfono, el bot no puede seguir
 * respondiendo por su cuenta: el cliente recibiría dos conversaciones a la vez.
 * Es la misma pausa que usa «pasar a un agente».
 */
private function calmarAlBot(int $companyId, string $phone, string $provider): void
{
    try {
        DB::table('wa_bot_pauses')->updateOrInsert(
            ['company_id' => $companyId, 'provider' => $provider, 'phone' => $phone],
            ['paused_at' => now(), 'updated_at' => now(), 'created_at' => now()],
        );
    } catch (\Throwable $e) {
        Log::warning('[CRM] No se pudo pausar el bot', ['phone' => $phone, 'error' => $e->getMessage()]);
    }
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

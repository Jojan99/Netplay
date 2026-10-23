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
    $formas = array_values(array_unique([$solo, preg_replace('/^57/', '', $solo), '57' . preg_replace('/^57/', '', $solo)]));

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
    // Sin el contenido: el registro no es lugar para guardar lo que escriben los clientes.
    Log::info('[Webhook recibido]', ['event' => $payload['event'] ?? null, 'instancia' => $payload['instanceId'] ?? null, 'tipo' => $payload['data']['type'] ?? null]);

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
        $telefono = \App\Services\WhatsApp\Lids::telefono($telefono) ?? $telefono;
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

    /**
     * ¿Lo dijo el bot de la línea (el menú, «¿de quién es el pago?», «mándeme la cédula»)?
     *
     * Antes eso no llegaba nunca al CRM. Ahora llega marcado: se guarda como mensaje del bot,
     * pero NO cuenta como que una persona atendió —no pausa al bot, no retira a los asistentes,
     * no cambia el estado de la conversación—.
     */
    $deBot = $deAgente && (bool) ($data['fromBot'] ?? false);

    /**
     * Lo que el cliente contestó dentro de un flujo del bot (su cédula, un «sí»). El bot ya lo
     * atendió: aquí sólo se guarda para que la conversación se lea completa. Ni bienvenida, ni
     * aviso de fuera de horario, ni asignación, ni asistentes.
     */
    $soloRegistrar = !$deAgente && (bool) ($data['soloRegistrar'] ?? false);

    // Lo que quedó retenido en la puerta de identificación porque el cliente nunca dio la cédula
    // (crm:soltar-retenidos): entra a la vista del asesor tal cual, con su hora de verdad y sin
    // que nadie le conteste solo. Un «bienvenido» a quien escribió hace semanas no sirve.
    $soltado = !$deAgente && !empty($payload['_soltado']);
    $soloRegistrar = $soloRegistrar || $soltado;
    $horaOriginal = $soltado && !empty($data['timestamp']) && is_numeric($data['timestamp'])
        ? \Illuminate\Support\Carbon::createFromTimestamp((int) $data['timestamp'])
        : null;

    Log::info('[Mensaje campos disponibles]', [
        'type'   => $data['type'] ?? null,
        'keys'   => array_keys($data),
    ]);

    // ─── Teléfono ───
    $phone = $data['phone'] ?? null;
    if (!$phone) {
        throw new \Exception('Invalid payload: phone');
    }

    // Un LID en vez del número (la copia de lo que sale por la línea casi siempre llega así):
    // se cambia por el teléfono real si el servicio de WhatsApp lo conoce. Sin esto la
    // respuesta caía en un chat aparte, «Cliente 88596786761837».
    if (empty($data['isGroup']) && \App\Services\WhatsApp\Lids::pareceLid((string) $phone)) {
        $phone = \App\Services\WhatsApp\Lids::telefono((string) $phone) ?? $phone;
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
    // La comprobación va aquí y no más abajo a propósito: si se descarta el
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

    // La puerta es para lo que escribe el CLIENTE. Lo que sale de la línea (el agente desde el
    // teléfono, o el bot) no se retiene ni dispara «envíeme su cédula»: antes, si el agente le
    // escribía a alguien sin identificar, su propio mensaje quedaba retenido y al cliente le
    // llegaba la pregunta de la cédula sin haber dicho nada.
    //
    // Lo que el cliente le contesta al bot de la línea (soloRegistrar) también pasa por la puerta,
    // pero callada: si ahí dio su cédula, queda identificado y se sueltan los mensajes que
    // estuvieran retenidos, sin que la puerta le escriba nada.
    if (!$esGrupo && !$deAgente && !($payload['_saltar_identificacion'] ?? false)) {
        // La pregunta sale por la línea por la que escribió el cliente.
        $decision = $puerta->evaluar($companyId, $provider, $phone, $data['content'] ?? null, $payload, $instanceId, $data['type'] ?? null, $soloRegistrar);

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
                // Dentro de la conversación: el mismo id de WhatsApp puede estar en otra empresa.
                $reactedId = DB::table('crm_messages')->where('conversation_id', $conversationId)->where('external_id', $data['reactionTo'])->value('id');
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
    if ($deAgente && !$deBot && $content !== null && $content !== '') {
        $eco = DB::table('crm_messages')
            ->where('conversation_id', $conversationId)
            ->where('sender_type', 'agent')
            ->where('content', $content)
            ->where('created_at', '>=', now()->subMinutes(3))
            ->exists();

        // El asistente de soporte manda y después guarda: su eco puede llegar
        // en el medio, antes de que el mensaje exista en el CRM.
        $eco = $eco || \App\Services\Soporte\AgenteDeSoporte::esEcoPropio((int) $conversationId, (string) $content);

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
        'sender_type'     => $deBot ? 'system' : ($deAgente ? 'agent' : 'customer'),
        'agent_signature' => $deBot ? 'Bot' : ($deAgente ? 'Desde WhatsApp' : null),
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
        'created_at'      => $horaOriginal ?? now(),
    ]);

    // Lo que dijo el bot, o lo que el cliente le contestó dentro de un flujo: queda guardado y
    // a la vista, y nada más. No es una persona atendiendo ni un mensaje nuevo por atender.
    if ($deBot || $soloRegistrar) {
        broadcast(new NewMessageEvent($message, $conversationId));
        broadcast(new InboxUpdatedEvent($conversationId, (string) DB::table('crm_conversations')->where('id', $conversationId)->value('status'), $deBot ? 'system' : 'customer', $provider));

        return ['conversation_id' => $conversationId, 'status' => 'processed', 'origen' => $deBot ? 'bot' : 'cliente_en_flujo_del_bot'];
    }

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

                // En su propio proceso: la IA tarda y no puede ocupar el del webhook.
                \App\Console\Commands\CobranzaResponder::lanzar((int) $caso->id, (int) $message->id);

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
        // Ya hay una persona: soporte se retira, el bot se pausa y cobranza suelta el caso.
        \App\Services\Crm\UnaPersonaAtiende::tomar((int) $companyId, (int) $conversationId, (string) ($provider ?? 'netplay'), $lineaId, $instanceId ?? null);

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

    // ─── Soporte: si es un pedido de soporte (o el asistente ya lleva el caso), contesta él ───
    //
    // Igual que en cobranza: sin bienvenida, sin aviso de fuera de horario y sin
    // asignar agente. Vale para WhatsApp Web y para la API de Meta. La respuesta
    // se arma en un proceso aparte (el diagnóstico y la IA tardan).
    if (!$esGrupo) {
        try {
            $casoDeSoporte = \App\Services\Soporte\AgenteDeSoporte::tomar(
                (int) $companyId, (string) ($provider ?? 'netplay'), (string) $phone, (int) $conversationId, (int) $message->id,
                is_string($content) ? $content : null, (string) $type,
                !empty($identidad['user_id']) ? (int) $identidad['user_id'] : null, $lineaId, $instanceId ?? null,
            );

            if ($casoDeSoporte) {
                broadcast(new NewMessageEvent($message, $conversationId));
                broadcast(new InboxUpdatedEvent($conversationId, (string) DB::table('crm_conversations')->where('id', $conversationId)->value('status'), 'customer', $provider));

                return ['conversation_id' => $conversationId, 'status' => 'processed', 'soporte' => $casoDeSoporte->id];
            }
        } catch (\Throwable $e) {
            Log::warning('[Soporte] No se pudo pasar el mensaje al asistente', ['phone' => $phone, 'error' => $e->getMessage()]);
        }
    }

    $settings = \App\Support\CrmSettings::for((int)$companyId);
    // Primer contacto: nadie del equipo (ni el bot) le ha escrito en este chat. Antes se miraba
    // que hubiera exactamente un mensaje, y si llegaban dos juntos («hola» + «buenas») no salía
    // la bienvenida. El candado evita mandarla dos veces en esa misma ráfaga.
    $isFirst = !DB::table('crm_messages')->where('conversation_id', $conversationId)->where('sender_type', '!=', 'customer')->exists()
        && \Illuminate\Support\Facades\Cache::add("crm:bienvenida:{$conversationId}", 1, now()->addDay());

    // ─── Asignación automática al agente con menos chats abiertos ───
    if ($settings['auto_assign']) {
        try { $this->autoAssign($conversationId, (int)$companyId); } catch (\Throwable $e) {
            Log::warning('[Auto-asignación] falló', ['conversation_id' => $conversationId, 'error' => $e->getMessage()]);
        }
    }

    // ─── Fuera de horario: aviso automático (una vez cada 4 h por conversación) ───
    if ($settings['off_hours_enabled'] && !\App\Support\CrmSettings::isOpenNow($settings)) {
        // Una vez cada 4 h por conversación. Antes se saltaba si hubo CUALQUIER mensaje del sistema
        // (los del bot también lo son), y quien hablaba con el bot nunca recibía el aviso.
        $recent = !\Illuminate\Support\Facades\Cache::add("crm:fuera_de_horario:{$conversationId}", 1, now()->addHours(4));
        if (!$recent) {
            try {
                $text = $settings['off_hours_message'] ?: \App\Support\CrmSettings::DEFAULT_OFF_HOURS;
                // Por la misma línea por la que escribió, no por la principal.
                \App\Services\Crm\SalidasDeMetaAlCrm::sinAnotar(fn () => (new WhatsAppService($companyId, false, $provider, $instanceId))->mensajeInformativo($phone, $text));
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
 * Reparte la conversación (si no tiene agente) al agente activo con menos chats abiertos.
 */
private function autoAssign(int $conversationId, int $companyId): void
{
    // A quien está en el panel y tiene cupo (Reparto); si no hay nadie, queda para el aviso de espera.
    \App\Services\Crm\Reparto::asignar($conversationId, $companyId);
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

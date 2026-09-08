<?php

namespace App\Repositories;

use App\Repositories\Interfaces\ConversationRepositoryInterface;
use Illuminate\Support\Facades\DB;
use App\Models\CrmMessage;
use App\Models\CrmAgent;
use App\Models\CrmNote;
use App\Models\CrmLabel;
use App\Models\CrmSticker;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;



class ConversationRepository implements ConversationRepositoryInterface
{
    public function getInbox(array $filters = []): array
    {
        // filtros soportados
        $status  = $filters['status']  ?? null;   // new|in_progress|closed
        $search  = $filters['search']  ?? null;   // nombre o telefono
        $mine    = $filters['mine']    ?? null;   // 1/true => solo asignadas a mi
        $userId  = $filters['user_id'] ?? null;   // si mandas user_id explícito (opcional)
        $limit   = (int)($filters['limit'] ?? 50);
        $provider = $filters['provider'] ?? null;
        $labelId  = !empty($filters['label']) ? (int)$filters['label'] : null;



        // Subquery: último mensaje por conversación
        $lastMsgSub = DB::table('crm_messages as m1')
            ->select(
                'm1.conversation_id',
                'm1.content',
                'm1.message_type',
                'm1.media_url',
                'm1.mime_type',     // 🔥 AÑADIR ESTOMessages retrieved successfully
                'm1.created_at',
                'm1.sender_type'
            )
            ->whereRaw('m1.id = (
      SELECT m2.id
      FROM crm_messages m2
      WHERE m2.conversation_id = m1.conversation_id
      ORDER BY m2.created_at DESC, m2.id DESC
      LIMIT 1
  )');

        $q = DB::table('crm_conversations as c')
            ->join('crm_customers as cu', 'cu.id', '=', 'c.customer_id')
            ->leftJoinSub($lastMsgSub, 'lm', function ($join) {
                $join->on('lm.conversation_id', '=', 'c.id');
            })
            // Datos de agente (si quieres mostrar nombre del agente desde user_data)
            ->leftJoin('user_data as ud', 'ud.user_id', '=', 'c.assigned_user_id')
            ->where('c.company_id', getSessionCompanyId())
            ->select([
                'c.id',
                'c.status',
                'c.priority',
                'c.provider',
                'c.assigned_user_id',
                'c.created_at',

                'cu.id as customer_id',
                'cu.name as customer_name',
                'cu.phone as customer_phone',

                DB::raw("
        CASE 
            WHEN lm.message_type = 'text' THEN lm.content
            WHEN lm.message_type = 'image' THEN COALESCE(NULLIF(lm.content, ''), 'Foto')
            WHEN lm.message_type = 'audio' THEN 'Nota de voz'
            WHEN lm.message_type = 'video' THEN COALESCE(NULLIF(lm.content, ''), 'Video')
            WHEN lm.message_type = 'document' THEN COALESCE(NULLIF(lm.content, ''), 'Documento')
            WHEN lm.message_type = 'sticker' THEN 'Sticker'
            ELSE COALESCE(NULLIF(lm.content, ''), 'Mensaje')
        END as last_message_content
    "),

                'lm.message_type as last_message_type',
                'lm.sender_type as last_message_from',
                'lm.media_url as last_message_media_url',
                'lm.created_at as last_message_at',

                'ud.names as assigned_names',
                'ud.lastname as assigned_lastname',
            ])

            ->when($status && $status !== 'all', function ($qq) use ($status) {
                $qq->where('c.status', $status);
            })

            ->when(in_array($provider, ['meta', 'netplay'], true), function ($qq) use ($provider) {
                $qq->where('c.provider', $provider);
            })

            ->when($search, function ($qq) use ($search) {
                $s = '%' . $search . '%';
                $qq->where(function ($w) use ($s) {
                    $w->where('cu.name', 'like', $s)
                        ->orWhere('cu.phone', 'like', $s);
                });
            })
            // Solo filtrar por agente cuando se pide explícitamente 'mine'
            ->when(!empty($filters['mine']) && $userId, function ($qq) use ($userId) {
                $qq->where(function ($w) use ($userId) {
                    $w->where('c.assigned_user_id', $userId)
                      ->orWhereNull('c.assigned_user_id');
                });
            })


            ->when($labelId, fn($qq) => $qq->whereExists(fn($e) => $e->selectRaw('1')->from('crm_conversation_labels as cl')->whereColumn('cl.conversation_id', 'c.id')->where('cl.label_id', $labelId)))
            ->orderByRaw('COALESCE(lm.created_at, c.created_at) DESC')
            ->limit($limit);

        $rows = $q->get();

        // Etiquetas de las conversaciones devueltas (una sola consulta)
        $ids = $rows->pluck('id')->all();
        $labelsByConv = [];
        if ($ids) {
            foreach (DB::table('crm_conversation_labels as cl')->join('crm_labels as l', 'l.id', '=', 'cl.label_id')
                ->whereIn('cl.conversation_id', $ids)->get(['cl.conversation_id', 'l.id', 'l.name', 'l.color']) as $l) {
                $labelsByConv[$l->conversation_id][] = ['id' => (int)$l->id, 'name' => $l->name, 'color' => $l->color];
            }
        }

        return $rows->map(function ($row) use ($labelsByConv) {
            return [
                'labels' => $labelsByConv[$row->id] ?? [],
                'id' => (int)$row->id,
                'status' => $row->status,
                'priority' => $row->priority,
                'provider' => $row->provider,
                'assigned_user_id' => $row->assigned_user_id ? (int)$row->assigned_user_id : null,

                'customer' => [
                    'id' => (int)$row->customer_id,
                    'name' => $row->customer_name,
                    'phone' => $row->customer_phone,
                ],

                'last_message' => $row->last_message_type ? [
                    'content'   => $row->last_message_content,
                    'type'      => $row->last_message_type,
                    'from'      => $row->last_message_from,
                    'media_url' => $row->last_message_media_url,
                    'at'        => $row->last_message_at,
                ] : null,

                'assigned_user' => $row->assigned_user_id ? [
                    'names' => $row->assigned_names,
                    'lastname' => $row->assigned_lastname,
                ] : null,

                'created_at' => $row->created_at,
            ];
        })->toArray();
    }

    public function getByConversation(int $conversationId): array
    {
        // 1️⃣ Obtener conversación
        $conversation = DB::table('crm_conversations as c')
            ->join('crm_customers as cu', 'cu.id', '=', 'c.customer_id')
            ->where('c.id', $conversationId)
            ->select([
                'c.id',
                'c.company_id',
                'c.provider',
                'cu.name as customer_name',
                'cu.phone',
                'c.status',
                'c.priority',
            ])
            ->first();


        if (!$conversation) {
            throw new \Exception('Conversation not found');
        }

        $botPaused = DB::table('wa_bot_pauses')
            ->where(['company_id' => $conversation->company_id, 'provider' => $conversation->provider ?: 'netplay', 'phone' => $conversation->phone])
            ->exists();

        // 2️⃣ Obtener mensajes
        // Reacciones: se agrupan sobre el mensaje reaccionado (no son filas del chat)
        $reactions = [];
        foreach (DB::table('crm_messages')->where('conversation_id', $conversationId)->where('message_type', 'reaction')->whereNotNull('quoted_message_id')
            ->orderBy('id')->get(['quoted_message_id', 'sender_type', 'content']) as $r) {
            if ($r->content === '' || $r->content === null) { unset($reactions[$r->quoted_message_id][$r->sender_type]); continue; } // reacción quitada
            $reactions[$r->quoted_message_id][$r->sender_type] = $r->content;
        }

        $pollVotes = [];
        foreach (DB::table('crm_poll_votes as v')->join('crm_messages as pm', 'pm.id', '=', 'v.message_id')->where('pm.conversation_id', $conversationId)
            ->orderBy('v.updated_at')->get(['v.message_id', 'v.voter_key', 'v.voter_type', 'v.voter_name', 'v.options']) as $v) {
            $pollVotes[$v->message_id][] = ['voter_key' => $v->voter_key, 'voter_type' => $v->voter_type, 'voter_name' => $v->voter_name, 'options' => json_decode($v->options, true) ?: []];
        }

        $messages = DB::table('crm_messages as m')
            ->leftJoin('crm_messages as q', 'q.id', '=', 'm.quoted_message_id')
            ->where('m.conversation_id', $conversationId)
            ->where(fn($w) => $w->where('m.message_type', '!=', 'reaction')->orWhereNull('m.quoted_message_id'))
            ->orderBy('m.created_at', 'asc')
            ->orderBy('m.id', 'asc')
            ->select([
                'm.id', 'm.sender_type', 'm.content', 'm.message_type', 'm.media_url', 'm.mime_type', 'm.created_at',
                'm.status', 'm.is_note', 'm.is_forwarded', 'm.agent_signature', 'm.quoted_message_id',
                'q.sender_type as q_sender', 'q.content as q_content', 'q.message_type as q_type', 'q.media_url as q_media',
            ])
            ->get()
            ->map(function ($row) use ($reactions, $pollVotes) {
                return [
                    'id' => (int) $row->id,
                    'poll_votes' => $row->message_type === 'poll' ? ($pollVotes[$row->id] ?? []) : null,
                    'sender_type' => $row->sender_type,
                    'content' => $row->content,
                    'message_type' => $row->message_type,
                    'media_url' => $row->media_url,
                    'mime_type' => $row->mime_type,
                    'created_at' => $row->created_at,
                    'status' => $row->status,
                    'reactions' => collect($reactions[$row->id] ?? [])->map(fn($emoji, $from) => ['emoji' => $emoji, 'from' => $from])->values()->toArray(),
                    'is_note' => (bool) $row->is_note,
                    'is_forwarded' => (bool) $row->is_forwarded,
                    'agent_signature' => $row->agent_signature,
                    'quoted' => $row->quoted_message_id ? [
                        'id' => (int) $row->quoted_message_id,
                        'sender_type' => $row->q_sender,
                        'content' => $row->q_content,
                        'message_type' => $row->q_type,
                        'media_url' => $row->q_media,
                    ] : null,
                ];
            })
            ->toArray();


        // Ventana de 24 h de Meta: sólo se puede responder libremente si el cliente escribió en las últimas 24 h
        $metaWindowOpen = null; $metaWindowUntil = null;
        if ($conversation->provider === 'meta') {
            $lastCustomerAt = DB::table('crm_messages')->where('conversation_id', $conversationId)->where('sender_type', 'customer')->max('created_at');
            $until = $lastCustomerAt ? Carbon::parse($lastCustomerAt)->addHours(24) : null;
            $metaWindowOpen  = $until ? $until->isFuture() : false;
            $metaWindowUntil = $until?->toIso8601String();
        }

        // 3️⃣ Response completo (lo que Angular espera)
        return [
            'message' => 'Messages retrieved successfully',
            'conversation' => [
                'id' => (int) $conversation->id,
                'customer_name' => $conversation->customer_name,
                'phone' => $conversation->phone,
                'status' => $conversation->status,
                'priority' => $conversation->priority,
                'provider' => $conversation->provider,
                'bot_paused' => $botPaused,
                'meta_window_open' => $metaWindowOpen,
                'meta_window_until' => $metaWindowUntil,
            ],
            'data' => $messages,
            'error' => 0,
        ];
    }

   public function getConversationStatus(string $phone): bool
{
    $activeConversation = DB::table('crm_conversations as c')
        ->join('crm_customers as cu', 'cu.id', '=', 'c.customer_id')
        ->where('cu.phone', $phone)
        ->where('c.status', 'in_progress')
        ->exists();

    return $activeConversation;
}

public function getActiveConversationByPhone(string $phone): ?object
{
    return DB::table('crm_conversations as c')
        ->join('crm_customers as cu', 'cu.id', '=', 'c.customer_id')
        ->where('cu.phone', $phone)
        ->whereIn('c.status', ['in_progress'])
        ->orderByDesc('c.id')
        ->select('c.id', 'c.status', 'c.assigned_user_id')
        ->first();
}

public function getPhoneByConversationId(int $conversationId): ?string
{
    return DB::table('crm_conversations as c')
        ->join('crm_customers as cu', 'cu.id', '=', 'c.customer_id')
        ->where('c.id', $conversationId)
        ->value('cu.phone'); // devuelve solo el campo phone
}

    public function getOrCreateConversationByPhone(string $phone, string $names, ?int $companyId = null, string $provider = 'netplay'): int
    {
        $companyId = $companyId ?? getSessionCompanyId();
        $provider = in_array($provider, ['meta', 'netplay'], true) ? $provider : 'netplay';

        // 1) Buscar conversación ACTIVA (no cerrada) para ese teléfono
        $activeConversation = DB::table('crm_conversations as c')
            ->join('crm_customers as cu', 'cu.id', '=', 'c.customer_id')
            ->where('c.company_id', $companyId)
            ->where('c.provider', $provider)
            ->where('cu.phone', $phone)
            ->whereIn('c.status', ['new', 'in_progress'])
            ->orderByDesc('c.id')
            ->select('c.id', 'cu.id as customer_id')
            ->first();

        Log::info('activeConversation', ['activeConversation' => $activeConversation]);


        if ($activeConversation) {
            return (int) $activeConversation->id;
        }

        Log::info('activeConversation', ['activeConversation' => $activeConversation]);


        // 2) Buscar cliente por teléfono (puede existir aunque no haya conversación activa)
        $customer = DB::table('crm_customers')
            ->where('company_id', $companyId)
            ->where('phone', $phone)
            ->select('id')
            ->first();

        Log::info('customer', ['customer' => $customer]);

Log::info('Intentando insertar cliente', [
    'phone' => $phone,
    'name'  => $names
]);
        $customerId = $customer
            ? (int) $customer->id
            : (int) DB::table('crm_customers')->insertGetId([
                'company_id' => $companyId,
                'phone'      => $phone,
                'name'       => $names,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        Log::info('Customer creado o encontrado', ['customerId' => $customerId]);

        

        // 3) Crear nueva conversación (porque la anterior estaba cerrada o no existía)
        return (int) DB::table('crm_conversations')->insertGetId([
            'company_id'       => $companyId,
            'provider'         => $provider,
            'customer_id'      => $customerId,
            'status'           => 'new',
            'priority'         => 'normal',
            'last_message_at'  => now(),
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

    }



    public function storeMessage(array $data): CrmMessage
    {
        if (!isset(
            $data['conversation_id'],
            $data['sender_type'],
            $data['message_type']
        )) {
            throw new \InvalidArgumentException('Datos incompletos para storeMessage');
        }

        error_log(">>>>>>>>" . json_encode($data));

        $message = new CrmMessage();

        $message->conversation_id = $data['conversation_id'];
        $message->sender_user_id  = null;
        $message->sender_type     = $data['sender_type'];
        $message->message_type    = $data['message_type'];
        $message->content         = $data['content'] ?? null;
        $message->media_url       = $data['media_url'] ?? null;
        $message->external_id = $data['external_id'] ?? null;
        $message->status          = $data['status'] ?? null;
        $message->quoted_message_id = $data['quoted_message_id'] ?? null;
        $message->mime_type       = $data['mime_type'] ?? null;
        $message->extension       = $data['extension'] ?? null;
        $message->original_name   = $data['original_name'] ?? null;


        $message->save();

        // 🔄 estado automático
        $currentStatus = DB::table('crm_conversations')
    ->where('id', $data['conversation_id'])
    ->value('status');

$newStatus = $currentStatus;

// Solo cambiar a in_progress si:
// - Está en new
// - Y el agente responde

if ($currentStatus === 'new' && $data['sender_type'] === 'agent') {
    $newStatus = 'in_progress';
}
DB::table('crm_conversations')
    ->where('id', $data['conversation_id'])
    ->update([
        'status'          => $newStatus,
        'last_message_at' => now(),
        'updated_at'      => now(),
    ]);
        return $message;
    }

    public function isFirstMessage(int $conversationId): bool
{
    $count = DB::table('crm_messages')
        ->where('conversation_id', $conversationId)
        ->count();

    return $count === 1;
}







    public function find(int $id)
    {
        return DB::table('crm_conversations as c')
            ->join('crm_customers as cu', 'cu.id', '=', 'c.customer_id')
            ->where('c.id', $id)
            ->select([
                'c.id',
                'c.status',
                'c.priority',
                'c.assigned_user_id',
                'c.created_at',
                'c.company_id',
                'c.provider',

                // 🔥 datos del cliente
                'cu.phone',
                'cu.name as customer_name',
            ])
            ->first();
    }


    public function updateStatus(int $id, string $status): void
    {
        DB::table('crm_conversations')
            ->where('id', $id)
            ->update([
                'status' => $status
            ]);
    }


    public function createMessage(array $data): CrmMessage
    {
        return CrmMessage::create([
            'conversation_id'   => $data['conversation_id'],
            'sender_user_id'    => $data['sender_user_id'] ?? null,
            'sender_type'       => $data['sender_type'],
            'content'           => $data['content'],
            'message_type'      => $data['message_type'] ?? 'text',
            'quoted_message_id' => $data['quoted_message_id'] ?? null,
            'status'            => $data['status'] ?? 'pending',
        ]);
    }

    /** Guarda el id de WhatsApp del mensaje enviado (para seguir los acks). */
    public function setMessageExternalId(int $messageId, ?string $externalId, string $status = 'sent'): void
    {
        DB::table('crm_messages')->where('id', $messageId)->update(array_filter([
            'external_id' => $externalId,
            'status'      => $status,
        ], fn($v) => $v !== null));
    }

    /** Aplica un ack (sent|delivered|read|failed) por id de WhatsApp. Devuelve [conversation_id, id] o null. */
    public function applyMessageStatus(string $externalId, string $status): ?array
    {
        $row = DB::table('crm_messages')->where('external_id', $externalId)->where('sender_type', '!=', 'customer')->first(['id', 'conversation_id', 'status']);
        if (!$row) return null;
        $rank = ['pending' => 0, 'sent' => 1, 'delivered' => 2, 'read' => 3, 'failed' => 9];
        if (($rank[$row->status] ?? 0) >= ($rank[$status] ?? 0) && $status !== 'failed') return null; // nunca retroceder
        $update = ['status' => $status];
        if ($status === 'delivered') $update['delivered_at'] = now();
        if ($status === 'read') { $update['read_at'] = now(); $update['delivered_at'] = DB::raw('COALESCE(delivered_at, NOW())'); }
        DB::table('crm_messages')->where('id', $row->id)->update($update);
        return ['conversation_id' => (int)$row->conversation_id, 'id' => (int)$row->id];
    }

    /** Votos de una encuesta: [{voter_key, voter_type, voter_name, options}] */
    public function getPollVotes(int $messageId): array
    {
        return DB::table('crm_poll_votes')->where('message_id', $messageId)->orderBy('updated_at')->get()
            ->map(fn($v) => ['voter_key' => $v->voter_key, 'voter_type' => $v->voter_type, 'voter_name' => $v->voter_name, 'options' => json_decode($v->options, true) ?: []])
            ->values()->toArray();
    }

    public function upsertPollVote(int $messageId, string $voterKey, string $voterType, ?string $voterName, array $options): void
    {
        DB::table('crm_poll_votes')->updateOrInsert(
            ['message_id' => $messageId, 'voter_key' => $voterKey],
            ['voter_type' => $voterType, 'voter_name' => $voterName, 'options' => json_encode(array_values($options)), 'updated_at' => now(), 'created_at' => now()]
        );
    }

    /** Datos mínimos de un mensaje para citarlo (reply). */
    public function findMessageForQuote(int $messageId, int $conversationId): ?array
    {
        $m = DB::table('crm_messages')->where('id', $messageId)->where('conversation_id', $conversationId)->first(['id', 'sender_type', 'content', 'message_type', 'external_id']);
        return $m ? ['id' => (int)$m->id, 'sender_type' => $m->sender_type, 'content' => $m->content, 'message_type' => $m->message_type, 'external_id' => $m->external_id] : null;
    }



    public function markMessageAsSent(int $messageId): void
    {
        DB::table('crm_messages')
            ->where('id', $messageId)
            ->update([
                'status' => 'sent'
            ]);
    }

    public function getAgentsWithLoad(): Collection
    {
        return CrmAgent::query()
            // agente → user
            ->join('users', 'users.id', '=', 'crm_agents.user_id')

            // datos reales del usuario
            ->join('user_data as ud', 'ud.user_id', '=', 'users.id')

            // asignaciones
            ->leftJoin(
                'crm_conversation_assignments as ca',
                'ca.to_user_id',
                '=',
                'users.id'
            )

            // conversaciones activas
            ->leftJoin('crm_conversations as c', function ($join) {
                $join->on('c.id', '=', 'ca.conversation_id')
                    ->where('c.status', '=', 'in_progress');
            })

            ->where('crm_agents.active', 1)

            ->groupBy(
                'crm_agents.id',
                'users.id',
                'ud.names',
                'crm_agents.max_chats'
            )

            ->select(
                'crm_agents.id as agent_id',
                'users.id as user_id',
                'ud.names as name',
                'crm_agents.max_chats',
                DB::raw('COUNT(c.id) as active_chats')
            )

            ->get();
    }

    public function getActiveConversationsByAgent(): Collection
{
    return DB::table('crm_conversation_assignments as ca')
        ->join('crm_conversations as c', function ($join) {
            $join->on('c.id', '=', 'ca.conversation_id')
                 ->where('c.status', '=', 'in_progress');
        })
        ->join('users as u', 'u.id', '=', 'ca.to_user_id')
        ->join('user_data as ud', 'ud.user_id', '=', 'u.id')
        ->join('crm_conversations as conv', 'conv.id', '=', 'ca.conversation_id')
        ->join('crm_customers as cust', 'cust.id', '=', 'conv.customer_id')
        ->select(
            'ca.to_user_id',
            'conv.id as conversation_id',
            'cust.phone as customer_name'
        )
        ->get()
        ->groupBy('to_user_id');
}


    public function getLastAssignment(int $conversationId)
    {
        return DB::table('crm_conversation_assignments')
            ->where('conversation_id', $conversationId)
            ->orderByDesc('created_at')
            ->first();
    }

    public function insertAssignment(array $data): void
    {
        DB::table('crm_conversation_assignments')->insert([
            'conversation_id' => $data['conversation_id'],
            'from_user_id'    => $data['from_user_id'],
            'to_user_id'      => $data['to_user_id'],
            'reason'          => $data['reason'],
            'created_at'      => now(),
        ]);
    }

    public function updateAssignedUser(
        int $conversationId,
        int $userId
    ): void {
        DB::table('crm_conversations')
            ->where('id', $conversationId)
            ->update([
                'assigned_user_id' => $userId,
                'updated_at' => now(),
                'status' => 'in_progress'
            ]);
    }

    /* =====================================================================
     * NOTAS INTERNAS
     * =================================================================== */
    public function getNotes(int $conversationId): array
    {
        return DB::table('crm_notes as n')
            ->leftJoin('user_data as ud', 'ud.user_id', '=', 'n.user_id')
            ->where('n.conversation_id', $conversationId)
            ->orderBy('n.created_at', 'asc')
            ->select([
                'n.id',
                'n.content',
                'n.created_at',
                'n.user_id',
                DB::raw("CONCAT(COALESCE(ud.names,''), ' ', COALESCE(ud.lastname,'')) as agent_name"),
            ])
            ->get()
            ->map(fn($r) => [
                'id'         => (int)$r->id,
                'content'    => $r->content,
                'agent_name' => trim($r->agent_name) ?: 'Agente',
                'created_at' => $r->created_at,
            ])
            ->toArray();
    }

    public function addNote(int $conversationId, int $userId, string $content): array
    {
        $id = DB::table('crm_notes')->insertGetId([
            'conversation_id' => $conversationId,
            'user_id'         => $userId,
            'content'         => $content,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $name = DB::table('user_data')->where('user_id', $userId)
            ->selectRaw("CONCAT(COALESCE(names,''), ' ', COALESCE(lastname,'')) as n")
            ->value('n');

        return [
            'id'         => $id,
            'content'    => $content,
            'agent_name' => trim($name ?? '') ?: 'Agente',
            'created_at' => now()->toDateTimeString(),
        ];
    }

    public function deleteNote(int $noteId): void
    {
        DB::table('crm_notes')->where('id', $noteId)->delete();
    }

    /* =====================================================================
     * ETIQUETAS
     * =================================================================== */
    public function getLabels(int $companyId): array
    {
        return DB::table('crm_labels')
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get()
            ->map(fn($r) => [
                'id'    => (int)$r->id,
                'name'  => $r->name,
                'color' => $r->color,
            ])
            ->toArray();
    }

    public function createLabel(int $companyId, string $name, string $color): array
    {
        $id = DB::table('crm_labels')->insertGetId([
            'company_id' => $companyId,
            'name'       => $name,
            'color'      => $color,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return ['id' => $id, 'name' => $name, 'color' => $color];
    }

    public function deleteLabel(int $labelId): void
    {
        DB::table('crm_conversation_labels')->where('label_id', $labelId)->delete();
        DB::table('crm_labels')->where('id', $labelId)->delete();
    }

    public function getConversationLabels(int $conversationId): array
    {
        return DB::table('crm_conversation_labels as cl')
            ->join('crm_labels as l', 'l.id', '=', 'cl.label_id')
            ->where('cl.conversation_id', $conversationId)
            ->select(['l.id', 'l.name', 'l.color'])
            ->get()
            ->map(fn($r) => ['id' => (int)$r->id, 'name' => $r->name, 'color' => $r->color])
            ->toArray();
    }

    public function addConversationLabel(int $conversationId, int $labelId): void
    {
        DB::table('crm_conversation_labels')->insertOrIgnore([
            'conversation_id' => $conversationId,
            'label_id'        => $labelId,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
    }

    public function removeConversationLabel(int $conversationId, int $labelId): void
    {
        DB::table('crm_conversation_labels')
            ->where('conversation_id', $conversationId)
            ->where('label_id', $labelId)
            ->delete();
    }

    /* =====================================================================
     * PRIORIDAD
     * =================================================================== */
    public function updatePriority(int $conversationId, string $priority): void
    {
        DB::table('crm_conversations')
            ->where('id', $conversationId)
            ->update(['priority' => $priority, 'updated_at' => now()]);
    }

    /* =====================================================================
     * DASHBOARD MÉTRICAS
     * =================================================================== */
    public function getDashboardMetrics(int $companyId): array
    {
        $total      = DB::table('crm_conversations')->where('company_id', $companyId)->count();
        $byStatus   = DB::table('crm_conversations')->where('company_id', $companyId)
            ->selectRaw('status, COUNT(*) as total')->groupBy('status')->get()
            ->pluck('total', 'status')->toArray();

        $today      = DB::table('crm_conversations')
            ->where('company_id', $companyId)
            ->whereDate('created_at', today())->count();

        $byPriority = DB::table('crm_conversations')->where('company_id', $companyId)
            ->selectRaw('priority, COUNT(*) as total')->groupBy('priority')->get()
            ->pluck('total', 'priority')->toArray();

        // Conversaciones por agente
        $byAgent = DB::table('crm_conversations as c')
            ->join('user_data as ud', 'ud.user_id', '=', 'c.assigned_user_id')
            ->where('c.company_id', $companyId)
            ->whereIn('c.status', ['new', 'in_progress'])
            ->selectRaw("CONCAT(COALESCE(ud.names,''), ' ', COALESCE(ud.lastname,'')) as agent_name, COUNT(*) as total")
            ->groupBy('c.assigned_user_id', 'ud.names', 'ud.lastname')
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->map(fn($r) => ['agent' => trim($r->agent_name), 'total' => (int)$r->total])
            ->toArray();

        // Promedio tiempo respuesta (entre primer mensaje cliente y primer respuesta agente) en minutos
        $avgResponse = DB::table('crm_conversations as c')
            ->join('crm_messages as m1', function ($j) {
                $j->on('m1.conversation_id', '=', 'c.id')
                  ->where('m1.sender_type', '=', 'customer');
            })
            ->join('crm_messages as m2', function ($j) {
                $j->on('m2.conversation_id', '=', 'c.id')
                  ->where('m2.sender_type', '=', 'agent');
            })
            ->where('c.company_id', $companyId)
            ->whereRaw('m1.id = (SELECT MIN(id) FROM crm_messages WHERE conversation_id = c.id AND sender_type = "customer")')
            ->whereRaw('m2.id = (SELECT MIN(id) FROM crm_messages WHERE conversation_id = c.id AND sender_type = "agent")')
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, m1.created_at, m2.created_at)) as avg_minutes')
            ->value('avg_minutes');

        // Últimos 7 días
        $last7days = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::today()->subDays($i);
            $count = DB::table('crm_conversations')
                ->where('company_id', $companyId)
                ->whereDate('created_at', $date)
                ->count();
            $last7days[] = [
                'date'  => $date->format('d/m'),
                'total' => $count
            ];
        }

        // Esperando respuesta ahora: abiertas cuyo último mensaje es del cliente
        $waitAlert = (int)(DB::table('crm_settings')->where('company_id', $companyId)->value('wait_alert_minutes') ?? 15);
        $waiting = DB::table('crm_conversations as c')
            ->join('crm_customers as cu', 'cu.id', '=', 'c.customer_id')
            ->join('crm_messages as lm', function ($j) { $j->on('lm.conversation_id', '=', 'c.id'); })
            ->where('c.company_id', $companyId)
            ->whereIn('c.status', ['new', 'in_progress'])
            ->whereRaw('lm.id = (SELECT MAX(id) FROM crm_messages WHERE conversation_id = c.id)')
            ->where('lm.sender_type', 'customer')
            ->selectRaw('c.id, cu.name, cu.phone, TIMESTAMPDIFF(MINUTE, lm.created_at, NOW()) as minutes')
            ->orderByDesc('minutes')
            ->get();
        $waitingOver = $waiting->filter(fn($w) => $w->minutes >= $waitAlert);

        // Tiempo de primera respuesta por agente (últimos 30 días)
        $responseByAgent = DB::table('crm_conversations as c')
            ->join('user_data as ud', 'ud.user_id', '=', 'c.assigned_user_id')
            ->join('crm_messages as m1', function ($j) { $j->on('m1.conversation_id', '=', 'c.id')->where('m1.sender_type', '=', 'customer'); })
            ->join('crm_messages as m2', function ($j) { $j->on('m2.conversation_id', '=', 'c.id')->where('m2.sender_type', '=', 'agent'); })
            ->where('c.company_id', $companyId)
            ->where('c.created_at', '>=', Carbon::now()->subDays(30))
            ->whereRaw('m1.id = (SELECT MIN(id) FROM crm_messages WHERE conversation_id = c.id AND sender_type = "customer")')
            ->whereRaw('m2.id = (SELECT MIN(id) FROM crm_messages WHERE conversation_id = c.id AND sender_type = "agent")')
            ->selectRaw("CONCAT(COALESCE(ud.names,''), ' ', COALESCE(ud.lastname,'')) as agent_name, COUNT(*) as total, AVG(TIMESTAMPDIFF(MINUTE, m1.created_at, m2.created_at)) as avg_minutes")
            ->groupBy('c.assigned_user_id', 'ud.names', 'ud.lastname')
            ->orderBy('avg_minutes')
            ->limit(10)
            ->get()
            ->map(fn($r) => ['agent' => trim($r->agent_name), 'total' => (int)$r->total, 'avg_minutes' => round((float)$r->avg_minutes, 1)])
            ->toArray();

        $byLabel = DB::table('crm_conversation_labels as cl')
            ->join('crm_labels as l', 'l.id', '=', 'cl.label_id')
            ->join('crm_conversations as c', 'c.id', '=', 'cl.conversation_id')
            ->where('c.company_id', $companyId)
            ->whereIn('c.status', ['new', 'in_progress'])
            ->selectRaw('l.id, l.name, l.color, COUNT(*) as total')
            ->groupBy('l.id', 'l.name', 'l.color')->orderByDesc('total')->get()
            ->map(fn($r) => ['id' => (int)$r->id, 'name' => $r->name, 'color' => $r->color, 'total' => (int)$r->total])->toArray();

        $closedToday = DB::table('crm_conversations')->where('company_id', $companyId)->where('status', 'closed')->whereDate('updated_at', today())->count();
        $messagesToday = DB::table('crm_messages as m')->join('crm_conversations as c', 'c.id', '=', 'm.conversation_id')
            ->where('c.company_id', $companyId)->whereDate('m.created_at', today())
            ->selectRaw("SUM(m.sender_type = 'customer') as inbound, SUM(m.sender_type = 'agent') as outbound")->first();

        return [
            'total'           => $total,
            'today'           => $today,
            'closed_today'    => $closedToday,
            'messages_today'  => ['inbound' => (int)($messagesToday->inbound ?? 0), 'outbound' => (int)($messagesToday->outbound ?? 0)],
            'by_status'       => $byStatus,
            'by_priority'     => $byPriority,
            'by_agent'        => $byAgent,
            'by_label'        => $byLabel,
            'avg_response_minutes' => round((float)($avgResponse ?? 0), 1),
            'response_by_agent'    => $responseByAgent,
            'waiting'         => [
                'alert_minutes' => $waitAlert,
                'total'         => $waiting->count(),
                'over_alert'    => $waitingOver->count(),
                'list'          => $waitingOver->take(8)->map(fn($w) => ['id' => (int)$w->id, 'name' => $w->name ?: $w->phone, 'phone' => $w->phone, 'minutes' => (int)$w->minutes])->values()->toArray(),
            ],
            'last_7_days'     => $last7days,
        ];
    }

    /* =====================================================================
     * BROADCAST
     * =================================================================== */
    public function getCustomersForBroadcast(int $companyId): array
    {
        // Nombre del CRM o, si está vacío, el del cliente del ISP (mismos últimos 10 dígitos) + plan y estado
        return DB::table('crm_customers as cu')
            ->leftJoin('user_data as ud', function ($j) use ($companyId) {
                $j->on(DB::raw("RIGHT(REGEXP_REPLACE(cu.phone, '[^0-9]', ''), 10)"), '=', DB::raw("RIGHT(REGEXP_REPLACE(ud.phone, '[^0-9]', ''), 10)"))
                  ->where('ud.company_id', '=', $companyId);
            })
            ->leftJoin('internet_plans as ip', 'ip.id', '=', 'ud.internet_plans_id')
            ->leftJoin('internet_status as ist', 'ist.id', '=', 'ud.status_internet_id')
            ->where('cu.company_id', $companyId)
            ->orderByRaw("COALESCE(NULLIF(cu.name,''), CONCAT(ud.names,' ',ud.lastname), cu.phone)")
            ->select(['cu.id', 'cu.phone', 'cu.name', 'ud.names', 'ud.lastname', 'ip.plan_name', 'ist.name as service_status'])
            ->get()
            ->unique('id')
            ->map(fn($r) => [
                'id'     => (int)$r->id,
                'phone'  => $r->phone,
                'name'   => trim($r->name ?: trim(($r->names ?? '') . ' ' . ($r->lastname ?? ''))) ?: null,
                'linked' => !empty($r->names),
                'plan'   => $r->plan_name,
                'status' => $r->service_status,
            ])
            ->values()
            ->toArray();
    }

    /* =====================================================================
     * NUEVA CONVERSACIÓN DESDE TELÉFONO
     * =================================================================== */
    public function createConversationFromPhone(string $phone, string $name, int $companyId, string $provider = 'netplay'): int
    {
        // limpiar teléfono
        $phone = preg_replace('/[^0-9+]/', '', $phone);

        // buscar o crear cliente
        $customer = DB::table('crm_customers')
            ->where('phone', $phone)
            ->where('company_id', $companyId)
            ->first();

        if (!$customer) {
            $customerId = DB::table('crm_customers')->insertGetId([
                'company_id' => $companyId,
                'phone'      => $phone,
                'name'       => $name ?: $phone,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $customerId = $customer->id;
        }

        // crear conversación nueva
        return (int) DB::table('crm_conversations')->insertGetId([
            'company_id'     => $companyId,
            'provider'       => $provider,
            'customer_id'    => $customerId,
            'status'         => 'new',
            'priority'       => 'normal',
            'last_message_at'=> now(),
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
    }

    /* =====================================================================
     * ESTADO DE SERVICIO DEL CLIENTE
     * =================================================================== */
    public function getServiceStatusByPhone(string $phone): ?array
    {
        // limpiar teléfono (últimos 10 dígitos para buscar en user_data)
        $clean = preg_replace('/[^0-9]/', '', $phone);
        $last10 = substr($clean, -10);

        // user_data guarda plan, estado e IP directamente (internet_plans_id, status_internet_id, ip_assignment_id → tabla_ips)
        $user = DB::table('user_data as ud')
            ->leftJoin('internet_plans as ip', 'ip.id', '=', 'ud.internet_plans_id')
            ->leftJoin('internet_status as ist', 'ist.id', '=', 'ud.status_internet_id')
            ->leftJoin('tabla_ips as tip', 'tip.id', '=', 'ud.ip_assignment_id')
            ->where(function ($q) use ($clean, $last10) {
                $q->where('ud.phone', 'like', '%' . $last10)
                  ->orWhere('ud.phone', $clean);
            })
            ->orderByDesc('ud.id')
            ->select([
                'ud.names',
                'ud.lastname',
                'ud.address',
                'tip.ip',
                DB::raw("COALESCE(ip.plan_name, 'Sin plan') as plan_name"),
                DB::raw("COALESCE(ist.name, 'Desconocido') as service_status"),
            ])
            ->first();

        if (!$user) return null;

        return [
            'name'           => trim(($user->names ?? '') . ' ' . ($user->lastname ?? '')),
            'address'        => $user->address ?? null,
            'ip'             => $user->ip ?? null,
            'plan'           => $user->plan_name,
            'service_status' => $user->service_status,
        ];
    }

    /* =====================================================================
     * STICKERS
     * =================================================================== */
    public function getStickers(int $companyId): array
    {
        return DB::table('crm_stickers')
            ->where('company_id', $companyId)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn($r) => [
                'id'        => (int)$r->id,
                'media_url' => $r->media_url,
                'name'      => $r->name,
            ])
            ->toArray();
    }

    public function saveSticker(int $companyId, string $mediaUrl, ?string $name): array
    {
        $id = DB::table('crm_stickers')->insertGetId([
            'company_id' => $companyId,
            'media_url'  => $mediaUrl,
            'name'       => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return ['id' => $id, 'media_url' => $mediaUrl, 'name' => $name];
    }

    public function deleteSticker(int $stickerId): void
    {
        DB::table('crm_stickers')->where('id', $stickerId)->delete();
    }

    /* =====================================================================
     * RESPUESTAS RÁPIDAS ("/atajo" en el compositor)
     * =================================================================== */
    public function getQuickReplies(int $companyId): array
    {
        return DB::table('crm_quick_replies')
            ->where('company_id', $companyId)
            ->orderBy('shortcut')
            ->get()
            ->map(fn($r) => [
                'id'       => (int)$r->id,
                'shortcut' => $r->shortcut,
                'title'    => $r->title,
                'content'  => $r->content,
            ])
            ->toArray();
    }

    public function saveQuickReply(int $companyId, ?int $id, string $shortcut, ?string $title, string $content, ?int $userId): array
    {
        $shortcut = strtolower(ltrim(trim($shortcut), '/'));
        $data = [
            'shortcut'   => $shortcut,
            'title'      => $title,
            'content'    => $content,
            'updated_at' => now(),
        ];

        if ($id) {
            DB::table('crm_quick_replies')->where('company_id', $companyId)->where('id', $id)->update($data);
        } else {
            $id = DB::table('crm_quick_replies')->insertGetId($data + [
                'company_id' => $companyId,
                'created_by' => $userId,
                'created_at' => now(),
            ]);
        }

        return ['id' => (int)$id, 'shortcut' => $shortcut, 'title' => $title, 'content' => $content];
    }

    public function deleteQuickReply(int $companyId, int $id): void
    {
        DB::table('crm_quick_replies')->where('company_id', $companyId)->where('id', $id)->delete();
    }
}

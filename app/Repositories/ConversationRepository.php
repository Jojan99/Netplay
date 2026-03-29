<?php

namespace App\Repositories;

use App\Repositories\Interfaces\ConversationRepositoryInterface;
use Illuminate\Support\Facades\DB;
use App\Models\CrmMessage;
use App\Models\CrmAgent;
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



        // Subquery: último mensaje por conversación
        $lastMsgSub = DB::table('crm_messages as m1')
            ->select(
                'm1.conversation_id',
                'm1.content',
                'm1.message_type',
                'm1.media_url',
                'm1.mime_type',     // 🔥 AÑADIR ESTOMessages retrieved successfully
                'm1.created_at'
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
            ->select([
                'c.id',
                'c.status',
                'c.priority',
                'c.assigned_user_id',
                'c.created_at',

                'cu.id as customer_id',
                'cu.name as customer_name',
                'cu.phone as customer_phone',

                DB::raw("
        CASE 
            WHEN lm.message_type = 'text' THEN lm.content
            WHEN lm.message_type = 'image' THEN '📷 Imagen'
            WHEN lm.message_type = 'audio' THEN '🎤 Audio'
            WHEN lm.message_type = 'video' THEN '🎥 Video'
            ELSE 'Mensaje'
        END as last_message_content
    "),

                'lm.message_type as last_message_type',
                'lm.media_url as last_message_media_url',
                'lm.created_at as last_message_at',

                'ud.names as assigned_names',
                'ud.lastname as assigned_lastname',
            ])

            ->when($status && $status !== 'all', function ($qq) use ($status) {
                $qq->where('c.status', $status);
            })

            ->when($search, function ($qq) use ($search) {
                $s = '%' . $search . '%';
                $qq->where(function ($w) use ($s) {
                    $w->where('cu.name', 'like', $s)
                        ->orWhere('cu.phone', 'like', $s);
                });
            })
            ->when($userId && $status !== 'all', function ($qq) use ($userId, $status) {

                // 🆕 NEW → visibles para todos
                if ($status === 'new') {
                    return;
                }

                // 🔄 IN PROGRESS → solo asignadas
                if ($status === 'in_progress') {
                    $qq->where('c.assigned_user_id', $userId);
                }

                // CLOSED → visibles para todos
                if ($status === 'closed') {
                    return;
                }
            })


            ->orderByRaw('COALESCE(lm.created_at, c.created_at) DESC')
            ->limit($limit);

        return $q->get()->map(function ($row) {
            return [
                'id' => (int)$row->id,
                'status' => $row->status,
                'priority' => $row->priority,
                'assigned_user_id' => $row->assigned_user_id ? (int)$row->assigned_user_id : null,

                'customer' => [
                    'id' => (int)$row->customer_id,
                    'name' => $row->customer_name,
                    'phone' => $row->customer_phone,
                ],

                'last_message' => $row->last_message_type ? [
                    'content'   => $row->last_message_content,
                    'type'      => $row->last_message_type,
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
                'cu.name as customer_name',
                'cu.phone',
                'c.status',
                'c.priority',
            ])
            ->first();


        if (!$conversation) {
            throw new \Exception('Conversation not found');
        }

        // 2️⃣ Obtener mensajes
        $messages = DB::table('crm_messages')
            ->where('conversation_id', $conversationId)
            ->orderBy('created_at', 'asc')
            ->select([
                'id',
                'sender_type',
                'content',
                'message_type',
                'media_url',
                'mime_type',     // 🔥 AÑADIR ESTO
                'created_at',
            ])
            ->get()
            ->map(function ($row) {
                return [
                    'id' => (int) $row->id,
                    'sender_type' => $row->sender_type,
                    'content' => $row->content,
                    'message_type' => $row->message_type,
                    'media_url' => $row->media_url,
                    'mime_type' => $row->mime_type,
                    'created_at' => $row->created_at,
                ];
            })
            ->toArray();


        // 3️⃣ Response completo (lo que Angular espera)
        return [
            'message' => 'Messages retrieved successfully',
            'conversation' => [
                'id' => (int) $conversation->id,
                'customer_name' => $conversation->customer_name,
                'phone' => $conversation->phone,
                'status' => $conversation->status,
                'priority' => $conversation->priority,
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

    public function getOrCreateConversationByPhone(string $phone, string $names): int
    {

        // 1) Buscar conversación ACTIVA (no cerrada) para ese teléfono
        $activeConversation = DB::table('crm_conversations as c')
            ->join('crm_customers as cu', 'cu.id', '=', 'c.customer_id')
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
                'phone'      => $phone,
                'name'       => $names,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        Log::info('Customer creado o encontrado', ['customerId' => $customerId]);

        

        // 3) Crear nueva conversación (porque la anterior estaba cerrada o no existía)
        return (int) DB::table('crm_conversations')->insertGetId([
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
            'conversation_id' => $data['conversation_id'],
            'sender_user_id'  => $data['sender_user_id'] ?? null,
            'sender_type'     => $data['sender_type'],
            'content'         => $data['content'],
            'message_type'    => $data['message_type'] ?? 'text',
        ]);
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
}

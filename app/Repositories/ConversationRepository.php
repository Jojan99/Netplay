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
            ->where('c.company_id', getSessionCompanyId())
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
            // Solo filtrar por agente cuando se pide explícitamente 'mine'
            ->when(!empty($filters['mine']) && $userId, function ($qq) use ($userId) {
                $qq->where(function ($w) use ($userId) {
                    $w->where('c.assigned_user_id', $userId)
                      ->orWhereNull('c.assigned_user_id');
                });
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

        return [
            'total'           => $total,
            'today'           => $today,
            'by_status'       => $byStatus,
            'by_priority'     => $byPriority,
            'by_agent'        => $byAgent,
            'avg_response_minutes' => round((float)($avgResponse ?? 0), 1),
            'last_7_days'     => $last7days,
        ];
    }

    /* =====================================================================
     * BROADCAST
     * =================================================================== */
    public function getCustomersForBroadcast(int $companyId): array
    {
        return DB::table('crm_customers')
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->select(['id', 'phone', 'name'])
            ->get()
            ->map(fn($r) => ['id' => (int)$r->id, 'phone' => $r->phone, 'name' => $r->name])
            ->toArray();
    }

    /* =====================================================================
     * NUEVA CONVERSACIÓN DESDE TELÉFONO
     * =================================================================== */
    public function createConversationFromPhone(string $phone, string $name, int $companyId): int
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

        $user = DB::table('user_data as ud')
            ->leftJoin('conection_routers as cr', 'cr.user_id', '=', 'ud.user_id')
            ->leftJoin('internet_plans as ip', 'ip.id', '=', 'cr.plan_id')
            ->leftJoin('internet_status as ist', 'ist.id', '=', 'cr.status_id')
            ->where(function ($q) use ($clean, $last10) {
                $q->where('ud.phone', 'like', '%' . $last10)
                  ->orWhere('ud.phone', $clean);
            })
            ->select([
                'ud.names',
                'ud.lastname',
                'ud.address',
                'cr.ip',
                DB::raw("COALESCE(ip.name, 'Sin plan') as plan_name"),
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
}

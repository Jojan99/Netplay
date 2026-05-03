<?php

namespace App\Repositories;

use App\Http\Requests\Ticket\CreateTicketRequest;
use App\Http\Requests\Ticket\TicketRequest;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\TypePriority;
use App\Models\TypeService;
use App\Repositories\Interfaces\TicketRepositoryInterface;
use Illuminate\Support\Facades\DB;

class TicketRepository implements TicketRepositoryInterface
{
    public function getTypePriorityAll(): mixed
    {
        return TypePriority::where('active', 1)->get();
    }

    public function getTypeServiceAll(): mixed
    {
        return TypeService::where('active', 1)->get();
    }

    public function getTechnicaAll(): mixed
    {
        $companyId = getSessionCompanyId();
        return DB::table('user_data as us')
            ->join('users', 'users.id', '=', 'us.user_id')
            ->whereIn('users.profile_id', function ($q) use ($companyId) {
                $q->select('id')->from('profiles')
                    ->where('company_id', $companyId)
                    ->where('name', 'TECNICO');
            })
            ->where('users.company_id', $companyId)
            ->select('us.*')
            ->get();
    }

    public function createTicket(CreateTicketRequest $data): int
    {
        $ticket = Ticket::create([
            'company_id'      => getSessionCompanyId(),
            'user_id'         => $data->user_id,
            'address'         => $data->address,
            'date'            => $data->date,
            'service_id'      => $data->type_service,
            'priority_id'     => $data->priority,
            'status_id'       => 1,
            'technical_id'    => $data->tecnichal,
            'observation'     => $data->observation,
            'cedula'          => $data->cedula,
            'phone'           => $data->phone,
            'user_created_id' => $data->log_id,
            'reopened_count'  => 0,
        ]);

        return $ticket->id;
    }

    public function getTicketInProgressAll($status): mixed
    {
        return Ticket::select(
            'ticket_status.name as status',
            'user_data_user.names as user_names',
            'user_data_user.lastname as user_lastname',
            'ticket_type_prioritys.name as prioritys',
            'ticket_type_services.name as service',
            'tickets.address',
            'tickets.id',
            'tickets.date',
            'tickets.phone',
            'tickets.cedula',
            'tickets.created_at',
            'tickets.observation',
            'tickets.started_at',
            'tickets.finished_at',
            'tickets.closed_at',
            'tickets.reopened_count',
            'tickets.technical_id',
            'user_data_tech.names as tech_names',
            'user_data_tech.lastname as tech_lastname'
        )
        ->join('ticket_status', 'ticket_status.id', '=', 'tickets.status_id')
        ->join('user_data as user_data_user', 'user_data_user.user_id', '=', 'tickets.user_id')
        ->join('user_data as user_data_tech', 'user_data_tech.user_id', '=', 'tickets.technical_id')
        ->join('ticket_type_prioritys', 'ticket_type_prioritys.id', '=', 'tickets.priority_id')
        ->join('ticket_type_services', 'ticket_type_services.id', '=', 'tickets.service_id')
        ->where('tickets.status_id', $status->status)
        ->where('tickets.company_id', getSessionCompanyId())
        ->where(function ($q) {
            $q->where('tickets.technical_id', getSessionUserId())
              ->orWhereRaw(getSessionUserProfileId() . ' = 2');
        })
        ->orderBy('ticket_type_prioritys.id', 'asc')
        ->get();
    }

    public function getAllTickets(array $filters): mixed
    {
        $query = Ticket::select(
            'ticket_status.name as status',
            'ticket_status.id as status_id',
            'user_data_user.names as user_names',
            'user_data_user.lastname as user_lastname',
            'ticket_type_prioritys.name as prioritys',
            'ticket_type_prioritys.id as priority_id',
            'ticket_type_services.name as service',
            'tickets.address',
            'tickets.id',
            'tickets.date',
            'tickets.phone',
            'tickets.cedula',
            'tickets.created_at',
            'tickets.updated_at',
            'tickets.observation',
            'tickets.started_at',
            'tickets.finished_at',
            'tickets.closed_at',
            'tickets.reopened_count',
            'tickets.technical_id',
            'tickets.user_id',
            'user_data_tech.names as tech_names',
            'user_data_tech.lastname as tech_lastname'
        )
        ->join('ticket_status', 'ticket_status.id', '=', 'tickets.status_id')
        ->join('user_data as user_data_user', 'user_data_user.user_id', '=', 'tickets.user_id')
        ->join('user_data as user_data_tech', 'user_data_tech.user_id', '=', 'tickets.technical_id')
        ->join('ticket_type_prioritys', 'ticket_type_prioritys.id', '=', 'tickets.priority_id')
        ->join('ticket_type_services', 'ticket_type_services.id', '=', 'tickets.service_id')
        ->where('tickets.company_id', getSessionCompanyId());

        // Role filter: technicians only see their own, admins see all
        if (getSessionUserProfileId() != 2) {
            $query->where('tickets.technical_id', getSessionUserId());
        }

        if (!empty($filters['status_id'])) {
            $query->where('tickets.status_id', $filters['status_id']);
        }
        if (!empty($filters['technical_id'])) {
            $query->where('tickets.technical_id', $filters['technical_id']);
        }
        if (!empty($filters['search'])) {
            $s = $filters['search'];
            $query->where(function ($q) use ($s) {
                $q->where('user_data_user.names', 'like', "%{$s}%")
                  ->orWhere('user_data_user.lastname', 'like', "%{$s}%")
                  ->orWhere('tickets.cedula', 'like', "%{$s}%")
                  ->orWhere('tickets.address', 'like', "%{$s}%");
            });
        }

        return $query->orderBy('tickets.created_at', 'desc')->get();
    }

    public function getTicketById(int $id): mixed
    {
        return Ticket::select(
            'ticket_status.name as status',
            'ticket_status.id as status_id',
            'user_data_user.names as user_names',
            'user_data_user.lastname as user_lastname',
            'ticket_type_prioritys.name as prioritys',
            'ticket_type_prioritys.id as priority_id',
            'ticket_type_services.name as service',
            'ticket_type_services.id as service_id',
            'tickets.address',
            'tickets.id',
            'tickets.date',
            'tickets.phone',
            'tickets.cedula',
            'tickets.created_at',
            'tickets.updated_at',
            'tickets.observation',
            'tickets.started_at',
            'tickets.finished_at',
            'tickets.closed_at',
            'tickets.reopened_count',
            'tickets.technical_id',
            'tickets.user_id',
            'user_data_tech.names as tech_names',
            'user_data_tech.lastname as tech_lastname'
        )
        ->join('ticket_status', 'ticket_status.id', '=', 'tickets.status_id')
        ->join('user_data as user_data_user', 'user_data_user.user_id', '=', 'tickets.user_id')
        ->join('user_data as user_data_tech', 'user_data_tech.user_id', '=', 'tickets.technical_id')
        ->join('ticket_type_prioritys', 'ticket_type_prioritys.id', '=', 'tickets.priority_id')
        ->join('ticket_type_services', 'ticket_type_services.id', '=', 'tickets.service_id')
        ->where('tickets.id', $id)
        ->where('tickets.company_id', getSessionCompanyId())
        ->first();
    }

    public function getTicketsByUser(int $userId): mixed
    {
        return Ticket::select(
            'ticket_status.name as status',
            'ticket_type_prioritys.name as prioritys',
            'ticket_type_services.name as service',
            'tickets.address',
            'tickets.id',
            'tickets.date',
            'tickets.phone',
            'tickets.cedula',
            'tickets.created_at',
            'tickets.observation',
            'tickets.started_at',
            'tickets.finished_at',
            'tickets.reopened_count',
            'user_data_tech.names as tech_names',
            'user_data_tech.lastname as tech_lastname'
        )
        ->join('ticket_status', 'ticket_status.id', '=', 'tickets.status_id')
        ->join('user_data as user_data_tech', 'user_data_tech.user_id', '=', 'tickets.technical_id')
        ->join('ticket_type_prioritys', 'ticket_type_prioritys.id', '=', 'tickets.priority_id')
        ->join('ticket_type_services', 'ticket_type_services.id', '=', 'tickets.service_id')
        ->where('tickets.user_id', $userId)
        ->where('tickets.company_id', getSessionCompanyId())
        ->orderBy('tickets.created_at', 'desc')
        ->get();
    }

    public function updateTicket(TicketRequest $data): mixed
    {
        $colum = null;
        $time  = null;

        switch ($data['status']) {
            case 1:
                $data['status'] = 2;
                $time  = now();
                $colum = 'started_at';
                break;
            case 2:
                $data['status'] = 3;
                $time  = now();
                $colum = 'finished_at';
                break;
        }

        $ticket = Ticket::where('id', $data['id'])
            ->where('company_id', getSessionCompanyId())
            ->first();

        if ($ticket && $colum) {
            $updates = ['status_id' => $data['status'], $colum => $time];
            if ($data['status'] == 3) {
                $updates['closed_at'] = now();
            }
            $ticket->update($updates);
        }

        return true;
    }

    public function addNote(int $ticketId, int $userId, string $note, array $photos, ?string $audioPath): mixed
    {
        return TicketNote::create([
            'ticket_id'  => $ticketId,
            'company_id' => getSessionCompanyId(),
            'user_id'    => $userId,
            'note'       => $note,
            'photos'     => !empty($photos) ? $photos : null,
            'audio_path' => $audioPath,
        ]);
    }

    public function getNotesByTicket(int $ticketId): mixed
    {
        return TicketNote::select(
            'ticket_notes.*',
            'user_data.names as author_names',
            'user_data.lastname as author_lastname'
        )
        ->join('user_data', 'user_data.user_id', '=', 'ticket_notes.user_id')
        ->where('ticket_notes.ticket_id', $ticketId)
        ->where('ticket_notes.company_id', getSessionCompanyId())
        ->orderBy('ticket_notes.created_at', 'asc')
        ->get();
    }

    public function closeTicket(int $id, string $description): bool
    {
        $ticket = Ticket::where('id', $id)
            ->where('company_id', getSessionCompanyId())
            ->where('status_id', 2)
            ->where(function ($q) {
                $q->where('technical_id', getSessionUserId())
                  ->orWhereRaw(getSessionUserProfileId() . ' = 2');
            })
            ->first();

        if (!$ticket) return false;

        $ticket->update([
            'status_id'   => 3,
            'finished_at' => now(),
            'closed_at'   => now(),
        ]);

        TicketNote::create([
            'ticket_id'  => $id,
            'company_id' => getSessionCompanyId(),
            'user_id'    => getSessionUserId(),
            'note'       => '✅ Cierre: ' . $description,
            'photos'     => null,
            'audio_path' => null,
        ]);

        return true;
    }

    public function reopenTicket(int $ticketId, string $reason): bool
    {
        $ticket = Ticket::where('id', $ticketId)
            ->where('company_id', getSessionCompanyId())
            ->where('status_id', 3)
            ->first();

        if (!$ticket) return false;

        $ticket->update([
            'status_id'      => 1,
            'finished_at'    => null,
            'closed_at'      => null,
            'reopened_count' => $ticket->reopened_count + 1,
        ]);

        TicketNote::create([
            'ticket_id'  => $ticketId,
            'company_id' => getSessionCompanyId(),
            'user_id'    => getSessionUserId(),
            'note'       => '🔁 Reapertura: ' . $reason,
            'photos'     => null,
            'audio_path' => null,
        ]);

        return true;
    }

    public function deleteTicket(int $id): bool
    {
        $deleted = Ticket::where('id', $id)
            ->where('company_id', getSessionCompanyId())
            ->delete();

        return $deleted > 0;
    }

    public function getTicketsSince(string $since): mixed
    {
        return Ticket::select(
            'ticket_status.name as status',
            'ticket_status.id as status_id',
            'user_data_user.names as user_names',
            'user_data_user.lastname as user_lastname',
            'ticket_type_prioritys.name as prioritys',
            'ticket_type_prioritys.id as priority_id',
            'ticket_type_services.name as service',
            'tickets.address',
            'tickets.id',
            'tickets.date',
            'tickets.phone',
            'tickets.cedula',
            'tickets.created_at',
            'tickets.updated_at',
            'tickets.observation',
            'tickets.started_at',
            'tickets.finished_at',
            'tickets.closed_at',
            'tickets.reopened_count',
            'tickets.technical_id',
            'tickets.user_id',
            'user_data_tech.names as tech_names',
            'user_data_tech.lastname as tech_lastname'
        )
        ->join('ticket_status', 'ticket_status.id', '=', 'tickets.status_id')
        ->join('user_data as user_data_user', 'user_data_user.user_id', '=', 'tickets.user_id')
        ->join('user_data as user_data_tech', 'user_data_tech.user_id', '=', 'tickets.technical_id')
        ->join('ticket_type_prioritys', 'ticket_type_prioritys.id', '=', 'tickets.priority_id')
        ->join('ticket_type_services', 'ticket_type_services.id', '=', 'tickets.service_id')
        ->where('tickets.company_id', getSessionCompanyId())
        ->where('tickets.updated_at', '>', $since)
        ->orderBy('tickets.updated_at', 'desc')
        ->get();
    }

    public function reassignTicket(int $ticketId, int $techId): bool
    {
        $ticket = Ticket::where('id', $ticketId)
            ->where('company_id', getSessionCompanyId())
            ->first();

        if (!$ticket) return false;

        $updates = ['technical_id' => $techId];

        // If in progress, reset to pending and clear timer
        if ($ticket->status_id === 2) {
            $updates['status_id']   = 1;
            $updates['started_at']  = null;
        }

        $ticket->update($updates);

        return true;
    }

    public function getStats(): mixed
    {
        $companyId = getSessionCompanyId();

        $total = Ticket::where('company_id', $companyId)->count();

        $pending = Ticket::where('company_id', $companyId)
            ->where('status_id', 1)->count();

        $inProgress = Ticket::where('company_id', $companyId)
            ->where('status_id', 2)->count();

        $closed = Ticket::where('company_id', $companyId)
            ->where('status_id', 3)->count();

        $closedToday = Ticket::where('company_id', $companyId)
            ->where('status_id', 3)
            ->whereDate('closed_at', today())
            ->count();

        $reopened = Ticket::where('company_id', $companyId)
            ->where('reopened_count', '>', 0)->count();

        $reopenPct = $total > 0 ? round(($reopened / $total) * 100, 1) : 0;

        $topTechnicians = DB::table('tickets as t')
            ->join('user_data as ud', 'ud.user_id', '=', 't.technical_id')
            ->where('t.company_id', $companyId)
            ->where('t.status_id', 2)
            ->select('t.technical_id', 'ud.names', 'ud.lastname', DB::raw('COUNT(*) as count'))
            ->groupBy('t.technical_id', 'ud.names', 'ud.lastname')
            ->orderByDesc('count')
            ->limit(10)
            ->get();

        $topClients = DB::table('tickets as t')
            ->join('user_data as ud', 'ud.user_id', '=', 't.user_id')
            ->where('t.company_id', $companyId)
            ->select('t.user_id', 'ud.names', 'ud.lastname', DB::raw('COUNT(*) as count'))
            ->groupBy('t.user_id', 'ud.names', 'ud.lastname')
            ->orderByDesc('count')
            ->limit(10)
            ->get();

        return [
            'total'           => $total,
            'pending'         => $pending,
            'in_progress'     => $inProgress,
            'closed'          => $closed,
            'closed_today'    => $closedToday,
            'reopen_pct'      => $reopenPct,
            'top_technicians' => $topTechnicians,
            'top_clients'     => $topClients,
        ];
    }
}

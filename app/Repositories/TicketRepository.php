<?php

namespace App\Repositories;

use App\Http\Requests\Ticket\CreateTicketRequest;
use App\Http\Requests\Ticket\TicketRequest;
use App\Models\Ticket;
use App\Models\TypePriority;
use App\Models\TypeService;
use App\Repositories\Interfaces\TicketRepositoryInterface;
use Illuminate\Support\Facades\DB;

use function Laravel\Prompts\error;

class TicketRepository implements TicketRepositoryInterface
{
    /**
     * @return mixed
     */
    public function getTypePriorityAll(): mixed
    {

        return TypePriority::where('active', 1)->get();
    }

     /**
     * @return mixed
     */
    public function getTypeServiceAll(): mixed
    {

        return TypeService::where('active', 1)->get();
    }

    /**
     * @return mixed
     */
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
        'user_data_tech.names as tech_names', 
        'user_data_tech.lastname as tech_lastname'
    )
    ->join('ticket_status', 'ticket_status.id', '=', 'tickets.status_id')
    ->join('user_data as user_data_user', 'user_data_user.user_id', '=', 'tickets.user_id')
    ->join('user_data as user_data_tech', 'user_data_tech.user_id', '=', 'tickets.technical_id')
    ->join('ticket_type_prioritys', 'ticket_type_prioritys.id', '=', 'tickets.priority_id')
    ->join('ticket_type_services', 'ticket_type_services.id', '=', 'tickets.service_id')
    ->where('tickets.status_id', $status->status)
    ->where(function ($q) {
        $q->where('tickets.technical_id', getSessionUserId())
          ->orWhereRaw(getSessionUserProfileId() . ' = 2');
    })
    ->orderBy('ticket_type_prioritys.id', 'asc')
    ->get();
}


    
    /**
     * @return mixed
     */
    public function getTechnicaAll(): mixed
    {
        return $userData = DB::table('user_data as us')
        ->join('type_role as tr', 'tr.id', '=', 'us.role_id')
        ->where('tr.id', 1)
        ->get();
    }


     /**
 * @param CreateTicketRequest $data
 * @return int
 */
public function createTicket(CreateTicketRequest $data): int
{
    $ticket = Ticket::create([
        'user_id' => $data->user_id,
        'address' => $data->address,
        'date' => $data->date,
        'service_id' => $data->type_service,
        'priority_id' => $data->priority,
        'status_id' => 1,
        'technical_id' => $data->tecnichal,
        'observation' => $data->observation,
        'cedula' => $data->cedula,
        'phone' => $data->phone,
        'user_created_id' => $data->log_id
    ]);

    return $ticket->id; // 👈 AQUÍ sale el id
}



     /**
     * @param TicketRequest $data
     * @return mixed
     */
    public function updateTicket(TicketRequest $data): mixed
    {

        switch ($data['status']) {
            case 1:
                $data['status'] = 2;
              $time =  $data['started_at'] = now();
              $colum = 'started_at';
                break;
            case 2:
                $data['status'] = 3;
                $time = $data['finished_at'] = now();
                $colum = 'finished_at';


                break;
            default:
                // Puedes agregar algún valor predeterminado si lo necesitas
                break;
        }


        $ticket = Ticket::where('id', $data['id'])->first();
        if ($ticket) $ticket->update([
            'status_id' => $data['status'],
             $colum => $time,
            
        ]);

        return true;
    }

}

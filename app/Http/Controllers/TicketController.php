<?php

namespace App\Http\Controllers;

use App\Constants\ApiResponseConstants;
use App\Http\Requests\Ticket\CreateTicketRequest;
use App\Http\Requests\Ticket\TicketRequest;
use App\UseCases\Ticket\Interfaces\CreateTicketUseCaseInterface;
use App\UseCases\Ticket\Interfaces\GetTechnicalUseCaseInterface;
use App\UseCases\Ticket\Interfaces\GetTicketInProgressAllUseCaseInterface;
use App\UseCases\Ticket\Interfaces\GetTypePriorityUseCaseInterface;
use App\UseCases\Ticket\Interfaces\GetTypeServiceUseCaseInterface;
use App\UseCases\Ticket\Interfaces\UpdateTicketUseCaseInterface;
use App\Repositories\Interfaces\TicketRepositoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Tymon\JWTAuth\Exceptions\JWTException;


class TicketController extends Controller
{
    public function getTypeServiceAll(GetTypeServiceUseCaseInterface $uc): object
    {
        try {
            $result = $uc->getTypeServiceAll();
        } catch (JWTException $e) {
            return standardApiReponse($e->getMessage(), null, ApiResponseConstants::ERROR, JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
        return standardApiReponse($result['message'], $result['data'], $result['status'], JsonResponse::HTTP_OK);
    }

    public function getTypePriorityAll(GetTypePriorityUseCaseInterface $uc): object
    {
        try {
            $result = $uc->getTypePriorityAll();
        } catch (JWTException $e) {
            return standardApiReponse($e->getMessage(), null, ApiResponseConstants::ERROR, JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
        return standardApiReponse($result['message'], $result['data'], $result['status'], JsonResponse::HTTP_OK);
    }

    public function getTechnicaAll(GetTechnicalUseCaseInterface $uc): object
    {
        try {
            $result = $uc->getTechnicaAll();
        } catch (JWTException $e) {
            return standardApiReponse($e->getMessage(), null, ApiResponseConstants::ERROR, JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
        return standardApiReponse($result['message'], $result['data'], $result['status'], JsonResponse::HTTP_OK);
    }

    public function createTicket(CreateTicketRequest $req, CreateTicketUseCaseInterface $uc): object
    {
        try {
            $result = $uc->createTicket($req);
        } catch (JWTException $e) {
            return standardApiReponse($e->getMessage(), null, ApiResponseConstants::ERROR, JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
        return standardApiReponse($result['message'], $result['data'], $result['status'], JsonResponse::HTTP_OK);
    }

    public function updateTicket(TicketRequest $req, UpdateTicketUseCaseInterface $uc): object
    {
        try {
            $result = $uc->updateTicket($req);
        } catch (JWTException $e) {
            return standardApiReponse($e->getMessage(), null, ApiResponseConstants::ERROR, JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
        return standardApiReponse($result['message'], $result['data'], $result['status'], JsonResponse::HTTP_OK);
    }

    public function getTicketInProgressAll(TicketRequest $req, GetTicketInProgressAllUseCaseInterface $uc): object
    {
        try {
            $result = $uc->getTicketInProgressAll($req);
        } catch (JWTException $e) {
            return standardApiReponse($e->getMessage(), null, ApiResponseConstants::ERROR, JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
        return standardApiReponse($result['message'], $result['data'], $result['status'], JsonResponse::HTTP_OK);
    }

    public function getTicketsByUser(int $user_id, TicketRepositoryInterface $repo): object
    {
        try {
            $tickets = $repo->getTicketsByUser($user_id);
        } catch (JWTException $e) {
            return standardApiReponse($e->getMessage(), null, 1, JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
        return standardApiReponse('ok', $tickets, 0, JsonResponse::HTTP_OK);
    }

    // ── New endpoints ──────────────────────────────────────────────────────

    public function getAllTickets(Request $request, TicketRepositoryInterface $repo): object
    {
        $tickets = $repo->getAllTickets($request->only(['status_id', 'technical_id', 'search']));
        return standardApiReponse('ok', $tickets, 0, JsonResponse::HTTP_OK);
    }

    public function getTicketById(int $id, TicketRepositoryInterface $repo): object
    {
        $ticket = $repo->getTicketById($id);
        if (!$ticket) {
            return standardApiReponse('Ticket no encontrado', null, 1, JsonResponse::HTTP_NOT_FOUND);
        }
        return standardApiReponse('ok', $ticket, 0, JsonResponse::HTTP_OK);
    }

    public function getStats(TicketRepositoryInterface $repo): object
    {
        $stats = $repo->getStats();
        return standardApiReponse('ok', $stats, 0, JsonResponse::HTTP_OK);
    }

    public function getNotes(int $id, TicketRepositoryInterface $repo): object
    {
        $notes = $repo->getNotesByTicket($id);
        return standardApiReponse('ok', $notes, 0, JsonResponse::HTTP_OK);
    }

    public function addNote(Request $request, int $id, TicketRepositoryInterface $repo): object
    {
        $request->validate(['note' => 'required|string']);

        $photos    = [];
        $audioPath = null;

        if ($request->hasFile('photos')) {
            foreach ($request->file('photos') as $photo) {
                $path     = $photo->store('tickets/photos', 'public');
                $photos[] = Storage::url($path);
            }
        }

        if ($request->hasFile('audio')) {
            $path      = $request->file('audio')->store('tickets/audios', 'public');
            $audioPath = Storage::url($path);
        }

        $note = $repo->addNote(
            $id,
            getSessionUserId(),
            $request->input('note'),
            $photos,
            $audioPath
        );

        return standardApiReponse('Novedad agregada', $note, 0, JsonResponse::HTTP_OK);
    }

    public function closeTicket(Request $request, int $id, TicketRepositoryInterface $repo): object
    {
        $request->validate(['description' => 'required|string|min:5']);
        $ok = $repo->closeTicket($id, $request->input('description'));
        if (!$ok) {
            return standardApiReponse('No se puede cerrar este ticket', null, 1, JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }
        return standardApiReponse('Ticket cerrado correctamente', null, 0, JsonResponse::HTTP_OK);
    }

    public function reopenTicket(Request $request, int $id, TicketRepositoryInterface $repo): object
    {
        $request->validate(['reason' => 'required|string|min:5']);
        $ok = $repo->reopenTicket($id, $request->input('reason'));
        if (!$ok) {
            return standardApiReponse('No se puede reabrir este ticket', null, 1, JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $ticket = $repo->getTicketById($id);
        if ($ticket) {
            $message =
                "🔁 *TICKET REABIERTO*\n\n" .
                "🆔 *ID Ticket:* {$id}\n" .
                "👤 *Cliente:* {$ticket->user_names} {$ticket->user_lastname}\n\n" .
                "👨‍🔧 *Técnico:* {$ticket->tech_names} {$ticket->tech_lastname}\n\n" .
                "📝 *Motivo:* {$request->input('reason')}";

            \App\Services\NotificationRouterService::dispatch(
                getSessionCompanyId(),
                'ticket_reopen',
                $message
            );
        }

        return standardApiReponse('Ticket reabierto', null, 0, JsonResponse::HTTP_OK);
    }

    public function deleteTicket(int $id, TicketRepositoryInterface $repo): object
    {
        $ok = $repo->deleteTicket($id);
        if (!$ok) {
            return standardApiReponse('Ticket no encontrado', null, 1, JsonResponse::HTTP_NOT_FOUND);
        }
        return standardApiReponse('Ticket eliminado', null, 0, JsonResponse::HTTP_OK);
    }

    public function getTicketsSince(Request $request, TicketRepositoryInterface $repo): object
    {
        $request->validate(['since' => 'required|date']);
        $tickets = $repo->getTicketsSince($request->input('since'));
        return standardApiReponse('ok', $tickets, 0, JsonResponse::HTTP_OK);
    }

    public function reassignTicket(Request $request, int $id, TicketRepositoryInterface $repo): object
    {
        $request->validate(['technical_id' => 'required|integer']);
        $ok = $repo->reassignTicket($id, $request->input('technical_id'));
        if (!$ok) {
            return standardApiReponse('No se pudo reasignar el ticket', null, 1, JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }
        return standardApiReponse('Ticket reasignado', null, 0, JsonResponse::HTTP_OK);
    }
}

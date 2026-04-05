<?php

namespace App\Repositories\Interfaces;

use App\Http\Requests\Ticket\CreateTicketRequest;
use App\Http\Requests\Ticket\TicketRequest;

interface TicketRepositoryInterface
{
    public function getTypePriorityAll(): mixed;
    public function getTypeServiceAll(): mixed;
    public function getTechnicaAll(): mixed;
    public function createTicket(CreateTicketRequest $data): int;
    public function updateTicket(TicketRequest $data): mixed;
    public function getTicketInProgressAll($status): mixed;
    public function getTicketsByUser(int $userId): mixed;
    public function getAllTickets(array $filters): mixed;
    public function getTicketById(int $id): mixed;
    public function addNote(int $ticketId, int $userId, string $note, array $photos, ?string $audioPath): mixed;
    public function getNotesByTicket(int $ticketId): mixed;
    public function reopenTicket(int $ticketId, string $reason): bool;
    public function reassignTicket(int $ticketId, int $techId): bool;
    public function getStats(): mixed;
    public function closeTicket(int $id, string $description): bool;
    public function deleteTicket(int $id): bool;
    public function getTicketsSince(string $since): mixed;
}

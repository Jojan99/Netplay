<?php
namespace App\UseCases\Crm;
use App\Repositories\Interfaces\ConversationRepositoryInterface;
use App\UseCases\Crm\Interfaces\GetCrmAgentsUseCaseInterface;
use Illuminate\Support\Collection;

class GetCrmAgentsUseCase implements GetCrmAgentsUseCaseInterface
{
    public function __construct(
        private ConversationRepositoryInterface $agentRepository
    ) {}

    public function execute(): array
    {
        /** @var Collection $agents */
        $agents = $this->agentRepository->getAgentsWithLoad();
        $conversations = $this->agentRepository->getActiveConversationsByAgent();

        return $agents
    ->map(function ($a) use ($conversations) {

        $status = 'libre';

        if ($a->active_chats >= $a->max_chats) {
            $status = 'saturado';
        } elseif ($a->active_chats >= ($a->max_chats * 0.7)) {
            $status = 'ocupado';
        }

        $agentConversations = $conversations->get($a->user_id) ?? collect();

        return [
            'agent_id'     => $a->agent_id,
            'user_id'      => $a->user_id,
            'name'         => $a->name,
            'active_chats' => (int) $a->active_chats,
            'max_chats'    => (int) $a->max_chats,
            'status'       => $status,
            'conversations' => $agentConversations->map(function ($c) {
                return [
                    'conversation_id' => $c->conversation_id,
                    'customer_name'   => $c->customer_name
                ];
            })->values()
        ];
    })
    ->values()
    ->toArray();
}
}

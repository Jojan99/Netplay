<?php

namespace App\UseCases\Ticket;

use App\Constants\ApiResponseConstants;
use App\Http\Requests\Facturation\CreateFacturationRequest;
use App\Http\Requests\Facturation\CreatePaidFacturationRequest;
use App\Http\Requests\Ticket\CreateTicketRequest;
use App\Http\Requests\Ticket\TicketRequest;
use App\Repositories\Interfaces\FacturationRepositoryInterface;
use App\Repositories\Interfaces\TicketRepositoryInterface;
use App\Repositories\Interfaces\UserRepositoryInterface;
use App\Resources\TemplatesEmail\TemplateEmailPay;
use App\Services\Avisos\MensajeDeAviso;
use App\Services\WhatsAppService;
use App\UseCases\Facturation\Interfaces\CreatePaidFacturationUseCaseInterface;
use App\UseCases\GeneratePdf\Interfaces\GeneratePdfReceiptByIdUseCaseInterface;
use App\UseCases\Ticket\Interfaces\CreateTicketUseCaseInterface;
use App\UseCases\Ticket\Interfaces\UpdateTicketUseCaseInterface;
use Illuminate\Database\QueryException;
use RouterOS\Exceptions\QueryException as ExceptionsQueryException;

/**
 * Clase del caso de uso signin
 *
 * @package App\UseCases\User
 * @author NetPlay <Netplay>
 * @copyright 2023/09/22
 */
class UpdateTicketUseCase implements UpdateTicketUseCaseInterface
{
    /**
     * Constructor de la clase
     *
     * @param FacturationRepositoryInterface $facturationRepository

     */

    public function __construct(
        private FacturationRepositoryInterface $facturationRepository,
        private GeneratePdfReceiptByIdUseCaseInterface $generatePdfReceiptByIdUseCaseInterface,
        private TemplateEmailPay $TemplateEmailPay,
        private UserRepositoryInterface $userRepositoryInterface,
        private TicketRepositoryInterface $ticketRepositoryInterface
        

    ) {
    }

    /**
     * @param TicketRequest $data
     * @return mixed
     */
    public function updateTicket(TicketRequest $data): mixed
    {
        try {
            if (!sessionUserHasProfile('USER')) {

                // $dataUser = $this->userRepositoryInterface->getUserById($data['']);
                // $data['log_id'] = getSessionUserId();

                $aviso = null;

                if ($data['status'] == 1) {
                    $aviso = MensajeDeAviso::nuevo('Ticket en curso', getSessionCompanyId(), '🟡')
                        ->dato('Ticket', "#{$data['id']}")
                        ->dato('Cliente', $data['names_client'] ?? null)
                        ->dato('Técnico', $data['tech_names'] ?? null)
                        ->fecha('Inicio', $data['started_at'] ?? now())
                        ->cierre('El técnico ya está atendiendo el ticket.');
                } elseif ($data['status'] == 2) {
                    $aviso = MensajeDeAviso::nuevo('Ticket finalizado', getSessionCompanyId(), '🟢')
                        ->dato('Ticket', "#{$data['id']}")
                        ->dato('Cliente', $data['names_client'] ?? null)
                        ->dato('Técnico', $data['tech_names'] ?? null)
                        ->fecha('Cierre', $data['finished_at'] ?? now())
                        ->cierre('El técnico dio el ticket por finalizado.');
                }

                $aviso?->enviar('ticket_status_change');

                $this->ticketRepositoryInterface->updateTicket($data);
                // $this->TemplateEmailPay->EmailPay($dataUser,$data['price_total'],$data['number_facture']);
            } else {
                return ['message' => 'No tienes esta accion permitida', 'data' => 9, 'status' => 1];
            }
        } catch (ExceptionsQueryException $err) {
            return ['message' => 'An error occurred while creating the user: ' . $err->getMessage(), 'data' => ApiResponseConstants::DATA_NULL, 'status' => 1];
        }
        return ['message' => 'Ticket Actualizado correctamente', 'status' => 0, 'data' => "ok"];
    }
}

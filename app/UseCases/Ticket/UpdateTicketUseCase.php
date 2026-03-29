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
            if (getSessionUserProfileId() != 1) {

                // $dataUser = $this->userRepositoryInterface->getUserById($data['']);
                // $data['log_id'] = getSessionUserId();

                 $whatsapp = new WhatsAppService();
                $hora = now()->format('H:i');
                if($data['status'] == 1){
                    $message =
                "🟡 *TICKET EN CURSO*\n\n".
                "🆔 *ID Ticket:* {$data['id']}\n".
                "👨‍🔧 *Cliente:* {$data['names_client']}\n\n".
                "👨‍🔧 *Técnico:* {$data['tech_names']}\n\n".
                "⏰ *Hora de inicio:* {$hora}\n\n".
                "⚙️ El técnico ha iniciado el ticket";
                } elseif($data['status'] == 2){
                $message =
                "🟢 *TICKET EN FINALIZADO*\n\n".
                "🆔 *ID Ticket:* {$data['id']}\n".
                "👨‍🔧 *Cliente:* {$data['names_client']}\n\n".
                "👨‍🔧 *Técnico:* {$data['tech_names']}\n\n".
                "⏰ *Hora de inicio:* {$hora}\n\n".
                "⚙️ El técnico ha Finalizado el ticket";
                }
                

                  $response = $whatsapp->mensajeInformativo('120363364349473490@g.us', $message);
                 //$response = $whatsapp->mensajeInformativo('3245127869', $message);
                
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

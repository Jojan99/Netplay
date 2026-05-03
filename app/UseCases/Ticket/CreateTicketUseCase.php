<?php

namespace App\UseCases\Ticket;

use App\Constants\ApiResponseConstants;
use App\Http\Requests\Facturation\CreateFacturationRequest;
use App\Http\Requests\Facturation\CreatePaidFacturationRequest;
use App\Http\Requests\Ticket\CreateTicketRequest;
use App\Repositories\Interfaces\FacturationRepositoryInterface;
use App\Repositories\Interfaces\TicketRepositoryInterface;
use App\Repositories\Interfaces\UserRepositoryInterface;
use App\Resources\TemplatesEmail\TemplateEmailPay;
use App\Services\WhatsAppService;
use App\Services\NotificationRouterService;
use App\UseCases\Facturation\Interfaces\CreatePaidFacturationUseCaseInterface;
use App\UseCases\GeneratePdf\Interfaces\GeneratePdfReceiptByIdUseCaseInterface;
use App\UseCases\Ticket\Interfaces\CreateTicketUseCaseInterface;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use RouterOS\Exceptions\QueryException as ExceptionsQueryException;

/**
 * Clase del caso de uso signin
 *
 * @package App\UseCases\User
 * @author NetPlay <Netplay>
 * @copyright 2023/09/22
 */
class CreateTicketUseCase implements CreateTicketUseCaseInterface
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
        private UserRepositoryInterface $UserRepositoryInterface,
        private TicketRepositoryInterface $ticketRepositoryInterface

    ) {
    }

    /**
     * @param CreateTicketRequest $data
     * @return mixed
     */
    public function createTicket(CreateTicketRequest $data): mixed
    {
        try {
            if (sessionUserHasProfile('CONTADOR', 'ADMIN')) {

                $dataUser = $this->UserRepositoryInterface->getUserById($data['id_user']);
                $data['log_id'] = getSessionUserId();


                error_log(json_encode($data->json()->all()));

              
              $id =  $this->ticketRepositoryInterface->createTicket($data);

                $hora    = Carbon::now()->toDateTimeString();
                $isInstall = isset($data['type_service']) && stripos($data['type_service'], 'instala') !== false;
                $eventType = $isInstall ? 'ticket_install' : 'ticket_support';

                $message =
                "🆕 *NUEVO TICKET*\n\n".
                "🆔 *ID:* {$id}\n".
                "👤 *Cliente:* {$data['client_name']}\n".
                "📄 *Cédula:* {$data['cedula']}\n".
                "📞 *Teléfono:* {$data['phone']}\n".
                "📍 *Dirección:* {$data['address']}\n".
                "👨‍🔧 *Técnico:* {$data['technician_name']}\n\n".
                "📝 *Observación:*\n{$data['observation']}\n\n".
                "⏰ *Fecha:* {$hora}";

                NotificationRouterService::dispatch(getSessionCompanyId(), $eventType, $message);

                // $this->TemplateEmailPay->EmailPay($dataUser,$data['price_total'],$data['number_facture']);
            } else {
                return ['message' => 'No tienes esta accion permitida', 'data' => 9, 'status' => 1];
            }
        } catch (ExceptionsQueryException $err) {
            return ['message' => 'An error occurred while creating the user: ' . $err->getMessage(), 'data' => ApiResponseConstants::DATA_NULL, 'status' => 1];
        }
        return ['message' => 'Ticket generado correctamente', 'status' => 0, 'data' => "ok"];
    }
}

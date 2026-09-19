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
use App\Services\Avisos\MensajeDeAviso;
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

                // El aviso al grupo lo decide quien crea el ticket. Antes salía
                // siempre y no había forma de crear uno sin avisar. Y nunca puede
                // tumbar el alta: el ticket ya quedó guardado.
                if ($data->boolean('notify_group', true)) {
                    try {
                        // type_service y priority llegan como id: sin el nombre el
                        // aviso mostraría un número y "instalación" nunca se
                        // detectaría.
                        $servicio  = \Illuminate\Support\Facades\DB::table('ticket_type_services')
                            ->where('id', $data['type_service'] ?? 0)->value('name');
                        $prioridad = \Illuminate\Support\Facades\DB::table('ticket_type_prioritys')
                            ->where('id', $data['priority'] ?? 0)->value('name');

                        $isInstall = $servicio !== null && stripos((string) $servicio, 'instala') !== false;

                        MensajeDeAviso::nuevo(
                                $isInstall ? 'Nuevo ticket de instalación' : 'Nuevo ticket de soporte',
                                getSessionCompanyId(),
                                $isInstall ? '📦' : '🔧'
                            )
                            ->dato('Ticket', "#{$id}")
                            ->dato('Cliente', $data['client_name'] ?? null)
                            ->dato('Cédula', $data['cedula'] ?? null)
                            ->telefono('Teléfono', $data['phone'] ?? null)
                            ->dato('Dirección', $data['address'] ?? null)
                            ->dato('Servicio', $servicio)
                            ->dato('Prioridad', $prioridad)
                            ->dato('Técnico', $data['technician_name'] ?? null)
                            ->fecha('Registrado', Carbon::now())
                            ->bloque('Observación', $data['observation'] ?? null)
                            ->enviar($isInstall ? 'ticket_install' : 'ticket_support');
                    } catch (\Throwable $e) {
                        \Log::warning('[Tickets] No se pudo avisar el ticket nuevo', [
                            'ticket' => $id,
                            'error'  => $e->getMessage(),
                        ]);
                    }
                }

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

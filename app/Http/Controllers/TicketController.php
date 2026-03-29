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
use Illuminate\Http\JsonResponse;
use Tymon\JWTAuth\Exceptions\JWTException;


class TicketController extends Controller
{
    /**
     * @param GetTypeServiceUseCaseInterface $getTypeServiceUseCaseInterface
     * @return object
     */
    public function getTypeServiceAll(
        GetTypeServiceUseCaseInterface $getTypeServiceUseCaseInterface
    ): object {
        try {
            $result = $getTypeServiceUseCaseInterface->getTypeServiceAll();
        } catch (JWTException $e) {
            
            // Respuesta en caso de excepción
            return standardApiReponse(
                'Currency rates could not be queried: ' . $e->getMessage(),
                ApiResponseConstants::DATA_NULL,
                ApiResponseConstants::ERROR,
                JsonResponse::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        return standardApiReponse(
            $result['message'],
            $result['data'],
            $result['status'],
            JsonResponse::HTTP_OK
        );
    }

    /**
     * @param GetTypePriorityUseCaseInterface $getTypePriorityUseCaseInterface
     * @return object
     */
    public function getTypePriorityAll(
        GetTypePriorityUseCaseInterface $getTypePriorityUseCaseInterface
    ): object {
        try {
            $result = $getTypePriorityUseCaseInterface->getTypePriorityAll();
        } catch (JWTException $e) {
            
            // Respuesta en caso de excepción
            return standardApiReponse(
                'Currency rates could not be queried: ' . $e->getMessage(),
                ApiResponseConstants::DATA_NULL,
                ApiResponseConstants::ERROR,
                JsonResponse::HTTP_INTERNAL_SERVER_ERROR
            );
        }
        return standardApiReponse(
            $result['message'],
            $result['data'],
            $result['status'],
            JsonResponse::HTTP_OK
        );
    }

    /**
     * @param GetTechnicalUseCaseInterface $getTechnicalUseCaseInterface
     * @return object
     */
    public function getTechnicaAll(
        GetTechnicalUseCaseInterface $getTechnicalUseCaseInterface
    ): object {
        try {
            $result = $getTechnicalUseCaseInterface->getTechnicaAll();
        } catch (JWTException $e) {
            
            // Respuesta en caso de excepción
            return standardApiReponse(
                'Currency rates could not be queried: ' . $e->getMessage(),
                ApiResponseConstants::DATA_NULL,
                ApiResponseConstants::ERROR,
                JsonResponse::HTTP_INTERNAL_SERVER_ERROR
            );
        }
        return standardApiReponse(
            $result['message'],
            $result['data'],
            $result['status'],
            JsonResponse::HTTP_OK
        );
    }

    /**
     * @param CreateTicketRequest $createTicketRequest
     * @param CreateTicketUseCaseInterface $createTicketUseCaseInterface
     * @return object
     */
    public function createTicket(
        CreateTicketRequest $createTicketRequest,
        CreateTicketUseCaseInterface $createTicketUseCaseInterface
    ): object {
        try {
            $createTicket = $createTicketUseCaseInterface->createTicket($createTicketRequest);
        } catch (JWTException $e) {
            // Respuesta en caso de excepción
            return standardApiReponse(
                'Currency rates could not be queried: ' . $e->getMessage(),
                ApiResponseConstants::DATA_NULL,
                ApiResponseConstants::ERROR,
                JsonResponse::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        return standardApiReponse(
            $createTicket['message'],
            $createTicket['data'],
            $createTicket['status'],
            JsonResponse::HTTP_OK
        );
    }

    /**
     * @param CreateTicketRequest $createTicketRequest
     * @param UpdateTicketUseCaseInterface $updateTicketUseCaseInterface
     * @return object
     */
    public function updateTicket(
        TicketRequest $ticketRequest,
        UpdateTicketUseCaseInterface $updateTicketUseCaseInterface
    ): object {
        try {
            $createTicket = $updateTicketUseCaseInterface->updateTicket($ticketRequest);
        } catch (JWTException $e) {
            // Respuesta en caso de excepción
            return standardApiReponse(
                'Currency rates could not be queried: ' . $e->getMessage(),
                ApiResponseConstants::DATA_NULL,
                ApiResponseConstants::ERROR,
                JsonResponse::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        return standardApiReponse(
            $createTicket['message'],
            $createTicket['data'],
            $createTicket['status'],
            JsonResponse::HTTP_OK
        );
    }
    
    /**
     * @param CreateTicketRequest $createTicketRequest
     * @param GetTicketInProgressAllUseCaseInterface $getTicketInProgressAllUseCaseInterface
     * @return object
     */
    public function getTicketInProgressAll(
        TicketRequest $ticketRequest,
        GetTicketInProgressAllUseCaseInterface $getTicketInProgressAllUseCaseInterface
    ): object {
        try {
            $createTicket = $getTicketInProgressAllUseCaseInterface->getTicketInProgressAll($ticketRequest);
        } catch (JWTException $e) {
            // Respuesta en caso de excepción
            return standardApiReponse(
                'Currency rates could not be queried: ' . $e->getMessage(),
                ApiResponseConstants::DATA_NULL,
                ApiResponseConstants::ERROR,
                JsonResponse::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        return standardApiReponse(
            $createTicket['message'],
            $createTicket['data'],
            $createTicket['status'],
            JsonResponse::HTTP_OK
        );
    }
}

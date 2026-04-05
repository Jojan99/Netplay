<?php

namespace App\UseCases\Facturation;

use App\Constants\ApiResponseConstants;
use App\Http\Requests\Facturation\CreateFacturationRequest;
use App\Http\Requests\Facturation\CreatePaidFacturationRequest;
use App\Repositories\Interfaces\FacturationRepositoryInterface;
use App\Repositories\Interfaces\UserRepositoryInterface;
use App\Resources\TemplatesEmail\TemplateEmailPay;
use App\UseCases\Facturation\Interfaces\CreatePaidFacturationUseCaseInterface;
use App\UseCases\GeneratePdf\Interfaces\GeneratePdfReceiptByIdUseCaseInterface;
use Illuminate\Database\QueryException;
use RouterOS\Exceptions\QueryException as ExceptionsQueryException;

/**
 * Clase del caso de uso signin
 *
 * @package App\UseCases\User
 * @author NetPlay <Netplay>
 * @copyright 2023/09/22
 */
class CreatePaidFacturationUseCase implements CreatePaidFacturationUseCaseInterface
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
        private UserRepositoryInterface $UserRepositoryInterface

    ) {
    }

    /**
     * @param CreatePaidFacturationRequest $data
     * @return mixed
     */
    public function createpaidFacturation(CreatePaidFacturationRequest $data): mixed
    {
        try {
            if (getSessionUserProfileId() == 2) {

                $validation = $this->facturationRepository->getDateFacturePendingById($data['det_id']);

                $dataUser = $this->UserRepositoryInterface->getUserById($data['id_user']);
                $data['log_id'] = getSessionUserId();
                if ($validation["abone"] == 1) {
                    return ['message' => 'el usuario ya tiene un abono no puedes realizar un pago total', 'data' => 0, 'status' => 1];
                }
                $this->facturationRepository->createpaidFacturation($data);
                // $this->TemplateEmailPay->EmailPay($dataUser,$data['price_total'],$data['number_facture']);
            } else {
                return ['message' => 'No tienes esta accion permitida', 'data' => 9, 'status' => 1];
            }
        } catch (ExceptionsQueryException $err) {
            return ['message' => 'An error occurred while creating the user: ' . $err->getMessage(), 'data' => ApiResponseConstants::DATA_NULL, 'status' => 1];
        }
        return ['message' => 'Pago generado con éxito', 'status' => 0, 'data' => "ok"];
    }
}

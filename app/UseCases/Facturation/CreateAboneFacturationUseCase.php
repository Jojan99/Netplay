<?php

namespace App\UseCases\Facturation;

use App\Constants\ApiResponseConstants;
use App\Http\Requests\Facturation\CreateFacturationRequest;
use App\Http\Requests\Facturation\CreatePaidFacturationRequest;
use App\Repositories\Interfaces\FacturationRepositoryInterface;
use App\Repositories\Interfaces\UserRepositoryInterface;
use App\Resources\TemplatesEmail\TemplateEmailPay;
use App\UseCases\Facturation\Interfaces\CreateAboneFacturationUseCaseInterface;
use App\UseCases\Facturation\Interfaces\CreatePaidFacturationUseCaseInterface;
use App\UseCases\GeneratePdf\Interfaces\GeneratePdfReceiptByIdUseCaseInterface;
use Illuminate\Database\QueryException;
use Carbon\Carbon;
use DateTime;
use RouterOS\Exceptions\QueryException as ExceptionsQueryException;

use function Laravel\Prompts\error;

/**
 * Clase del caso de uso signin
 *
 * @package App\UseCases\User
 * @author NetPlay <Netplay>
 * @copyright 2023/09/22
 */
class CreateAboneFacturationUseCase implements CreateAboneFacturationUseCaseInterface
{
    /**
     * Constructor de la clase
     *
     * @param FacturationRepositoryInterface $facturationRepository

     */

    public function __construct(
        private FacturationRepositoryInterface $facturationRepository,
        private GeneratePdfReceiptByIdUseCaseInterface $generatePdfReceiptByIdUseCaseInterface,
        private UserRepositoryInterface $UserRepositoryInterface,
        private TemplateEmailPay $TemplateEmailPay,

    ) {
    }

    /**
     * @param CreatePaidFacturationRequest $data
     * @return mixed
     */
    public function createAboneFacturation(CreatePaidFacturationRequest $data): mixed
    { 
        ;
        try {
            if (sessionUserHasProfile('CONTADOR', 'ADMIN')) {
                    $validation = $this->facturationRepository->getDateFacturePendingById($data['det_id']);
                    $dataUser = $this->UserRepositoryInterface->getUserById($data['id_user']);
                    $jugada = $validation['price_abone'] + $data['price_discount'];
                    $priceTotal = $validation['price_total'] - $validation['price_discount'];
                    $data["paid"] = 0;
                    $data["abone"] = 1;
                    $data['log_id'] = getSessionUserId();
                    if($jugada > $priceTotal){
                        return ['message' => 'El valor que intentas Abonar es mayor al valor espereado ' .($priceTotal-$validation['price_abone']), 'data' => 0, 'status' => 1];
                        
                  
                    }elseif($priceTotal == $jugada){
                       
                        $data["paid"] = 1;
                        $data["abone"] = 0;
                        $data["price_total"] = 0;
                    
                    }elseif($data['price_discount'] == 0 ){
                        
                        return ['message' => 'No puedes realizar un abono de 0 pesos ', 'data' => 9, 'status' => 1];
                    }else{
                        $data['price_total'] = $jugada ;
                        $data["abone"] = 1;
                    }
                    $this->facturationRepository->createAboneFacturation($data);
                    // $pdf =  $this->generatePdfReceiptByIdUseCaseInterface->generatePdfById($dataUser,$data['price_total'],$data['number_facture']);
                    $this->TemplateEmailPay->EmailPay($dataUser,$data['price_discount'],$data['det_id']);

            } else {
                return ['message' => 'No tienes esta accion permitida', 'data' => 0, 'status' => 1];
            }
        } catch (ExceptionsQueryException $err) {
            return ['message' => 'An error occurred while creating the user: ' . $err->getMessage(), 'data' => ApiResponseConstants::DATA_NULL, 'status' => 1];
        }
        return ['message' => 'Pago generado con éxito', 'status' => 0, 'data' => $data['price_discount']];
    }
}

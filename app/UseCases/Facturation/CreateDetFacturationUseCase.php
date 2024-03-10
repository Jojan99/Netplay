<?php

namespace App\UseCases\Facturation;

use App\Constants\ApiResponseConstants;
use App\Http\Requests\Facturation\CreateFacturationRequest;
use App\Repositories\Interfaces\FacturationRepositoryInterface;
use App\UseCases\Facturation\Interfaces\CreateDetFacturationUseCaseInterface;
use Illuminate\Database\QueryException;
use Carbon\Carbon;
use DateTime;

/**
 * Clase del caso de uso signin
 *
 * @package App\UseCases\User
 * @author NetPlay <Netplay>
 * @copyright 2023/09/22
 */
class CreateDetFacturationUseCase implements CreateDetFacturationUseCaseInterface
{
    /**
     * Constructor de la clase
     *
     * @param FacturationRepositoryInterface $facturationRepository

     */

    public function __construct(
        private FacturationRepositoryInterface $facturationRepository,

    ) {
    }

    /**
     * @param CreateFacturationRequest $data
     * @return mixed
     */
    public function createDetFacturation(CreateFacturationRequest $data): mixed
    { 
        try {
            if (true) {
                
                error_log($data);
                $getDateLast = $this->facturationRepository->getDateLast($data['id_user']);

                if($getDateLast){
                    $getDateLastUno = Carbon::parse($getDateLast['date_facturation']);
                    $getDataNow = Carbon::parse($data['date_facturation']);
                }else{
                    $getDataNow = Carbon::parse($data['date_facturation']);
                    $getDateLastUno = Carbon::parse(now());
                }

                $validation = $getDateLastUno->month;
                $getDataNow = $getDataNow->month;


                error_log($data['porcentage_discount']);
                    $daysFacture = $data['days_facture'];
    
                    $pricePlan = $this->facturationRepository->getPricePlan($data['id_user']);
    
                    $price = $pricePlan['monthly_price'];
    
                    $priceTotaldesc = ($price / 30);
    
                    $calculete = ($priceTotaldesc * $daysFacture);
    
                    $data['date_create_facturation'] = Carbon::now();
    
                    $data['price_discount'] = $price * ($data['porcentage_discount'] / 100);
    
                    if ($data['porcentage_discount'] > 0 && $daysFacture > 0) {
                        return ['message' => 'No se puede agregar descuento y dias facturados a las vez', 'data' => 9, 'status' => 1];
    
    
                    } elseif ($data['discount'] == 1 && $daysFacture > 0) {
                        $data['price_total'] = $calculete;
    
                    } elseif ($data['total'] > 0 && $daysFacture == 0 && $data['discount'] == 0 && $data['porcentage_discount'] == 0) {
    
                        $data['price_total'] = $price;
                    } elseif($data['discount'] == 1 && $data['porcentage_discount'] > 0){
                        $data['price_total'] = $price - $data['price_discount'];
    
                    }elseif($data['total'] == 1 && $data['porcentage_discount'] > 0 || $daysFacture > 0 ){
                        return ['message' => 'No puede Seleccionar total a pagar de la facturas y descuento a la misma vez', 'data' => 9, 'status' => 1];
    
                    }else {
                        return ['message' => 'Debes seleccionar algun valor para la facturacion', 'data' => 9, 'status' => 1];
                    }


                    $fechaObjeto = new DateTime($data['date_facturation']);
                    $fechaObjeto->setDate($fechaObjeto->format('Y'), $fechaObjeto->format('m'), 15);

                    $data_fecha = $fechaObjeto->format('Y-m-d');

                    $CabUser =   $this->facturationRepository->createCabFacturation($data['id_user'],$data_fecha);
                    if ($CabUser) {
                        $data['cab_id'] = $CabUser['id'];
                        $this->facturationRepository->createDetFacturation($data);
                    } else {
                        return ['message' => 'Error creating user', 'data' => 9, 'status' => 1];
                    }
            
            } else {
                return ['message' => 'No tienes esta accion permitida', 'data' => 9, 'status' => 1];
            }

        } catch (QueryException $err) {
            return ['message' => 'An error occurred while creating the user: ' . $err->getMessage(), 'data' => ApiResponseConstants::DATA_NULL, 'status' => 1];
        }
        return ['message' => 'Factura generada con éxito', 'status' => 0, 'data' => ApiResponseConstants::DATA_NULL];
    }

    public function createProcesoDetFacturation(CreateFacturationRequest $data): mixed
    { 
        try {
            if (true) {

                $fechaObjeto = new DateTime($data['date_facturation']);
                $fechaObjeto->setDate($fechaObjeto->format('Y'), $fechaObjeto->format('m'), 15);

                $data_fecha = $fechaObjeto->format('Y-m-d');
              
                $data1 = $this->facturationRepository->getuserFacture1();

               foreach ($data1 as $key => $value) {
               error_log(json_encode($value['id']));

               $data['cab_id'] = $value['id'];
               $data['date_facturation'] = $data_fecha;
               $data['date_create_facturation'] = $data_fecha;
               $data['price_total'] = 50000;
               $data['total'] = 1;
               $data['porcentage_discount'] = 0;
               $data['days_facture'] = 0;
               $data['discount'] = 0;
               $data['price_discount'] = 0;
               $data['paid'] = 0;

               $this->facturationRepository->createDetFacturation($data);
               
               }


              
            
            } else {
                return ['message' => 'No tienes esta accion permitida', 'data' => 9, 'status' => 1];
            }

        } catch (QueryException $err) {
            return ['message' => 'An error occurred while creating the user: ' . $err->getMessage(), 'data' => ApiResponseConstants::DATA_NULL, 'status' => 1];
        }
        return ['message' => 'Factura generada con éxito', 'status' => 0, 'data' => ApiResponseConstants::DATA_NULL];
    }
}

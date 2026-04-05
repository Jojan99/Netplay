<?php

namespace App\UseCases\Facturation;

use App\Constants\ApiResponseConstants;
use App\Http\Requests\Facturation\CreateFacturationRequest;
use App\Repositories\Interfaces\FacturationRepositoryInterface;
use App\Repositories\Interfaces\GeneratePdfRepositoryInterface;
use App\UseCases\Facturation\Interfaces\CreateDetFacturationUseCaseInterface;
use App\UseCases\GeneratePdf\Interfaces\GeneratePdfUseCaseInterface;
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
            if (getSessionUserProfileId() == 2) {
                // Obtener el día del mes de la fecha actual


                $fechaObjeto = new DateTime($data['date_facturation']); // Convertir la cadena en un objeto DateTime
                $diaDelMes = $fechaObjeto->format('d');

                error_log(json_encode($diaDelMes));
 

                $resultado = ($diaDelMes > 20) ? 2 : 1;
                if ($diaDelMes <= 15) {
                    $fechaObjeto->setDate($fechaObjeto->format('Y'), $fechaObjeto->format('m'), 15);
                } else {
                    $fechaObjeto->setDate($fechaObjeto->format('Y'), $fechaObjeto->format('m'), 30);
                }

                $data_fecha = $fechaObjeto->format('Y-m-d');

                error_log($data_fecha);

                $data1 = $this->facturationRepository->getuserFactureCreate($data['id_user']);
                // error_log(json_decode($data1));
                foreach ($data1 as $value) {

                    $data['cab_id'] = $value['id'];
                    $data['date_facturation'] = $data_fecha;
                    $data['date_create_facturation'] = $data_fecha;
                    $data['price_total'] = $data['priceTotal'];
                    $data['total'] = 1;
                    $data['porcentage_discount'] = 0;
                    $data['days_facture'] = 0;
                    $data['discount'] = 0;
                    $data['price_discount'] = 0;
                    $data['paid'] = 0;
                    $data['create_facture_manual'] = 1;

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

    /**
     * @param CreateFacturationRequest $data
     * @return mixed
     */
    public function updateDetFacturation(CreateFacturationRequest $data): mixed
    {
        try {
            if (true) {
                error_log(json_encode($data));
                $price_total = $data['price_total'];
                $data['price_abone'] = 0;
                $calculete = (($price_total / 30) * $data['days_facture']);

                $data['date_create_facturation'] = Carbon::now();

                $data['price_discount'] = $price_total * ($data['porcentage_discount'] / 100);

                if ($data['porcentage_discount'] > 0 && $data['days_facture'] > 0) {
                    return ['message' => 'No se puede agregar descuento y dias facturados a las vez', 'data' => 9, 'status' => 1];
                } elseif ($data['discount'] == 1 && $data['days_facture'] > 0) {
                    $data['price_discount']  =  $calculete;
                } elseif ($data['total'] > 0 && $data['days_facture'] == 0 && $data['discount'] == 0 && $data['porcentage_discount'] == 0) {
                    $data['price_total'] = $price_total;
                } elseif ($data['discount'] == 1 && $data['porcentage_discount'] > 0) {
                    $data['price_total'] = $price_total;
                } elseif ($data['porcentage_discount'] > 0 || $data['days_facture'] > 0) {
                    return ['message' => 'No puede realizar dos descuento a la misma vez', 'data' => 9, 'status' => 1];
                } else {
                    return ['message' => 'Debes seleccionar algun valor para la facturacion', 'data' => 9, 'status' => 1];
                }


                $this->facturationRepository->updateDetFacturation($data);
            } else {
                return ['message' => 'No tienes esta accion permitida', 'data' => 9, 'status' => 1];
            }
        } catch (QueryException $err) {
            return ['message' => 'An error occurred while creating the user: ' . $err->getMessage(), 'data' => ApiResponseConstants::DATA_NULL, 'status' => 1];
        }
        return ['message' => 'Factura generada con éxito', 'status' => 0, 'data' => ApiResponseConstants::DATA_NULL];
    }

    public function createProcesoDetFacturation(
        CreateFacturationRequest $data,
        int $periodo,
        int $companyId,
        int $billingDay,
        int $billingMonth,
        int $billingYear
    ): mixed {
        try {
            // Fecha de factura = día configurado del grupo, en el mes/año indicado
            $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $billingMonth, $billingYear);
            $safeDay     = min($billingDay, $daysInMonth);
            $data_fecha  = sprintf('%04d-%02d-%02d', $billingYear, $billingMonth, $safeDay);

            // Fecha límite para el cálculo del prorrateo (mismo día de factura)
            $fechaCorte = Carbon::create($billingYear, $billingMonth, $safeDay);

            $clientes = $this->facturationRepository->getuserFacture1($periodo, $companyId);

            foreach ($clientes as $value) {
                // Si el cliente fue creado hace menos de 20 días respecto al corte, se prorratea o se omite
                $fechaCreacion = Carbon::parse($value['created_at']);
                $diasDesdeCreacion = $fechaCreacion->diffInDays($fechaCorte);

                if ($diasDesdeCreacion < 20) {
                    // Prorrateo: días proporcionales
                    $prorrateo = round(($value['monthly_price'] / 30) * $diasDesdeCreacion);
                    if ($prorrateo <= 0) continue;
                    $precioFinal = $prorrateo;
                } else {
                    $precioFinal = $value['monthly_price'];
                }

                $data['cab_id']                  = $value['id'];
                $data['date_facturation']         = $data_fecha;
                $data['date_create_facturation']  = $data_fecha;
                $data['price_total']              = $precioFinal;
                $data['total']                    = 1;
                $data['porcentage_discount']      = 0;
                $data['days_facture']             = ($diasDesdeCreacion < 20) ? $diasDesdeCreacion : 30;
                $data['discount']                 = 0;
                $data['price_discount']           = 0;
                $data['paid']                     = 0;
                $data['create_facture_manual']    = 0;

                $this->facturationRepository->createDetFacturation($data);
            }

        } catch (QueryException $err) {
            return [
                'message' => 'Error al generar facturas: ' . $err->getMessage(),
                'data'    => ApiResponseConstants::DATA_NULL,
                'status'  => 1,
            ];
        }

        return ['message' => 'Factura generada con éxito', 'status' => 0, 'data' => ApiResponseConstants::DATA_NULL];
    }

}

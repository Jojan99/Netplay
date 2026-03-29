<?php

namespace App\UseCases\Notifications;

use App\Repositories\Interfaces\SearchRepositoryInterface;
use App\UseCases\Search\Interfaces\SearchUseCaseInterface;
use Illuminate\Database\QueryException;
use App\Constants\ApiResponseConstants;
use App\Http\Requests\Search\SearchRequest;
use App\Repositories\Interfaces\FacturationRepositoryInterface;
use App\Services\WhatsAppService;
use App\UseCases\Notifications\Interfaces\SendNotificationUseCaseInterface;

/**
 * Clase del caso de uso GetCountrysUseCase
 *
 * @package App\UseCases\Pqr
 * @author Network Golden <sa.networkgolden@gmail.com>
 * @copyright 2022/06/13
 */
class SendNotificationUseCase implements SendNotificationUseCaseInterface
{
    /**
     * Constructor de la clase
     *
     * @param FacturationRepositoryInterface $facturationRepositoryInterface
     */
    public function __construct(
        private FacturationRepositoryInterface $facturationRepositoryInterface
    ) {
    }

    /**
     * @return mixed
     */
    public function sendNotificationReminder(): mixed
    {
        try {
            if(true){

                $whatsapp = new WhatsAppService('u99eyqpz5jwn5h4w', 'instance106490');

                $getDataInfoPenddingFacture = $this->facturationRepositoryInterface->getDataInfoPenddingFacture();

                error_log(json_encode($getDataInfoPenddingFacture));

                foreach ($getDataInfoPenddingFacture as $value) {
                    // Divide el string de teléfonos y recorta espacios en cada número
                    $phoneNumbers = array_map('trim', explode(' - ', $value->phone));
                    
                
                    // Prepara el mensaje a enviar
$message = "¡Hola! *{$value->names} {$value->lastname}* te informamos que tu servicio de internet está próximo a ser suspendido por mora 🚫
                
Tu saldo actual es de *{$value->price_total}* con *{$value->monthPedding}* meses pendientes.
                                        
Recuerda que puedes realizar tus pagos a las siguientes cuentas bancarias o en un corresponsal bancolombia:
                
BANCOLOMBIA CTA AHO 47800013328  
DAVIPLATA 3022042294  
NEQUI 3022042294 (Hum Gom)  
NEQUI 3245127869 (Joj Pom)
                
Estar siempre al día con tu factura evita suspensiones de servicio y reportes negativos en centrales de riesgo.

*Si ya ha cancelado, por favor ignore este mensaje.* 

Gracias por preferirnos @.NET, somos Soluciones NetPlay";
                
                    // Envía el mensaje a cada número individualmente
                    foreach ($phoneNumbers as $phone) {
                        $response = $whatsapp->mensajeInformativo($phone, $message);
                        echo $response;
                    }
                }
          
            }else{
                return [
                    'message' => 'Accion no permitida',
                    'status' => 1,
                    'data' => ApiResponseConstants::DATA_NULL
                ];
            }
        } catch (QueryException $err) {
            return [
                'message' => 'Ocurrio un error al consultar los generos disponibles',
                'status' => 1,
                'data' => ApiResponseConstants::DATA_NULL
            ];
        }

        return [
            'message' => 'consulta realizada con exito',
            'status' => 0,
            'data' => $getDataInfoPenddingFacture 
        ];
    }
}

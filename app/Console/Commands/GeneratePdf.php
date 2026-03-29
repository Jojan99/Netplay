<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\WhatsAppService;
use App\UseCases\User\Interfaces\GetUserAllUseCaseInterface;
use Carbon\Carbon;

class GeneratePdf extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:mensaje'; // Corregido "mesanje" a "mensaje"

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Envía un mensaje informando sobre un error en el envío de facturas';

    /**
     * @var GetUserAllUseCaseInterface
     */
    protected $getUserAllUseCase;

    /**
     * Constructor con inyección de dependencias.
     */
    public function __construct(GetUserAllUseCaseInterface $getUserAllUseCase)
    {
        parent::__construct();
        $this->getUserAllUseCase = $getUserAllUseCase;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $whatsapp = new WhatsAppService('u99eyqpz5jwn5h4w', 'instance106490');

        // Obtener los números de teléfono de todos los usuarios
        $users = $this->getUserAllUseCase->getUserAll();
        error_log(json_encode($users));


        // Iterar sobre los usuarios y enviar un mensaje de error
        // foreach ($users['data'] as $user) {
        // error_log($user['phone']);

            // Verificar que el campo phone no sea nulo o vacío
            // if (!empty($user->phone)) {


                // Dividir los teléfonos si están separados por " - "
                // $phoneNumbers = array_map('trim', explode(' - ', $user->phone));
                $hora = Carbon::now()->toDateTimeString();
                // Mensaje de error
                $message = "⚠️ *Estimado usuario*, .'$hora'. ha ocurrido un error en el envío de facturas.  
Por favor, omita cualquier mensaje recibido.  
Estamos trabajando para solucionar el problema.  
Gracias por su comprensión. 🙏";
                // Enviar el mensaje a cada número
                // foreach ($phoneNumbers as $phone) {
                    $response = $whatsapp->mensajeInformativo('3245127869', $message);
                    $this->info("Mensaje enviado a: - Respuesta: $response");
                }
            }
        // }
    // }
// }

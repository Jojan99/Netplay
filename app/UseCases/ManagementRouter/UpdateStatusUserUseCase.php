<?php

namespace App\UseCases\ManagementRouter;

use App\Repositories\Interfaces\DniRepositoryInterface;
use Illuminate\Database\QueryException;
use App\Constants\ApiResponseConstants;
use App\Http\Requests\Gestions\GestionUserRequest;
use App\Managers\Interfaces\ConectionRouterManagerInterface;
use App\Models\CabFacturation;
use App\Models\DetFacturation;
use App\Models\UserData;
use App\Repositories\Interfaces\ManagementRouterRepositoryInterface;
use App\Services\WhatsAppService;
use App\UseCases\ManagementRouter\Interfaces\UpdateStatusUserUseCaseInterface;
use RouterOS\Exceptions\QueryException as ExceptionsQueryException;
use RouterOS\Query;


/**
 * Clase del caso de uso GetCountrysUseCase
 *
 * @package App\UseCases\Pqr
 * @author Network Golden <sa.networkgolden@gmail.com>
 * @copyright 2022/06/13
 */
class UpdateStatusUserUseCase implements UpdateStatusUserUseCaseInterface
{
    /**
     * Constructor de la clase
     *
     * @param DniRepositoryInterface $dniRepositoryInterface
     */
    protected  $connection;

    public function __construct(
        private ManagementRouterRepositoryInterface $managementRouterRepositoryInterface,
        private ConectionRouterManagerInterface $conectionRouterManagerInterface,
        private \App\Repositories\Interfaces\RouterRepositoryInterface $routerRepositoryInterface,
    ) {
        $this->connection = $conectionRouterManagerInterface;
    }

    private function resolveToken(?int $routerId = null): string
    {
        $companyId = getSessionCompanyId();
        if (!$companyId) {
            throw new \RuntimeException('Sesión sin empresa asociada');
        }

        if ($routerId) {
            $router = $this->routerRepositoryInterface->getRouterById($routerId, $companyId);
            if (!$router) {
                throw new \RuntimeException('Router no encontrado o no pertenece a esta empresa');
            }
            return $router->token;
        }

        $token = $this->routerRepositoryInterface->getTokenByCompany($companyId);
        if (!$token) {
            throw new \RuntimeException('No hay router configurado para esta empresa');
        }
        return $token;
    }

    /** @deprecated use resolveToken() */
    private function getCompanyRouterId(): string
    {
        return $this->resolveToken();
    }

    /**
     * @return mixed
     * @param GestionUserRequest $gestionUserRequest
     */
public function UpdateStatus(GestionUserRequest $gestionUserRequest): array
{
    $client = null;

    try {
        error_log('llega');

        $statusCmd = ($gestionUserRequest['status'] == 2)
            ? '/ip/arp/disable'
            : '/ip/arp/enable';

        // RESPUESTA DEL UPDATE (NO SOBREESCRIBIR)
        $routerResponse = $this->managementRouterRepositoryInterface->UpdateStatus($gestionUserRequest);

        error_log(json_encode($routerResponse));


        if (empty($routerResponse['username'])) {
            return [
                'message' => 'No se pudo obtener username para buscar en ARP',
                'status'  => 1,
                'data'    => null
            ];
        }

        // 🔹 Obtener router_id del usuario
        $userRouterId = UserData::where('user_id', $gestionUserRequest['id_user'])->value('router_id');

        // ✅ UNA SOLA CONEXIÓN (al router del usuario o al default)
        $client = $this->connection->conection($this->resolveToken($userRouterId ? (int) $userRouterId : null));

        // BUSCAR ARP POR USERNAME
        $query = (new Query('/ip/arp/print'))
            ->where('comment', $routerResponse['username']);

        $user = $client->query($query)->read();

        if (empty($user) || !isset($user[0]['.id'])) {
            return [
                'message' => 'Usuario no encontrado en ARP',
                'status'  => 1,
                'data'    => null
            ];
        }

        // ENABLE / DISABLE ARP
        $query = (new Query($statusCmd))
            ->equal('.id', $user[0]['.id']);

        $client->query($query)->read();

        // Send WhatsApp notification on suspension
        if ($gestionUserRequest['status'] == 2) {
            try {
                $userData = UserData::select('user_data.*')
                    ->join('users', 'users.id', '=', 'user_data.user_id')
                    ->where('user_data.user_id', $gestionUserRequest['id_user'])
                    ->where('users.company_id', getSessionCompanyId())
                    ->first();

                if ($userData && $userData->phone) {
                    $cab = CabFacturation::where('user_id', $userData->user_id)
                    ->select('id', 'user_id', 'billing_electronic')
                    ->first();
                    $balance = $cab
                        ? (float) DetFacturation::where('cab_id', $cab->id)->where('paid', '<>', 1)->sum('price_total')
                        : 0.0;

                    $vars = [
                        '{nombre}'   => $userData->names ?? '',
                        '{apellido}' => $userData->lastname ?? '',
                        '{dni}'      => $userData->dni ?? '',
                        '{telefono}' => $userData->phone ?? '',
                        '{deuda}'    => number_format($balance, 0, '.', ','),
                        '{fecha}'    => now()->format('d/m/Y'),
                    ];

                    if ($cab->billing_electronic == 1) {
                    $template = "🚫 Estimado/a {nombre} {apellido}, le informamos que su servicio de internet ha sido *suspendido* por falta de pago. 🚫\n\n"
                    . "💰 Saldo pendiente: *\${deuda}*\n\n"
                    . "Para reactivar su servicio comuníquese con nosotros.\n\n"
                    . "📅 Fecha: {fecha}\n\n"
                    . "💳 Pago de tu saldo pendiente\n\n"
                    . "Puedes realizar tu pago siguiendo una de estas opciones:\n"
                    . "1⃣ Descarga la imagen adjunta.\n"
                    . "2⃣ Si deseas pagar con Nequi, escanea el código QR y sigue los pasos indicados.\n"
                    . "3⃣ También puedes realizar una transferencia por Bre-B usando la siguiente llave:\n"
                    . "🔑 0091768855\n\n"
                    . "📩 Envíanos el comprobante en tu siguiente mensaje.";

                        $message = str_replace(array_keys($vars), array_values($vars), $template);
                        (new WhatsAppService())->sendImage($userData->phone, "https://netplay.com.co/storage/Qr/QrNetplay.jpeg", $message);
                    } else {
                                   $template = "🚫 Estimado/a {nombre} {apellido}, le informamos que su servicio de internet ha sido *suspendido* por falta de pago.🚫\n\n"
                                  . "💰 Saldo pendiente: *\${deuda}*\n\n"
                                  . "Para reactivar su servicio escanea el código QR adjunto.\n\n"
                                  . "📅 Fecha: {fecha}\n\n"
                                  . "Medios de pago: BANCOLOMBIA CTA AHO 47800013328\n"
                                  . "DAVIPLATA 3022042294 (Hum Gom)\n"
                                  . "NEQUI 3245127869 (Joj Pom)";

                        $message = str_replace(array_keys($vars), array_values($vars), $template);
                        (new WhatsAppService())->mensajeInformativo($userData->phone, $message);
                    }
                }
                   
            } catch (\Throwable $waErr) {
                error_log('WA suspend notify: ' . $waErr->getMessage());
            }
        }

        // Registrar en auto_suspend_logs para que el proceso automático lo tenga en cuenta
        $action = ($gestionUserRequest['status'] == 2) ? 'suspended' : 'reactivated';
        \Illuminate\Support\Facades\DB::table('auto_suspend_logs')->insert([
            'company_id'     => getSessionCompanyId(),
            'user_id'        => $gestionUserRequest['id_user'],
            'action'         => $action,
            'invoices_count' => 0,
            'created_at'     => now(),
        ]);

        return [
            'message' => 'consulta realizada con exito',
            'status'  => 0,
            'data'    => true
        ];

    } catch (\Throwable $err) {
        // Opcional: log real para ver causa exacta
        error_log('UpdateStatus error: ' . $err->getMessage());

        return [
            'message' => 'Ocurrio un error',
            'status'  => 1,
            'data'    => ApiResponseConstants::DATA_NULL
        ];
    } finally {
        // ✅ SIEMPRE CERRAR
        if ($client && method_exists($client, 'disconnect')) {
            $client->disconnect();
        }
    }
}


    public function validateMikrotikConnection(): bool
{
    try {
        // Intentar establecer una conexión con MikroTik
        $connection = $this->connection->conection($this->getCompanyRouterId());
        
        // Realizar una consulta de prueba
        $query = new Query('/system/resource/print');
        $response = $connection->query($query)->read();

        // Verificar si la respuesta es válida
        if (!empty($response)) {
            return true; // Conexión exitosa
        }
    } catch (ExceptionsQueryException $err) {
        error_log("Error de conexión a MikroTik: " . $err->getMessage());
    }
    
    return false; // Fallo en la conexión
}
}


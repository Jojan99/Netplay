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

    private function getCompanyRouterId(): string
    {
        $companyId = getSessionCompanyId();
        if (!$companyId) {
            throw new \RuntimeException('Sesión sin empresa asociada');
        }
        $token = $this->routerRepositoryInterface->getTokenByCompany($companyId);
        if (!$token) {
            throw new \RuntimeException('No hay router configurado para esta empresa');
        }
        return $token;
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

        // ✅ UNA SOLA CONEXIÓN
        $client = $this->connection->conection($this->getCompanyRouterId());

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
                    $cab = CabFacturation::where('user_id', $userData->user_id)->first();
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
                    $template = "Estimado/a {nombre} {apellido}, le informamos que su servicio de internet ha sido *suspendido* por falta de pago.\n\n"
                              . "💰 Saldo pendiente: *\${deuda}*\n\n"
                              . "Para reactivar su servicio, comuníquese con nosotros o realice su pago.\n\n"
                              . "📅 Fecha: {fecha}";

                    $message = str_replace(array_keys($vars), array_values($vars), $template);
                    (new WhatsAppService())->mensajeInformativo($userData->phone, $message);
                }
            } catch (\Throwable $waErr) {
                error_log('WA suspend notify: ' . $waErr->getMessage());
            }
        }

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


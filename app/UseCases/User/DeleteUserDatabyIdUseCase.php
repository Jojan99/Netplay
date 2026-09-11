<?php

namespace App\UseCases\User;

use App\Constants\ApiResponseConstants;
use App\Http\Requests\Facturation\CreateFacturationRequest;
use App\Managers\Interfaces\ConectionRouterManagerInterface;
use App\Repositories\Interfaces\UserRepositoryInterface;
use App\Services\Red\ClienteEnElRouter;
use App\UseCases\User\Interfaces\DeleteUserDatabyIdUseCaseInterface;
use Illuminate\Database\QueryException;




/**
 * Clase del caso de uso signin
 *
 * @package App\UseCases\User
 * @author NetPlay <Netplay>
 * @copyright 2023/09/22
 */
class DeleteUserDatabyIdUseCase implements DeleteUserDatabyIdUseCaseInterface
{
   /**
     * Constructor de la clase
     *
     * @param UserRepositoryInterface $userRepository

     */

     public function __construct(
        private UserRepositoryInterface $userRepository,

    ) {
    }

    /**
     * @param int    $id
     * @param string $enRouter  'quitar', 'suspender' o 'nada'
     * @return mixed
     */
    public function DeleteUserData($id, string $enRouter = 'suspender'): mixed
    {
        if (!sessionUserHasProfile('CONTADOR', 'ADMIN')) {
            return ['message' => 'Accion no permitida', 'status' => 1, 'data' => ''];
        }

        try {
            $this->userRepository->DeleteUserData($id);
        } catch (QueryException $err) {
            return [
                'message' => 'Ha ocurrido un error al actualizar los datos',
                'status' => 1,
                'data' => ApiResponseConstants::DATA_NULL
            ];
        }

        // El router se limpia aquí y no desde el panel: antes el panel llamaba
        // a la suspensión por falta de pago, que además le mandaba al cliente
        // el WhatsApp de "servicio suspendido".
        $router = $this->limpiarRouter((int) $id, $enRouter);

        return [
            'message' => 'Cliente eliminado' . $router['mensaje'],
            'status'  => 0,
            'data'    => ['router' => $router['detalle']],
        ];
    }

    /** @return array{mensaje:string, detalle:array|null} */
    private function limpiarRouter(int $userId, string $enRouter): array
    {
        if (!in_array($enRouter, ['quitar', 'suspender'], true)) {
            return ['mensaje' => '.', 'detalle' => null];
        }

        $companyId = (int) getSessionCompanyId();

        if (!$companyId) {
            return ['mensaje' => '.', 'detalle' => null];
        }

        $servicio = new ClienteEnElRouter(app(ConectionRouterManagerInterface::class), $companyId);
        $r = $enRouter === 'quitar' ? $servicio->quitar($userId) : $servicio->suspender($userId);

        if (!$r['ok']) {
            return [
                'mensaje' => ', pero no se pudo actualizar el MikroTik: ' . implode('; ', $r['errores']),
                'detalle' => $r,
            ];
        }

        if ($r['quitado'] === []) {
            return ['mensaje' => '. No tenía nada configurado en el MikroTik.', 'detalle' => $r];
        }

        return [
            'mensaje' => $enRouter === 'quitar'
                ? '. Se quitó del MikroTik: ' . implode(', ', $r['quitado']) . '.'
                : '. En el MikroTik: ' . implode(', ', $r['quitado']) . '.',
            'detalle' => $r,
        ];
    }
}

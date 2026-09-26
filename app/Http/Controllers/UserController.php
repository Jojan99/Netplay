<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Constants\ApiResponseConstants;
use Tymon\JWTAuth\Exceptions\JWTException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use App\Http\Requests\User\CreateUserDataRequest;
use App\UseCases\User\Interfaces\CreateUserDataUseCaseInterface;
use App\UseCases\User\Interfaces\UpdateUserDataUseCaseInterface;
use App\UseCases\User\Interfaces\GetUserAllUseCaseInterface;
use App\UseCases\User\Interfaces\GetUserUseCaseInterface;
use App\UseCases\User\Interfaces\GetUserByIdUseCaseInterface;
use App\UseCases\User\Interfaces\DeleteUserDatabyIdUseCaseInterface;
use App\UseCases\User\Interfaces\GetCountUserUseCaseInterface;
use App\UseCases\User\Interfaces\GetTotalPriceMonthUseCaseInterface;
use App\UseCases\User\Interfaces\GetTotalClientRegisterMonthUseCaseInterface;
use App\UseCases\User\Interfaces\GetTrazaFactureUseCaseInterface;

class UserController extends Controller
{
    /** Registro de clientes paginado en la base, con conteo por estado. */
    public function lista(Request $request, \App\Services\Clientes\ListaDeClientes $lista): object
    {
        $pagina = $lista->pagina($request->only(['page', 'per_page', 'estado', 'q', 'cliente_id']));
        return standardApiReponse('ok', $pagina, ApiResponseConstants::SUCCESS);
    }

    /**
     * Enciende o apaga la facturación electrónica del cliente (la marca vive en
     * su factura). Es la que decide si cobra por la pasarela y qué mensajes de
     * pago recibe.
     */
    public function facturacionElectronica(int $id, Request $request): object
    {
        $activa = $request->boolean('activa');

        $cab = \App\Models\CabFacturation::where('user_id', $id)
            ->where('company_id', getSessionCompanyId())
            ->first();

        if (!$cab) {
            return standardApiReponse('Ese cliente todavía no tiene facturación creada.', null, 1, JsonResponse::HTTP_OK);
        }

        $cab->billing_electronic = $activa ? 1 : 0;
        $cab->save();

        return standardApiReponse($activa ? 'Facturación electrónica activada.' : 'Facturación electrónica desactivada.',
            ['billing_electronic' => (int) $cab->billing_electronic], 0, JsonResponse::HTTP_OK);
    }

    /**
     * El trato especial del cliente: el descuento que se le aplica en cada
     * factura, sin que nadie tenga que acordarse todos los meses.
     *
     * Tocar plata pide su propia ruta: si viajara dentro del formulario
     * general de la ficha se guardaría sin que nadie lo revise, y un cero de
     * más en el porcentaje sale caro.
     */
    public function guardarDescuento(int $id, Request $request): object
    {
        $request->validate([
            'descuento_tipo'   => 'nullable|in:porcentaje,valor',
            'descuento_valor'  => 'nullable|numeric|min:0|max:99999999',
            'descuento_motivo' => 'nullable|string|max:160',
            'descuento_hasta'  => 'nullable|date',
        ]);

        // UserData no tiene relación con users; la empresa se comprueba contra
        // la tabla, que es lo que separa a un cliente de otra empresa.
        $ficha = \App\Models\UserData::where('user_id', $id)
            ->whereIn('user_id', fn ($q) => $q->select('id')->from('users')->where('company_id', getSessionCompanyId()))
            ->first();

        if (!$ficha) {
            return standardApiReponse('Ese cliente no existe en su empresa.', null, 1, JsonResponse::HTTP_OK);
        }

        $tipo = $request->input('descuento_tipo') ?: null;
        $valor = (float) $request->input('descuento_valor', 0);
        $hasta = $request->input('descuento_hasta') ?: null;

        if ($problema = \App\Services\Facturacion\DescuentoDelCliente::problema($tipo, $valor, $hasta)) {
            return standardApiReponse($problema, null, 1, JsonResponse::HTTP_OK);
        }

        $ficha->descuento_tipo   = $tipo;
        $ficha->descuento_valor  = $tipo ? $valor : 0;
        $ficha->descuento_motivo = $tipo ? ($request->input('descuento_motivo') ?: null) : null;
        $ficha->descuento_hasta  = $tipo ? $hasta : null;
        $ficha->save();

        return standardApiReponse(
            $tipo ? 'Descuento guardado: se aplica desde la próxima factura.' : 'Descuento quitado.',
            $ficha->only(['descuento_tipo', 'descuento_valor', 'descuento_motivo', 'descuento_hasta']),
            0,
            JsonResponse::HTTP_OK,
        );
    }

    /** Clientes eliminados de la empresa, para poder reinstalarlos. */
    public function eliminados(Request $request, \App\Services\Clientes\ClientesEliminados $eliminados): object
    {
        $pagina = $eliminados->lista($request->only(['page', 'per_page', 'q']));
        return standardApiReponse('ok', $pagina, ApiResponseConstants::SUCCESS);
    }

    /** Devuelve un cliente eliminado al registro de activos. */
    public function reinstalar(int $id, Request $request, \App\Services\Clientes\ClientesEliminados $eliminados): object
    {
        $r = $eliminados->reinstalar($id, $request->only(['ip_accion', 'ip', 'interfaz']));
        return standardApiReponse($r['message'], $r['data'], $r['status'], JsonResponse::HTTP_OK);
    }

    /** Antes de reinstalar: ¿la IP que tenía sigue libre (en la plataforma y en el router)? */
    public function reinstalarRevisarIp(int $id, \App\Services\Clientes\ClientesEliminados $eliminados): object
    {
        $r = $eliminados->revisarIp($id);
        return standardApiReponse($r['message'], $r['data'], $r['status'], JsonResponse::HTTP_OK);
    }


    /**
     * @param GetUserUseCaseInterface $getUserUseCaseInterface
     * @return object
     */
    public function getUserLoggedIn(
        GetUserUseCaseInterface $getUserUseCaseInterface
    ): object {
        try {
            $getUserLoggedIn = $getUserUseCaseInterface->getUserLoggedIn(getSessionUserName());
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
            'user data queried successfully',
            $getUserLoggedIn,
            ApiResponseConstants::SUCCESS
        );
    }

    /**
     * @param CreateUserDataRequest $createUserDataRequest
     * @param CreateUserDataUseCaseInterface $createUserDataUseCaseInterface
     * @return object
     */
    public function createUserData(
        CreateUserDataRequest $createUserDataRequest,
        CreateUserDataUseCaseInterface $createUserDataUseCaseInterface
    ): object {
        try {
            $createUserData = $createUserDataUseCaseInterface->createUserData($createUserDataRequest);
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
            $createUserData['message'],
            $createUserData['data'],
            $createUserData['status'],
            JsonResponse::HTTP_OK
        );
    }
    

    /**
     * @param CreateUserDataRequest $createUserDataRequest
     * @param int id
     * @param UpdateUserDataUseCaseInterface $updateUserDataUseCaseInterface
     * @return object
     */
    public function updateUserData(
        CreateUserDataRequest $createUserDataRequest,
        UpdateUserDataUseCaseInterface $updateUserDataUseCaseInterface
    ): object {
        try {
            $updateUserData = $updateUserDataUseCaseInterface->UpdateUserData($createUserDataRequest);
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
            $updateUserData['message'],
            $updateUserData['data'],
            $updateUserData['status'],
            JsonResponse::HTTP_OK
        );
    }

    /**
     * Lo que el cliente tiene en su MikroTik, para decidir al eliminarlo si
     * se borra también de ahí. Sólo lee.
     */
    public function enRouter(int $id): object
    {
        $companyId = (int) getSessionCompanyId();

        if (!$companyId) {
            return standardApiReponse('Sesión sin empresa asociada', null, 1, JsonResponse::HTTP_UNAUTHORIZED);
        }

        $r = (new \App\Services\Red\ClienteEnElRouter(
            app(\App\Managers\Interfaces\ConectionRouterManagerInterface::class),
            $companyId
        ))->queHay($id);

        return standardApiReponse($r['error'] ?? 'En el router', $r, $r['ok'] ? 0 : 1, JsonResponse::HTTP_OK);
    }

    /**
     * @param CreateUserDataRequest $createUserDataRequest
     * @param int id
     * @param UpdateUserDataUseCaseInterface $updateUserDataUseCaseInterface
     * @return object
     */
    public function DeleteUserData(
        int $id,
        DeleteUserDatabyIdUseCaseInterface $deleteUserDatabyIdUseCaseInterface

    ): object {
        try {
            // Qué hacer con sus credenciales en el MikroTik: lo decide quien
            // elimina. Sin el parámetro se suspenden, como hacía antes el panel.
            $deleteUserData = $deleteUserDatabyIdUseCaseInterface->DeleteUserData(
                $id,
                (string) request()->query('router', 'suspender')
            );
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
            $deleteUserData['message'],
            $deleteUserData['data'],
            $deleteUserData['status'],
            JsonResponse::HTTP_OK
        );
    }

    /**
     * @param GetUserAllUseCaseInterface $getUserAllUseCaseInterface
     * @return object
     */
    public function getUserAll(
        GetUserAllUseCaseInterface $getUserAllUseCaseInterface
    ): object {
        try {
            $result = $getUserAllUseCaseInterface->getUserAll();
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
     * @param GetUserByIdUseCaseInterface $getUserByIdUseCaseInterface
     * @return object
     */
    public function getUserById(
        string $id,
        GetUserByIdUseCaseInterface $GetUserByIdUseCaseInterface
    ): object {
        if (!$id) {
            return standardApiReponse(
                'id parameter cannot be empty: ',
                ApiResponseConstants::DATA_NULL,
                ApiResponseConstants::ERROR,
                JsonResponse::HTTP_OK
            );
        }
        try {
            $getUser = $GetUserByIdUseCaseInterface->getUserById($id);
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
            $getUser['message'],
            $getUser['data'],
            $getUser['status'],
            JsonResponse::HTTP_OK
        );
    }

     /**
     * @param GetUserByIdUseCaseInterface $getUserByIdUseCaseInterface
     * @return object
     */
    public function getUserByIdBost(
        string $id,
        GetUserByIdUseCaseInterface $GetUserByIdUseCaseInterface,
        \Illuminate\Http\Request $request
    ): object {
        // Endpoint público: sin empresa devolvería clientes de cualquier empresa.
        $companyKey = $request->query('company');
        $companyId  = getSessionCompanyId();
        if (!$companyId && $companyKey) {
            $companyId = \App\Models\Company::where('slug', $companyKey)
                ->orWhere('nit', $companyKey)
                ->orWhere('id', is_numeric($companyKey) ? (int) $companyKey : 0)
                ->value('id');
        }
        if (!$companyId) {
            return standardApiReponse(
                'Falta identificar la empresa (?company=slug).',
                ApiResponseConstants::DATA_NULL,
                ApiResponseConstants::ERROR,
                JsonResponse::HTTP_BAD_REQUEST
            );
        }

        if (!$id) {
            return standardApiReponse(
                'id parameter cannot be empty: ',
                ApiResponseConstants::DATA_NULL,
                ApiResponseConstants::ERROR,
                JsonResponse::HTTP_OK
            );
        }
        try {
            $getUser = $GetUserByIdUseCaseInterface->getUserByIdBost($id, (int) $companyId);
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
            $getUser['message'],
            $getUser['data'],
            $getUser['status'],
            JsonResponse::HTTP_OK
        );
    }


     /**
     * @param GetCountUserUseCaseInterface $getCountUserUseCaseInterface
     * @return object
     */
    public function getCountUser(
        GetCountUserUseCaseInterface $getCountUserUseCaseInterface
    ): object {
        try {
            $result = $getCountUserUseCaseInterface->getCountUser();
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
     * @param GetTotalClientRegisterMonthUseCaseInterface $getTotalClientRegisterMonthUseCaseInterface
     * @return object
     */
    public function GetTotalClientRegisterMonth(
        Request $request,
        GetTotalClientRegisterMonthUseCaseInterface $getTotalClientRegisterMonthUseCaseInterface
    ): object {
        try {
            $year = (int) $request->query('year', (int) now()->year);
            $result = $getTotalClientRegisterMonthUseCaseInterface->GetTotalClientRegisterMonth($year);
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
     * @param GetTotalPriceMonthUseCaseInterface $getTotalPriceMonthUseCaseInterface
     * @return object
     */
    public function getTotalPriceMonth(
        Request $request,
        GetTotalPriceMonthUseCaseInterface $getTotalPriceMonthUseCaseInterface
    ): object {
        try {
            $year = (int) $request->query('year', (int) now()->year);
            $result = $getTotalPriceMonthUseCaseInterface->getTotalPriceMonth($year);
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
     * @param GetTotalPriceMonthUseCaseInterface $getTotalPriceMonthUseCaseInterface
     * @return object
     */
    public function getTrazaFacture(
        GetTrazaFactureUseCaseInterface $getTrazaFactureUseCaseInterface
    ): object {
        try {
            $result = $getTrazaFactureUseCaseInterface->getTrazaFacture();
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

    public function search(Request $request): object
    {
        $q = $request->query('q', '');
        $results = DB::table('user_data as ud')
            ->join('users', 'users.id', '=', 'ud.user_id')
            ->where('users.company_id', getSessionCompanyId())
            ->where(function ($query) use ($q) {
                $query->where('ud.names', 'like', "%{$q}%")
                      ->orWhere('ud.lastname', 'like', "%{$q}%")
                      ->orWhere('ud.dni', 'like', "%{$q}%")
                      ->orWhere('users.username', 'like', "%{$q}%");
            })
            ->select('ud.user_id as id', 'ud.names', 'ud.lastname', 'ud.dni', 'ud.phone', 'ud.email', 'ud.router_id')
            ->limit(10)
            ->get();

        return standardApiReponse('ok', $results, 0, JsonResponse::HTTP_OK);
    }

    public function getAuditLog(int $user_id): object
    {
        $logs = DB::table('user_audit_logs')
            ->where('user_id', $user_id)
            ->where('company_id', getSessionCompanyId())
            ->orderBy('created_at', 'desc')
            ->limit(100)
            ->get();
        return standardApiReponse('ok', $logs, 0, JsonResponse::HTTP_OK);
    }

    public function exportUsers(): object
    {
        try {
            $users = DB::table('users')
                ->select(
                    'users.id',
                    'user_data.names',
                    'user_data.lastname',
                    'user_data.dni',
                    'user_data.phone',
                    'user_data.email',
                    'user_data.address',
                    'internet_status.name as status',
                    'internet_plans.plan_name',
                    DB::raw("COALESCE(tabla_ips.ip, '') as ip"),
                    'cab_facturations.group as billing_group',
                    'users.created_at'
                )
                ->join('user_data', 'users.id', '=', 'user_data.user_id')
                ->join('internet_status', 'user_data.status_internet_id', '=', 'internet_status.id')
                ->join('internet_plans', 'user_data.internet_plans_id', '=', 'internet_plans.id')
                ->join('cab_facturations', 'cab_facturations.user_id', '=', 'users.id')
                ->leftJoin('tabla_ips', 'tabla_ips.id', '=', 'user_data.ip_assignment_id')
                ->where('user_data.active', 1)
                ->where('users.company_id', getSessionCompanyId())
                ->get();
        } catch (JWTException $e) {
            return standardApiReponse($e->getMessage(), null, 1, JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
        return standardApiReponse('ok', $users, 0, JsonResponse::HTTP_OK);
    }
}

<?php

namespace App\Repositories;

use App\Models\User;
use App\Models\UserData;
use App\Http\Requests\User\CreateUserDataRequest;
use Illuminate\Support\Facades\Hash;
use App\Repositories\Interfaces\UserRepositoryInterface;
use Illuminate\Support\Facades\DB;

class UserRepository implements UserRepositoryInterface
{



    /**
     * Método encargado de obtener los datos de un usuario por medio del nombre
     * de usuario
     *
     * @param string $userName
     * @return mixed
     */
    public function getUserLoggedIn(string $userName): mixed
    {
        $cabs = [];
        $usuario = User::where("username", $userName)->first();
        if ($usuario) {
            $cabs['active'] = $usuario['active'];
            $cabs['status'] = $usuario['status'];
            $cabs['profile_id'] = $usuario['profile_id'];
            // $cabRequestPurchase = CabRequestPurchase::where("user_id", $usuario['id'])->orderBy('id', 'desc')->first();
            $userData = UserData::where("user_id", $usuario['id'])->first();
            $cabs['names'] = $userData['names'];
            $cabs['lastname'] = $userData['lastname'];
            $cabs['user_id'] = $userData['user_id'];
            // if ($cabRequestPurchase) {
            //     $cabs['investment_exists'] = $cabRequestPurchase['status'];
            // }
        }
        return $cabs;
    }
    /**
     * @param int $sponsor_id
     * @param CreateUserDataRequest $data
     * @return mixed
     */
    public function createUser(CreateUserDataRequest $data): mixed
    {
        return User::create([
            'username' => $data['dni'],
            'email' => $data['email'],
            'password' => Hash::make($data['dni']),
            'profile_id' => 1
        ]);
    }

    /**
     * @param CreateUserDataRequest $data
     * @return mixed
     */
    public function createUserData(CreateUserDataRequest $data): mixed
    {
        return UserData::create([
            'names' => $data['names'],
            'lastname' => $data['lastname'],
            'address' => $data['address'],
            'user_id' => $data['userId'],
            'gender_id' => 1,
            'dni_id' => 1,
            'internet_plans_id' => $data['planInternet'],
            'status_internet_id' => 1,
            'country_id' => 1,
            'dni' => $data['dni'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'birthday' => 1,
            'ip_assignment_id' => $data['ip_assignment_id']
        ]);
    }

    /**
     * @param CreateUserDataRequest $data
     * @return mixed
     */
    public function UpdateUserData(CreateUserDataRequest $data): mixed
    {
        $user = UserData::where('user_id', $data['id'])->first();
        if ($user) $user->update(['names' => $data['names']]);

        return $data['id'];
    }

    /**
     * @param int $id
     * @return mixed
     */
    public function DeleteUserData($id): mixed
    {
        $user = UserData::where('user_id', $id)->first();
        if ($user) {
            $user->update([
                'active' => 0,
                'status' => 1, // Marca la fecha de eliminación (si usas soft deletes)
                'status_internet_id' => 2, // Marca la fecha de eliminación (si usas soft deletes)
            ]);
        }

        return true;
    }

    /**
     * @return mixed
     */
    public function getUserAll(): mixed
    {
        return User::select(
            'users.id',
            'user_data.names',
            'user_data.lastname',
            'user_data.address',
            'user_data.dni',
            'user_data.email',
            'user_data.phone',
            'internet_status.name as internet_status',
            'internet_plans.plan_name',
            //'tabla_ips.name as ip',
            'cab_facturations.id as id_cab',
            'users.created_at as date_create'
        )
            ->join('user_data', 'users.id', 'user_data.user_id')
            ->join('internet_status', 'user_data.status_internet_id', 'internet_status.id')
            ->join('internet_plans', 'user_data.internet_plans_id', 'internet_plans.id')
            //->join('tabla_ips', 'user_data.ip_assignment_id', 'tabla_ips.id')
            ->join('cab_facturations', 'users.id', 'cab_facturations.user_id')
            ->where('user_data.active', 1)
            ->where('users.company_id', getSessionCompanyId())
            ->get();
    }
    
    /**
     * @param int $id
     * @return mixed
     */
    public function getUserById($dni): mixed
    {
        return User::select(
                'user_data.names',
                'user_data.lastname',
                'user_data.dni',
                'internet_plans.plan_name',
                'internet_plans.monthly_price',
                'user_data.address',
                'user_data.phone',
                'user_data.email',
                'cab_facturations.id AS id_cab',
                'internet_status.id AS status_internet'
            )
            ->join('user_data', 'users.id', '=', 'user_data.user_id')
            ->join('internet_plans', 'user_data.internet_plans_id', '=', 'internet_plans.id')
            ->join('cab_facturations', 'cab_facturations.user_id', '=', 'user_data.user_id')
            ->join('internet_status', 'internet_status.id', '=', 'user_data.status_internet_id')
            ->where('user_data.user_id', $dni)
            ->first();
    }

    /**
     * @param int $id
     * @return mixed
     */
    public function getUserByIdBost($dni): mixed
    {
        return User::select(
                'user_data.names',
                'user_data.lastname',
                'user_data.dni',
                'internet_plans.plan_name',
                'internet_plans.monthly_price',
                'user_data.address',
                'user_data.phone',
                'user_data.email',
                'cab_facturations.id AS id_cab',
                'internet_status.id AS status_internet',
                'cab_facturations.billing_electronic AS billing_electronic'
            )
            ->join('user_data', 'users.id', '=', 'user_data.user_id')
            ->join('internet_plans', 'user_data.internet_plans_id', '=', 'internet_plans.id')
            ->join('cab_facturations', 'cab_facturations.user_id', '=', 'user_data.user_id')
            ->join('internet_status', 'internet_status.id', '=', 'user_data.status_internet_id')
            ->where('user_data.dni', $dni)
            ->first();
    }

    /**
     * @param string $email
     * @return mixed
     */
    public function validateUserEmail(string $email): mixed
    {
        return UserData::where("email", $email)->first();
    }

    /**
     * @param string $dni
     * @return mixed
     */
    public function validateUserDni(string $dni): mixed
    {
        return UserData::where("dni", $dni)->first();
    }

    /**
     * @param string $phone
     * @return mixed
     */
    public function validateUserPhone(string $phone): mixed
    {
        return UserData::where("phone", $phone)->first();
    }

    /**
     * @return mixed
     */
    public function getCountUser(): mixed
    {
        $result = UserData::selectRaw('
        SUM(CASE WHEN user_data.STATUS = 0 THEN 1 ELSE 0 END) AS actives,
        SUM(CASE WHEN user_data.STATUS = 1 THEN 1 ELSE 0 END) AS inactive
    ')
            ->join('users', 'users.id', '=', 'user_data.user_id')
            ->where('users.company_id', getSessionCompanyId())
            ->first(); // Obtiene el primer (y único) registro

        // Retorna los valores como un array
        return [
            'actives' => $result->actives,
            'inactive' => $result->inactive
        ];
    }

    /**
     * @return mixed
     */
    public function getTotalClientRegisterMonth($year): mixed
    {
        // Realizar la consulta
        $results = DB::table('user_data')
            ->select(
                DB::raw('MONTH(user_data.created_at) as month'),
                DB::raw('COUNT(CASE WHEN user_data.STATUS = 0 THEN user_data.id END) as activeClient'),
                DB::raw('COUNT(CASE WHEN user_data.STATUS = 1 THEN user_data.id END) as inactiveClient')
            )
            ->join('users', 'users.id', '=', 'user_data.user_id')
            ->where('users.company_id', getSessionCompanyId())
            ->whereYear('user_data.created_at', $year)
            ->groupBy(DB::raw('MONTH(user_data.created_at)'))
            ->orderBy(DB::raw('MONTH(user_data.created_at)'))
            ->get();

        return $results;
    }



    /**
     * @return mixed
     */
    public function getTrazaFacture(): mixed
    {
        // Realizar la consulta
        $results = DB::table('det_facturations')
            ->selectRaw('
              SUM(CASE WHEN det_facturations.paid = 1 THEN 1 ELSE 0 END) AS count_paid,
            SUM(CASE WHEN det_facturations.paid = 0 THEN det_facturations.price_total - det_facturations.price_discount - det_facturations.price_abone ELSE 0 END) AS valor_pending,
            SUM(CASE WHEN det_facturations.abone != 1 AND det_facturations.paid = 1 THEN (det_facturations.price_total - det_facturations.price_discount) ELSE 0 END) AS valor_ingreso,
            SUM(CASE WHEN det_facturations.abone = 1 AND det_facturations.paid != 1 THEN (det_facturations.price_abone) ELSE 0 END) AS valor_abone
        ')
            ->join('cab_facturations', 'cab_facturations.id', '=', 'det_facturations.cab_id')
            ->join('users', 'users.id', '=', 'cab_facturations.user_id')
            ->where('users.company_id', getSessionCompanyId())
            ->first();

        return $results;
    }

    /**
     * @return mixed
     */
    public function getTotalPriceMonth($year): mixed
    {
        // Realizar la consulta
        $results = DB::table('det_facturations as df')
        ->join('cab_facturations as cb', 'cb.id', '=', 'df.cab_id')
        ->join('users', 'users.id', '=', 'cb.user_id')
        ->where('users.company_id', getSessionCompanyId())
        ->selectRaw('
            MONTH(df.date_create_facturation) as month,
            SUM(CASE 
                WHEN df.abone != 1 AND df.paid = 1 THEN (df.price_total - df.price_discount) 
                ELSE 0 
            END) AS valor_ingreso,
            SUM(CASE 
                WHEN df.abone = 1 AND df.paid != 1 THEN df.price_abone 
                ELSE 0 
            END) AS valor_abone,
            SUM(CASE 
                WHEN df.abone != 1 AND df.paid = 1 THEN (df.price_total - df.price_discount) 
                ELSE 0 
            END) +
            SUM(CASE 
                WHEN df.abone = 1 AND df.paid != 1 THEN df.price_abone 
                ELSE 0 
            END) AS total_sum,  
            COALESCE((
                SELECT SUM(e.value) 
                FROM egresses e
                WHERE MONTH(e.created_at) = month 
          
            ), 0) AS total_egresses
        ')
        ->whereYear('df.date_create_facturation', $year)
        ->groupBy(DB::raw('MONTH(df.date_create_facturation)'))
        ->orderBy(DB::raw('MONTH(df.date_create_facturation)'))
        ->get();
    return $results;
    
    
    }
}

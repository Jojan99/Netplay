<?php

namespace App\Repositories;

use App\Models\User;
use App\Models\UserData;
use App\Http\Requests\User\CreateUserDataRequest;
use Illuminate\Support\Facades\Hash;
use App\Repositories\Interfaces\UserRepositoryInterface;



class UserRepository implements UserRepositoryInterface{



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
            'username' => $data['username'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
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
            'gender_id' => $data['genderId'],
            'dni_id' => $data['dniId'],
            'internet_plans_id' => $data['planInternet'],
            'status_internet_id' => 1,
            'country_id' => $data['countryId'],
            'dni' => $data['dni'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'birthday' => $data['birthday'],
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
        if ($user) $user->update(['active' => 0]);

        return true;
    }

     /**
     * @return mixed
     */
    public function getUserAll(): mixed
    {

        $data = User::select('users.id','user_data.names','user_data.lastname','user_data.address','user_data.dni','user_data.email','user_data.phone')
        ->join('user_data', 'users.id', 'user_data.user_id')
        ->where('user_data.active' ,1)
        ->get();
        return $data;
    }
      /**
     * @param int $id
     * @return mixed
     */
    public function getUserById($id): mixed
    {
        return User::select('user_data.names', 'user_data.lastname',
        'user_data.dni','internet_plan.plan_name','internet_plan.monthly_price',
        'user_data.address','user_data.phone','user_data.email')
        ->join('user_data', 'users.id', 'user_data.user_id')
        ->join('internet_plan', 'user_data.plan_internet_id', 'internet_plan.id')
        ->where('users.id', $id)->first();
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
}


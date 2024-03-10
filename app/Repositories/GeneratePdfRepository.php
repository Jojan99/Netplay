<?php

namespace App\Repositories;

use App\Models\User;
use App\Models\UserData;
use Dompdf\Dompdf;
use Dompdf\Options;
use App\Http\Requests\User\CreateUserDataRequest;
use Illuminate\Support\Facades\Hash;
use App\Repositories\Interfaces\GeneratePdfRepositoryInterface;
use App\Repositories\Interfaces\UserRepositoryInterface;

use function Laravel\Prompts\select;

class GeneratePdfRepository implements GeneratePdfRepositoryInterface
{
    /**
     * @param int $sponsor_id
     * @return mixed
     */
    // public function generatePdf($data): mixed
    // {
    //     return User::select('user_data.names', 'user_data.lastname',
    //     'user_data.dni','internet_plans.plan_name','internet_plans.monthly_price',
    //     'user_data.address','user_data.phone','user_data.email','cab_facturations.date_init_facturation')
    //     ->join('user_data', 'users.id', '=', 'user_data.user_id')
    //     ->join('internet_plans', 'internet_plans.id', '=', 'user_data.internet_plans_id')
    //     ->join('cab_facturations', 'cab_facturations.user_id', '=', 'user_data.user_id')
    //     ->get();
    
    // }

     /**
     * @return mixed
     */
    public function getUserPeriode1($Periodo): mixed
    {
        $data = UserData::select('user_id')
        ->where('active' ,1)
        ->where('Periode_facture' ,$Periodo)
        ->get();
        error_log($data);

        return $data;
    }

    public function generatePdf($data): mixed
    {
        $select = [];

        foreach ($data as $data) {

            $result = User::select('user_data.names', 'user_data.lastname',
            'user_data.dni','internet_plans.plan_name','internet_plans.monthly_price',
            'user_data.address','user_data.phone','user_data.email','cab_facturations.date_init_facturation','det_facturations.price_discount','det_facturations.number_facture')
            ->join('user_data', 'users.id', '=', 'user_data.user_id')
            ->join('internet_plans', 'internet_plans.id', '=', 'user_data.internet_plans_id')
            ->join('cab_facturations', 'cab_facturations.user_id', '=', 'user_data.user_id')
            ->join('det_facturations', 'det_facturations.cab_id', '=', 'cab_facturations.id')
            ->where('user_data.user_id', $data['user_id'])
            ->get();

            $select = array_merge($select, $result->toArray());
        }

        return $select;
    
    }

    public function generatePdfById($id): mixed{
        return User::select('user_data.names', 'user_data.lastname',
        'user_data.dni','internet_plans.plan_name','internet_plans.monthly_price',
        'user_data.address','user_data.phone','user_data.email','cab_facturations.date_init_facturation','det_facturations.price_discount','det_facturations.number_facture')
        ->join('user_data', 'users.id', '=', 'user_data.user_id')
        ->join('internet_plans', 'internet_plans.id', '=', 'user_data.internet_plans_id')
        ->join('cab_facturations', 'cab_facturations.user_id', '=', 'user_data.user_id')
        ->join('det_facturations', 'det_facturations.cab_id', '=', 'cab_facturations.id')
        ->where('det_facturations.number_facture', $id)
        ->first();
    }
}

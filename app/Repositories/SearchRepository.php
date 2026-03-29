<?php

namespace App\Repositories;

use App\Http\Requests\Search\SearchRequest;
use App\Models\User;
use App\Models\UserData;
use App\Models\DetFacturation;
use App\Models\CabFacturation;
use App\Repositories\Interfaces\SearchRepositoryInterface;
use Illuminate\Support\Facades\DB;

class SearchRepository implements SearchRepositoryInterface
{
    /**
     * @return mixed
     */
    public function getSearchUseCase(SearchRequest $searchRequest): mixed
    {
        $keyword = $searchRequest['value'];

        return User::select('users.id','user_data.names','user_data.lastname','user_data.address','user_data.dni','user_data.email','user_data.phone'
        ,'internet_status.name as internet_status','internet_plans.plan_name',
        //'tabla_ips.name as ip',
        'cab_facturations.id as id_cab')
        ->join('user_data', 'users.id', 'user_data.user_id')
        ->join('internet_status', 'user_data.status_internet_id', 'internet_status.id')
        ->join('internet_plans', 'user_data.internet_plans_id', 'internet_plans.id')
        //->join('tabla_ips', 'user_data.ip_assignment_id', 'tabla_ips.id')
        ->join('cab_facturations', 'users.id', 'cab_facturations.user_id')
        ->where('user_data.active', 1)
        ->where(function ($query) use ($keyword) {
            $query->where('user_data.email', 'like', '%'.$keyword.'%')
                  ->orWhere('user_data.dni', 'like', '%'.$keyword.'%')
                  ->orWhere('user_data.names', 'like', '%'.$keyword.'%')
                  ->orWhere('user_data.lastname', 'like', '%'.$keyword.'%');
        })
        ->get();
    
    }

    /**
     * @return mixed
     */
    public function SearchFinancesPaid(SearchRequest $searchRequest): mixed
        {
            $keyword = $searchRequest['value'];
            $resultados = DB::table('user_data as us')
                ->join('cab_facturations as cb', 'cb.user_id', '=', 'us.user_id')
                ->join('det_facturations as dt', 'cb.id', '=', 'dt.cab_id')
                ->where('dt.paid', 0)
                ->select([
                    'cb.date_init_facturation',
                    'us.names',
                    'us.lastname',
                    'us.dni',
                    'us.user_id',
                    'us.phone',
                    'us.email',
                    'us.address',
                    'dt.paid',
                    'dt.price_total',
                    'dt.id',
                    'dt.price_abone',
                    'dt.cab_id',
                    'dt.price_discount'
                ])
                ->where(function ($query) use ($keyword) {
                    $query->where('us.email', 'like', '%'.$keyword.'%')
                          ->orWhere('us.names', 'like', '%'.$keyword.'%')
                          ->orWhere('us.dni', 'like', '%'.$keyword.'%')
                          ->orWhere('us.lastname', 'like', '%'.$keyword.'%');
                })
                ->get();
    $groupedResults = [];
    $monthPedding = 1;

    foreach ($resultados as $item) {
        $userId = $item->user_id;
        if (!isset($groupedResults[$userId])) {
            $groupedResults[$userId] = $item;
            $groupedResults[$userId]->price_discount;
            $groupedResults[$userId]->price_total = $item->price_total;
    
            $groupedResults[$userId]->monthPedding = $monthPedding;
        } else {
            $groupedResults[$userId]->price_discount += $item->price_discount;
            $groupedResults[$userId]->price_total += $item->price_total;
    
    
            $groupedResults[$userId]->monthPedding += $monthPedding;
    
        }
    }
    
     $finalResults = array_values($groupedResults);
    
     
    
        return $finalResults;
    
        }
    
    
}

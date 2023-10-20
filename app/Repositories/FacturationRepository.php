<?php

namespace App\Repositories;

use App\Models\DetFacturation;
use App\Models\CabFacturation;
use App\Http\Requests\Facturation\CreateFacturationRequest;
use Illuminate\Support\Facades\Hash;
use App\Repositories\Interfaces\FacturationRepositoryInterface;
use Carbon\Carbon;



class FacturationRepository implements FacturationRepositoryInterface{



     /**
     * Método encargado de obtener los datos de un usuario por medio del nombre
     * de usuario
     *
     /**
     * @param string $id_user
     * @return mixed
     */
    public function getCabUserFacturation(string $id_user): mixed
    {
        return CabFacturation::where("user_id", $id_user)->first();
    }

    /**
     * Método encargado de obtener los datos de un usuario por medio del nombre
     * de usuario
     *
     /**
     * @param string $id_user
     * @return mixed
     */
    public function getDateFacturePending(): mixed
    {
        $data = CabFacturation::select('cab_facturations.date_init_facturation', 'user_data.names', 'user_data.dni', 'cab_facturations.created_at')
        ->join('user_data', 'cab_facturations.user_id', '=', 'user_data.user_id')
        ->get()
        ->map(function ($facturation) {
            $dateInit = Carbon::parse($facturation->date_init_facturation);
            $daysDiff = $dateInit->diffInDays(Carbon::now());
            $facturation->daysDiffs = $daysDiff;
            return $facturation;
        })
        ->filter(function ($facturation) {
            // Filtrar los registros con una diferencia de 30 días o más
            return $facturation->daysDiffs >= 29;
        });

        $det =  DetFacturation::where('abone', '>', 0)->first();
    
    $response = [
        'resp' => $data->values()->all(),
        'respdet' => $det,
    ];
    
    return response()->json($response);
    }

    /**
     * Método encargado de obtener los datos de un usuario por medio del nombre
     * de usuario
     *
     /**
     * @param string $id_user
     * @return mixed
     */
    public function createCabFacturation(string $id_user): mixed
    {
        return CabFacturation::create([
            'user_id' => $id_user,
            'date_init_facturation' => now()
        ]);
    }

    /**
     * @param CreateFacturationRequest $data
     * @return mixed
     */
    public function createDetFacturation(CreateFacturationRequest $data): mixed
    {
        return DetFacturation::create([
            'cab_id' => $data['cab_id'],
            'date_facturation' => $data['date_facturation'],
            'number_facture' => $data['number_facture'],
            'date_create_facturation' => $data['date_create_facturation'],
            'total' => $data['total'],
            'price_total' => $data['price_total'],
            'abone' => $data['abone'],
            'price_abone' => $data['price_abone'],
            'discount' => $data['discount'],
            'price_discount' => $data['price_discount'],
        ]);
    }
}


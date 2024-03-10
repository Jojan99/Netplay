<?php

namespace App\Repositories;

use App\Constants\ApiResponseConstants;
use App\Models\DetFacturation;
use App\Models\CabFacturation;
use App\Http\Requests\Facturation\CreateFacturationRequest;
use App\Models\UserData;
use Illuminate\Support\Facades\Hash;
use App\Repositories\Interfaces\FacturationRepositoryInterface;
use Carbon\Carbon;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\DB;

class FacturationRepository implements FacturationRepositoryInterface
{



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
    public function getuserFacture1(): mixed
    {
        return CabFacturation::where("group", 1)->get();
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

        $data = DetFacturation::select('det_facturations.number_facture','det_facturations.cab_id', 'cab_facturations.user_id', 'user_data.names', 'user_data.dni', 'internet_plans.monthly_price', 'cab_facturations.date_init_facturation', 'det_facturations.porcentage_discount', 'det_facturations.price_discount', 'det_facturations.price_total')
            ->join('cab_facturations', 'det_facturations.cab_id', '=', 'cab_facturations.id')
            ->join('user_data', 'cab_facturations.user_id', '=', 'user_data.user_id')
            ->join('internet_plans', 'user_data.internet_plans_id', '=', 'internet_plans.id')
            ->get();

        foreach ($data as $item) {
            $dateInitFacturation = Carbon::parse($item->date_init_facturation)->format('Y-m-d');
            $dateInitFacturation1 = Carbon::parse($dateInitFacturation);

            $item->diamesantes = $dateInitFacturation1->day;

            $fechaActual = Carbon::parse(Carbon::now()->format('Y-m-d'));
            $item->mescorte = $dateInitFacturation1->copy()->addMonth();

            // Calcular la diferencia en meses
            $diferenciaEnMeses = $fechaActual->diffInDays($dateInitFacturation);

            $mesactual = $fechaActual->month;
            $mescorte = $dateInitFacturation1->month;
            $yearactual = $fechaActual->year;
            $yearcorte = $dateInitFacturation1->year;
            $dayscorte = $dateInitFacturation1->day;
            $daysactual = $fechaActual->day;


            $diferenciaMes = $mesactual - $mescorte;
            $diferenciaYear = $yearcorte - $yearactual;
            $diferenciaDay = $dayscorte - $daysactual;

            if ($diferenciaMes == 0 && $diferenciaYear == 0 && $diferenciaDay < 0) {
                error_log("entra 0");
                $diferenciaEnMeses = -$diferenciaEnMeses;
            } elseif ($diferenciaMes != 0 && $diferenciaYear == 0) {
                $diferenciaEnMeses = -$diferenciaEnMeses;
                error_log("entra 1");

            }

            $item->diferencia = $diferenciaEnMeses;
            $item->dateInitFacturation = $dateInitFacturation;
            $item->mescorte = date('Y-m-d', strtotime($dateInitFacturation . ' +1 month'));
        }

        return $data;
    }

    /**
     * Método encargado de obtener los datos de un usuario por medio del nombre
     * de usuario
     *
     /**
     * @param string $id_user
     * @return mixed
     */
    public function getDataInfoPenddingFacture() : mixed
    {
        $resultados = DB::table('user_data as us')
        ->join('cab_facturations as cb', 'cb.user_id', '=', 'us.user_id')
        ->select([
            'cb.date_init_facturation',
            'us.names',
            'us.lastname',
            'us.dni',
            'us.user_id',
            'us.phone',
            'us.email',
            'us.address',
            DB::raw('DATEDIFF(NOW(), cb.date_init_facturation) AS fecha'),
            DB::raw('IF(DATEDIFF(NOW(), cb.date_init_facturation) <= 5, "Mostrar", "No Mostrar") AS condicion')
        ])
        ->whereRaw('DATEDIFF(NOW(), cb.date_init_facturation) <= 5')
        ->get();
    
    // Agrega los resultados filtrados al array original

    return $resultados;

    }

    /**
     * Método encargado de obtener los datos de un usuario por medio del nombre
     * de usuario
     *
     /**
     * @param string $id_user
     * @return mixed
     */
    public function getPricePlan(string $id_user): mixed
    {

        return UserData::select('internet_plans.monthly_price')
            ->join('internet_plans', 'internet_plans.id', '=', 'user_data.internet_plans_id')
            ->where('user_data.user_id', $id_user)
            ->first();
    }


    public function getDateLast(string $id_user): mixed
    {

        return DetFacturation::select('det_facturations.date_facturation')
            ->join('cab_facturations', 'cab_facturations.id', '=', 'det_facturations.cab_id')
            ->where('cab_facturations.user_id', $id_user)
            ->orderBy('det_facturations.date_facturation', 'desc')
            ->first();
    }
    /**
     * Método encargado de obtener los datos de un usuario por medio del nombre
     * de usuario
     *
     /**
     * @param string $id_user
     * @return mixed
     */
    public function createCabFacturation(string $id_user, string $cutoffDate): mixed
    {
        return CabFacturation::create([
            'user_id' => $id_user,
            'date_init_facturation' => '2024-03-15'
        ]);
    }

    /**
     * @param CreateFacturationRequest $data
     * @return mixed
     */
    public function createDetFacturation(CreateFacturationRequest $data): mixed
    {
        $randomNumber = rand(100, 1000000);

        // Obtiene una letra aleatoria
        $randomLetters = chr(rand(65, 90)) . chr(rand(65, 90)) . chr(rand(65, 90));

        $randomCombination = $randomLetters . $randomNumber;

        return DetFacturation::create([
            'cab_id' => $data['cab_id'],
            'date_facturation' => $data['date_facturation'],
            'number_facture' => $randomCombination,
            'date_create_facturation' => $data['date_create_facturation'],
            'total' => $data['total'],
            'price_total' => $data['price_total'],
            'price_abone' => 0,
            'discount' => $data['discount'],
            'price_discount' => $data['price_discount'],
            'days_facture' => $data['days_facture'],
            'porcentage_discount' => $data['porcentage_discount'],
            'paid' => 0,
        ]);
    }
}

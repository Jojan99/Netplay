<?php

namespace App\Repositories;

use App\Models\DetFacturation;
use App\Models\CabFacturation;
use App\Http\Requests\Facturation\CreateFacturationRequest;
use App\Http\Requests\Facturation\CreatePaidFacturationRequest;
use App\Http\Requests\Facturation\GetDateFacturePendingnRequest;
use App\Models\UserData;
use App\Repositories\Interfaces\FacturationRepositoryInterface;
use Illuminate\Support\Facades\DB;

class FacturationRepository implements FacturationRepositoryInterface
{

    /**
     * Método encargado de obtener los datos de un usuario por medio del nombre
     * de usuario
     *
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
     * @param string $group
     * @return mixed
     */
    public function getuserFacture1($periodo): mixed
    {

        return CabFacturation::select('internet_plans.monthly_price','cab_facturations.id',
        'cab_facturations.user_id','cab_facturations.date_init_facturation',
        'cab_facturations.created_at')
        ->join('user_data', 'user_data.user_id', '=', 'cab_facturations.user_id')
        ->join('users', 'users.id', '=', 'user_data.user_id')
        ->join('internet_plans', 'internet_plans.id', '=', 'user_data.internet_plans_id')
        ->where('cab_facturations.group', $periodo)
        ->where('user_data.active', 1)
        ->where('users.company_id', getSessionCompanyId())
        ->get();
    }
    /**
     * Método encargado de obtener los datos de un usuario por medio del nombre
     * de usuario
     *
     * @param string $idUser
     * @return mixed
     */
    public function getuserFactureCreate($idUser): mixed
    {

        return CabFacturation::select('internet_plans.monthly_price','cab_facturations.id',
        'cab_facturations.user_id','cab_facturations.date_init_facturation',
        'cab_facturations.created_at')
        ->join('user_data', 'user_data.user_id', '=', 'cab_facturations.user_id')
        ->join('users', 'users.id', '=', 'user_data.user_id')
        ->join('internet_plans', 'internet_plans.id', '=', 'user_data.internet_plans_id')
        ->where('user_data.user_id', $idUser)
        ->where('user_data.active', 1)
        ->where('users.company_id', getSessionCompanyId())
        ->get();
    }



/**
     * Método encargado de obtener los datos de un usuario por medio del nombre
     * de usuario
     *
     * @param string $id_user
     * @return mixed
     */
    public function getDatePayFacture(GetDateFacturePendingnRequest $data): mixed
{
    $query = DetFacturation::select(
        'id',
        'cab_id',
        'date_facturation',
        'number_facture',
        'date_create_facturation',
        'total',
        'price_total',
        'porcentage_discount',
        'days_facture',
        'discount',
        'price_discount',
        'create_facture_manual',
        'paid',
        'price_abone',
        'abone',
        DB::raw('price_total - price_abone as balance') // Campo adicional
    )
    ->where('cab_id', $data['cab_id']);
    
        error_log($data['value']);

    // Aplicar filtro condicional sobre el campo "paid"
    if ($data['value'] == 2) {
        // Facturas no pagadas
        $query->where('paid', 1);
    } elseif ($data['value'] == 3) {
        // Facturas pagadas
        $query->where('paid', 0);
    }

    return $query->get();
}


    /**
     * Método encargado de obtener los datos de un usuario por medio del nombre
     * de usuario
     *
     * @param string $id_user
     * @return mixed
     */
    public function getDateFacturePending(GetDateFacturePendingnRequest $data): mixed
    {
            return DetFacturation::select(
            'id',
            'cab_id',
            'date_facturation',
            'number_facture',
            'date_create_facturation',
            'total',
            'price_total',
            'porcentage_discount',
            'days_facture',
            'discount',
            'price_discount',
            'create_facture_manual',
            'paid',
            'price_abone',
            'abone',
            DB::raw('price_total - price_abone as balance'),

            // Total pendiente
            DB::raw('(SELECT SUM(price_total - price_abone) 
                    FROM det_facturations 
                    WHERE cab_id = '.$data['cab_id'].' AND paid = 0) as total_pending'),

            // Cantidad de facturas pendientes
            DB::raw('(SELECT COUNT(*) 
                    FROM det_facturations 
                    WHERE cab_id = '.$data['cab_id'].' AND paid = 0) as months_pending')
        )
        ->where("cab_id", $data['cab_id'])
        ->where("paid", 0)
        ->get();

        // return DetFacturation::select('det_facturations.cab_id','det_facturations.date_create_facturation'
        // ,'det_facturations.date_facturation','det_facturations.days_facture','det_facturations.discount','det_facturations.id',
        // 'det_facturations.number_facture','det_facturations.paid','det_facturations.porcentage_discount','det_facturations.price_discount',
        // 'det_facturations.price_total','det_facturations.total','user_data.names')
        // ->join('cab_facturations', 'det_facturations.cab_id', '=', 'cab_facturations.id')
        // ->join('user_data', 'cab_facturations.user_id', '=', 'user_data.user_id')
        // ->where("det_facturations.cab_id", $data['cab_id'])
        // ->where("det_facturations.paid", 0)
        // ->distinct()
        // ->get();
    }


    

    public function getDateFacturePendingById($det_id): mixed
    {
        return DetFacturation::where("id", $det_id)
        ->first();
    }

    /**
     * Método encargado de obtener los datos de un usuario por medio del nombre
     * de usuario
     *
     * @param string $id_user
     * @return mixed
     */
    public function getDataInfoPenddingFacture() : mixed
    {
        $resultados = DB::table('user_data as us')
    ->join('cab_facturations as cb', 'cb.user_id', '=', 'us.user_id')
    ->join('det_facturations as dt', 'cb.id', '=', 'dt.cab_id')
    ->join('users', 'users.id', '=', 'us.user_id')
    ->where('users.company_id', getSessionCompanyId())
    ->where('dt.paid', 0)
    // ->whereIn('us.dni', [
    //     '5326221',
    //     '72140391',
    //     '1131429214',
    //     '39002007',
    //     '22564061',
    //     '32729996',
    //     '57306958',
    //     '1148151504',
    //     '1143149563',
    //     '1129519530',
    //     '1043162548',
    //     '55301290',
    //     '1454399',
    //     '22478375',
    //     '77190705',
    //     '37274715',
    //     '1079884542',
    //     '1129521036',
    //     '1002463514',
    //     '1102892832',
    //     '1143466837',
    //     '73230365',
    //     '72185079',
    //     '22668642',
    //     '8711579',
    //     '72268765',
    //     '1193640046',
    //     '55238274',
    //     '22648431',
    //     '5032238',
    //     '72159056',
    //     '8500997',
    //     '1001995351',
    //     '1129514030',
    //     '27041818',
    //     '25952258',
    //     '1051417604',
    //     '5614184',
    //     '1045739786',
    //     '1143136520',
    //     '1001782941',
    //     '7206153',
    //     '8697607',
    //     '19059146',
    //     '30871535'
    // ])
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
        'dt.cab_id',
        'dt.price_discount',
        'dt.price_abone'
    ])
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

    /**
     * Método encargado de obtener los datos de un usuario por medio del nombre
     * de usuario
     *
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
     * @param string $id_user
     * @return mixed
     */
    public function createCabFacturation(string $id_user, int $group,string $fecha): mixed
    {

        return CabFacturation::create([
            'user_id' => $id_user,
            'date_init_facturation' => $fecha,
            'group' => $group
        ]);
    }

    /**
     * @param CreateFacturationRequest $data
     * @return mixed
     */
    public function createDetFacturation(CreateFacturationRequest $data): mixed
    {
        $randomNumber = $this->getConsecutiveFacture();

        $randomCombination = "NT" . $randomNumber;

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
            'paid' => 0,
            'create_facture_manual' => $data['create_facture_manual'],
            'porcentage_discount' => $data['porcentage_discount'],
        ]);
    }

     /**
     * @param CreateFacturationRequest $data
     * @return mixed
     */
    public function updateDetFacturation(CreateFacturationRequest $data): mixed
    {

        $detFacture = DetFacturation::where('id', $data['id_facture'])->first();
        if ($detFacture) $detFacture->update([
            'total' => 0,
            'price_total' => intval($data['price_total']),
            'price_abone' => intval($data['price_abone']),
            'discount' => $data['discount'],
            'price_discount' => intval($data['price_discount']),
            'days_facture' => $data['days_facture'],
            'porcentage_discount' => $data['porcentage_discount'],
        ]);

        return true;
    }
     /**
     * @param CreatePaidFacturationRequest $data
     * @return mixed
     */
    public function createpaidFacturation(CreatePaidFacturationRequest $data): mixed
    {
        $user = DetFacturation::where('id', $data['det_id'])->first();
        if ($user) $user->update(
            ['paid' => 1,
            'log_id' =>$data['log_id']]);
        return true;

    }

    /**
     * @return mixed
     */
    public function getConsecutiveFacture(): mixed
    {
        $lastRecord = DetFacturation::orderBy('id', 'desc')->first();
        $lastId = $lastRecord ? $lastRecord->id : null;
        return $lastId + 1;
    }

    /**
     * @return mixed
     */
    public function getConsecutiveFacture1(): mixed
    {
        $lastRecord = DetFacturation::orderBy('id', 'desc')->first();
        $lastId = $lastRecord ? $lastRecord->id : null;
        return $lastId;
    }

    /**
     * @param CreatePaidFacturationRequest $data
     * @return mixed
     */
    public function createAboneFacturation(CreatePaidFacturationRequest $data): mixed
    {
        $user = DetFacturation::where('id', $data['det_id'])->first();
        if ($user) $user->update(
            ['paid' => $data['paid'],
            'abone'=>$data['abone'],
            'log_id'=>$data['log_id'],
            'price_abone'=> $data['price_total'] == null ? 0 : $data['price_total'],
            ]);
        return true;
    }
    
}

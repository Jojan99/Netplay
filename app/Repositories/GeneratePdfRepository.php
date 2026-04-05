<?php

namespace App\Repositories;

use App\Models\User;
use App\Models\CabFacturation;
use App\Models\Ticket;
use App\Repositories\Interfaces\GeneratePdfRepositoryInterface;
use Illuminate\Support\Facades\DB;

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
    public function getUserPeriode1($Periodo, int $companyId): mixed
    {
        return CabFacturation::where('group', $Periodo)
            ->where('company_id', $companyId)
            ->get();
    }

     /**
     * @return mixed
     */
    public function getperiodeNotificationRemenber(): mixed
    {

        return CabFacturation::join('user_data as us', 'us.user_id', '=', 'cab_facturations.user_id')
        ->join('det_facturations as det', 'det.cab_id', '=', 'cab_facturations.id')
        ->where('cab_facturations.group', 2)
        ->where('us.active', 1)
        ->where('det.date_facturation', '2025-02-28')
        ->select('cab_facturations.user_id')
        ->get();
        
    }

     /**
     * @return mixed
     */
    public function getSaldoAnt($id_cab,$numberFactura): mixed
{
    return DB::table('det_facturations')
    ->select(DB::raw('SUM(price_total - price_discount - price_abone) as priceantfactura'))
    ->where('cab_id', $id_cab)
    ->where('paid', 0)
    ->where('number_facture', '!=', $numberFactura)
    ->value('priceantfactura');
}
 /**
     * @return mixed
     */
    public function getPaySaldoAnt($id_cab,$numberFactura): mixed
{
    return DB::table('det_facturations')
    ->select(DB::raw('SUM(price_total - price_discount - price_abone) as priceantfactura'))
    ->where('cab_id', $id_cab)
    ->where('paid', '!=', 1)
    // ->where('number_facture', '!=', $numberFactura)
    ->value('priceantfactura');
}

    public function generatePdf($data): mixed
    {
        $select = [];

        foreach ($data as $data) {

            $result = User::select(
                'user_data.names', 'user_data.lastname',
                'user_data.dni', 'internet_plans.plan_name', 'internet_plans.monthly_price',
                'user_data.address', 'user_data.phone', 'user_data.email', 'cab_facturations.date_init_facturation',
                'det_facturations.price_discount', 'det_facturations.number_facture', 'det_facturations.date_facturation',
                'det_facturations.price_total', 'det_facturations.days_facture', 'det_facturations.create_facture_manual',
                'det_facturations.porcentage_discount', 'cab_facturations.id','cab_facturations.billing_electronic'
            )
            ->join('user_data', 'users.id', '=', 'user_data.user_id')
            ->join('internet_plans', 'internet_plans.id', '=', 'user_data.internet_plans_id')
            ->join('cab_facturations', 'cab_facturations.user_id', '=', 'user_data.user_id')
            ->join('det_facturations', 'det_facturations.cab_id', '=', 'cab_facturations.id')
            ->where('user_data.user_id', $data['user_id'])
            ->where('user_data.active', 1)
            ->orderBy('det_facturations.date_facturation', 'desc') // Ordenar por fecha descendente
            ->limit(1) // Obtener solo un registro
            ->get();
        

            $select = array_merge($select, $result->toArray());
        }

        return $select;
    
    }



    public function generatePdfRemember($data): mixed
    {
        $select = [];

        foreach ($data as $data) {

            $result = User::select(
                'user_data.names', 'user_data.lastname',
                'user_data.dni', 'internet_plans.plan_name', 'internet_plans.monthly_price',
                'user_data.address', 'user_data.phone', 'user_data.email', 'cab_facturations.date_init_facturation',
                'det_facturations.price_discount', 'det_facturations.number_facture', 'det_facturations.date_facturation',
                'det_facturations.price_total', 'det_facturations.days_facture', 'det_facturations.create_facture_manual',
                'det_facturations.porcentage_discount', 'cab_facturations.id'
            )
            ->join('user_data', 'users.id', '=', 'user_data.user_id')
            ->join('internet_plans', 'internet_plans.id', '=', 'user_data.internet_plans_id')
            ->join('cab_facturations', 'cab_facturations.user_id', '=', 'user_data.user_id')
            ->join('det_facturations', 'det_facturations.cab_id', '=', 'cab_facturations.id')
            ->where('user_data.user_id', $data->user_id)
            ->where('user_data.active', 1)
            ->orderBy('det_facturations.date_facturation', 'desc') // Ordenar por fecha descendente
            ->limit(1) // Obtener solo un registro
            ->get();
        

            $select = array_merge($select, $result->toArray());
        }

        return $select;
    
    }

    public function generatePdfById($id): mixed{
        return User::select('user_data.names', 'user_data.lastname',
        'user_data.dni','internet_plans.plan_name','internet_plans.monthly_price',
        'user_data.address','user_data.phone','user_data.email','cab_facturations.date_init_facturation'
        ,'det_facturations.price_discount','det_facturations.create_facture_manual','det_facturations.number_facture','det_facturations.date_facturation'
        ,'det_facturations.price_total','det_facturations.days_facture','det_facturations.porcentage_discount',
        'cab_facturations.id','det_facturations.price_abone','det_facturations.abone','det_facturations.updated_at')
        ->join('user_data', 'users.id', '=', 'user_data.user_id')
        ->join('internet_plans', 'internet_plans.id', '=', 'user_data.internet_plans_id')
        ->join('cab_facturations', 'cab_facturations.user_id', '=', 'user_data.user_id')
        ->join('det_facturations', 'det_facturations.cab_id', '=', 'cab_facturations.id')
        ->where('det_facturations.number_facture', $id)
        ->first();
    }


     /**
     * @return mixed
     */
    public function getTicketInProgressAll($id): mixed
    {
        return Ticket::select(
            'ticket_status.name as status',
            'user_data_user.names as user_names', 
            'user_data_user.lastname as user_lastname', 
            'ticket_type_prioritys.name as prioritys', 
            'ticket_type_services.name as service', 
            'tickets.address', 
            'tickets.id', 
            'tickets.date', 
            'tickets.phone', 
            'tickets.cedula', 
            'tickets.observation', 
            'user_data_tech.names as tech_names', 
            'user_data_tech.lastname as tech_lastname'
        )
        ->join('ticket_status', 'ticket_status.id', '=', 'tickets.status_id')
        ->join('user_data as user_data_user', 'user_data_user.user_id', '=', 'tickets.user_id') // Datos del usuario
        ->join('user_data as user_data_tech', 'user_data_tech.user_id', '=', 'tickets.technical_id') // Datos del técnico
        ->join('ticket_type_prioritys', 'ticket_type_prioritys.id', '=', 'tickets.priority_id')
        ->join('ticket_type_services', 'ticket_type_services.id', '=', 'tickets.service_id')
        ->where('tickets.id', $id)
        ->get();
    
    }
  
}

<?php

namespace App\Repositories;

use App\Http\Requests\Egresos\CreateEgresosRequest;
use App\Models\Countrie;
use App\Models\Egresses;
use App\Repositories\Interfaces\EgresosRepositoryInterface;
use App\Repositories\Interfaces\LocationRepositoryInterface;
use Illuminate\Support\Facades\DB;

use function Laravel\Prompts\error;

class EgresosRepository implements EgresosRepositoryInterface
{
  /**
     * @param int $sponsor_id
     * @param CreateEgresosRequest $data
     * @return mixed
     */
    public function createEgresos(CreateEgresosRequest $data): mixed
    {
        return Egresses::create([
            'id_category_egresses' => 1,
            'concept' => $data['concept'],
            'value' => $data['value'],
            'user_id' => $data['user_id'],
        ]);
    }

        /**
     * @return mixed
     */
    public function getEgresosAll(): mixed
    {
        return Egresses::all();
    }

        /**
     * @return mixed
     */
    public function getCategory(): mixed
    {
        return Egresses::all();
    }


            /**
     * @return mixed
     */
    public function getPriceEgresseAll(): mixed
    {
        // Realizar la consulta
      $results = DB::table('det_facturations')
    ->selectRaw('
        SUM(CASE 
            WHEN abone != 1 AND paid = 1 THEN (price_total - price_discount) 
            ELSE 0 
        END) AS valor_ingreso,
        SUM(CASE 
            WHEN abone = 1 AND paid != 1 THEN price_abone 
            ELSE 0 
        END) AS valor_abone,
        SUM(CASE 
            WHEN abone != 1 AND paid = 1 THEN (price_total - price_discount) 
            ELSE 0 
        END) +
        SUM(CASE 
            WHEN abone = 1 AND paid != 1 THEN price_abone 
            ELSE 0 
        END) AS total_sum,
        (SELECT SUM(value) 
         FROM egresses
        ) AS total_egresses,
        (
            SUM(CASE 
                WHEN abone != 1 AND paid = 1 THEN (price_total - price_discount) 
                ELSE 0 
            END) +
            SUM(CASE 
                WHEN abone = 1 AND paid != 1 THEN price_abone 
                ELSE 0 
            END)
        ) - 
        (
            SELECT SUM(value) 
            FROM egresses
        ) AS net_value
    ')
    ->get();

return $results;

    }
}

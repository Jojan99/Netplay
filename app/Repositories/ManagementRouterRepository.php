<?php

namespace App\Repositories;

use App\Http\Requests\Gestions\GestionUserRequest;
use App\Models\Countrie;
use App\Models\DetFacturation;
use App\Models\UserData;
use App\Repositories\Interfaces\ManagementRouterRepositoryInterface;
use Illuminate\Foundation\Mix;
use InvalidArgumentException;

class ManagementRouterRepository implements ManagementRouterRepositoryInterface
{
/**
     * @param GestionUserRequest|array $data
     * @return array
     */
    public function UpdateStatus(GestionUserRequest|array $data): array
    {
        // 🔹 Normalizar entrada
        if ($data instanceof GestionUserRequest) {
            $idUser = $data->id_user;
            $status = $data->status;
        } elseif (is_array($data)) {
            $idUser = $data['id_user'] ?? null;
            $status = $data['status'] ?? null;
        } else {
            throw new InvalidArgumentException('Tipo de dato no soportado');
        }

        // 🔹 Validación defensiva mínima
        if (!$idUser || $status === null) {
            throw new InvalidArgumentException('Datos incompletos para UpdateStatus');
        }

        // 🔹 Lógica existente (NO se rompe)
        $user = UserData::select('user_data.user_id')
            ->join('users', 'users.id', '=', 'user_data.user_id')
            ->where('user_data.dni', $data['id_user']) // asumo que ip = dni
            ->first();

        UserData::where('user_id', $data['id_user'])
        ->update([
            'status_internet_id' => $status
        ]);

          return [
        'user_id'  => $data['id_user'] ?? null,
        'username' => $data['username'] ?? null,
        'status'   => $status
    ];
    }

  /**
     * @return mixed
     * @param GestionUserRequest $gestionUserRequest
     */
public function GetUsersPendding(): array
{
    return DetFacturation::from('det_facturations as det')
        ->join('cab_facturations as cab', 'det.cab_id', '=', 'cab.id')
        ->join('users as us', 'us.id', '=', 'cab.user_id')
        ->where('det.paid', '<>', 1)
        //->where('us.username', 'JOHNNY JAVIER ORTEGA CASTRO-10004')
        ->distinct()
        ->select([
            'us.username',
            'us.id as idUser'
        ])
        ->get()
        ->toArray();
}

}

<?php

namespace App\Repositories;

use App\Http\Requests\Gestions\GestionUserRequest;
use App\Models\Countrie;
use App\Models\DetFacturation;
use App\Models\UserData;
use App\Repositories\Interfaces\ManagementRouterRepositoryInterface;
use Illuminate\Foundation\Mix;
use Illuminate\Support\Facades\DB;
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
        // Aislamiento por empresa: sin el filtro, un cambio de estado podía caer
        // sobre el cliente de otra empresa que tuviera la misma cédula.
        $companyId = getSessionCompanyId();
        $user = UserData::select('user_data.user_id')
            ->join('users', 'users.id', '=', 'user_data.user_id')
            ->where('user_data.dni', $data['id_user']) // asumo que ip = dni
            ->when($companyId, fn($q) => $q->where('users.company_id', $companyId))
            ->first();

        $oldStatusId = UserData::where('user_id', $idUser)
            ->when($companyId, fn($q) => $q->where('company_id', $companyId))
            ->value('status_internet_id') ?? 1;

        UserData::where('user_id', $data['id_user'])
        ->when($companyId, fn($q) => $q->where('company_id', $companyId))
        ->update([
            'status_internet_id' => $status
        ]);

        // Audit log for internet status change
        DB::table('user_audit_logs')->insert([
            'user_id'       => $idUser,
            'changed_by'    => getSessionUserId(),
            'company_id'    => getSessionCompanyId(),
            'field_changed' => 'estado_internet',
            'old_value'     => $oldStatusId == 1 ? 'ACTIVE' : 'INACTIVE',
            'new_value'     => $status == 1 ? 'ACTIVE' : 'INACTIVE',
            'description'   => self::descripcionDelCambio($data, (int) $status),
            'created_at'    => now(),
            'updated_at'    => now(),
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
    // Con alias, el filtro de anuladas del modelo apunta a un nombre de tabla que ya no
    // existe en la consulta y la rompe: se quita y se pone a mano sobre el alias.
    return DetFacturation::conAnuladas()->from('det_facturations as det')
        ->join('cab_facturations as cab', 'det.cab_id', '=', 'cab.id')
        ->join('users as us', 'us.id', '=', 'cab.user_id')
        ->where('det.paid', '<>', 1)
        ->whereNull('det.anulada_en')
        ->distinct()
        ->select([
            'us.username',
            'us.id as idUser',
            'us.company_id',
        ])
        ->get()
        ->toArray();
}


    /** Lo que queda en el historial del cliente: manual, con el motivo que escribió el operador. */
    private static function descripcionDelCambio($data, int $status): string
    {
        $leer = fn (string $k) => is_array($data) ? ($data[$k] ?? null) : ($data->{$k} ?? null);
        $motivo = trim((string) $leer('motivo'));
        $noReactivar = $status != 1 && filter_var($leer('no_reactivar') ?? false, FILTER_VALIDATE_BOOLEAN);

        return mb_substr(($status == 1 ? 'Reactivación manual' : 'Suspensión manual')
            . ($noReactivar ? ' · no reactivar automáticamente' : '')
            . ($motivo !== '' ? ' · ' . $motivo : ''), 0, 250);
    }
}

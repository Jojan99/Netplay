<?php

namespace App\UseCases\Company;

use App\Http\Requests\Company\UpdateBillingConfigRequest;
use App\Models\CompanyBillingSchedule;
use App\UseCases\Company\Interfaces\UpdateBillingConfigUseCaseInterface;
use Illuminate\Database\QueryException;

class UpdateBillingConfigUseCase implements UpdateBillingConfigUseCaseInterface
{
    public function update(UpdateBillingConfigRequest $request): mixed
    {
        try {
            $companyId = getSessionCompanyId();

            // Validar que no haya grupos duplicados en el mismo request
            $grupos = array_column($request['schedules'], 'grupo');
            if (count($grupos) !== count(array_unique($grupos))) {
                return ['message' => 'No puede haber grupos duplicados en la configuración', 'data' => null, 'status' => 1];
            }

            foreach ($request['schedules'] as $schedule) {
                CompanyBillingSchedule::updateOrCreate(
                    ['company_id' => $companyId, 'grupo' => $schedule['grupo']],
                    [
                        'billing_day'  => $schedule['billing_day'],
                        'billing_hour' => $schedule['billing_hour'],
                        'active'       => $schedule['active'],
                    ]
                );
            }

        } catch (QueryException $e) {
            return ['message' => 'Error al guardar la configuración: ' . $e->getMessage(), 'data' => null, 'status' => 1];
        }

        $schedules = CompanyBillingSchedule::where('company_id', $companyId)
            ->orderBy('grupo')
            ->get();

        return ['message' => 'Configuración guardada correctamente', 'data' => $schedules, 'status' => 0];
    }
}

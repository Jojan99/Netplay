<?php

namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateBillingConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'schedules'               => 'required|array|min:1|max:4',
            'schedules.*.grupo'        => 'required|integer|min:1|max:4',
            'schedules.*.billing_day'  => 'required|integer|min:1|max:28',
            'schedules.*.billing_hour' => 'required|integer|min:0|max:23',
            'schedules.*.active'       => 'required|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'schedules.max'                  => 'Se permiten máximo 4 grupos de facturación.',
            'schedules.*.grupo.min'          => 'El grupo debe ser entre 1 y 4.',
            'schedules.*.grupo.max'          => 'El grupo debe ser entre 1 y 4.',
            'schedules.*.billing_day.min'    => 'El día de corte debe ser entre 1 y 28.',
            'schedules.*.billing_day.max'    => 'El día de corte debe ser entre 1 y 28.',
            'schedules.*.billing_hour.min'   => 'La hora debe ser entre 0 y 23.',
            'schedules.*.billing_hour.max'   => 'La hora debe ser entre 0 y 23.',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'message' => $validator->errors()->first(),
            'data'    => null,
            'error'   => 1,
        ], 422));
    }
}

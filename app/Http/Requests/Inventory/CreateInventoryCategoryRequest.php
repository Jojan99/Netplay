<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateInventoryCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        $companyId = getSessionCompanyId();

        return [
            'name'        => [
                'required',
                'string',
                'max:100',
                Rule::unique('inventory_categories')
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at'),
            ],
            'description' => 'nullable|string|max:255',
            // ONT y TV box se registran por serial; conectores y RJ45 por cantidad.
            'usa_serial'  => 'nullable|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre de la categoría es obligatorio.',
            'name.unique'   => 'Ya existe una categoría con este nombre en su empresa.',
        ];
    }
}

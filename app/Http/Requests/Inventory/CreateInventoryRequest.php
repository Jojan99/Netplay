<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateInventoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        $companyId = getSessionCompanyId();

        return [
            'name'        => 'required|string|max:150',
            'description' => 'nullable|string|max:255',
            'category_id' => [
                'nullable',
                'integer',
                Rule::exists('inventory_categories', 'id')
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at'),
            ],
            'sku'         => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('inventories')
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at'),
            ],
            'code'        => 'nullable|string|max:50',
            'quantity'    => 'nullable|numeric|min:0',
            'stock_min'   => 'nullable|numeric|min:0',
            'stock_max'   => 'nullable|numeric|min:0',
            'unit_price'  => 'nullable|numeric|min:0',
            'unit'        => 'nullable|string|max:50',
            'location'    => 'nullable|string|max:100',
        ];
    }

    public function messages(): array
    {
        return [
            'category_id.exists' => 'La categoría seleccionada no existe o no pertenece a su empresa.',
            'sku.unique'         => 'El SKU ya está registrado en su inventario.',
        ];
    }
}

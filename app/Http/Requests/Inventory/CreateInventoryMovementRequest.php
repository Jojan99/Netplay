<?php

namespace App\Http\Requests\Inventory;

use App\Models\InventoryMovement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateInventoryMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        $companyId = getSessionCompanyId();

        return [
            'inventory_id' => [
                'required',
                'integer',
                Rule::exists('inventories', 'id')
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at'),
            ],
            'type'         => ['required', 'string', Rule::in(InventoryMovement::$types)],
            'quantity'     => 'required|numeric|min:0.01',
            'unit_price'   => 'nullable|numeric|min:0',
            'description'  => 'nullable|string|max:255',
            'reference'     => 'nullable|string|max:100',
            'serial_number' => 'nullable|string|max:100',
            'batch_number'  => 'nullable|string|max:50',
            'expiry_date'  => 'nullable|date|after_or_equal:today',
        ];
    }

    public function messages(): array
    {
        return [
            'inventory_id.exists' => 'El ítem de inventario no existe o no pertenece a su empresa.',
            'type.in'             => 'El tipo de movimiento debe ser entrada, salida o ajuste.',
            'quantity.min'        => 'La cantidad debe ser mayor a 0.',
        ];
    }
}

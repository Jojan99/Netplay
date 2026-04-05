<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class CreateInventoryMovementRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'inventory_id' => 'required|integer',
            'type'         => 'required|in:entrada,salida,ajuste',
            'quantity'     => 'required|numeric|min:0',
            'unit_price'   => 'nullable|numeric|min:0',
            'description'  => 'nullable|string|max:255',
            'reference'    => 'nullable|string|max:100',
        ];
    }
}

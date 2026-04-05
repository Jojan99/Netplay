<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class CreateInventoryRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name'        => 'required|string|max:150',
            'description' => 'nullable|string|max:255',
            'category_id' => 'nullable|integer',
            'quantity'    => 'nullable|numeric|min:0',
            'unit_price'  => 'nullable|numeric|min:0',
            'unit'        => 'nullable|string|max:50',
        ];
    }
}

<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateInventoryCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        $companyId = getSessionCompanyId();
        $categoryId = $this->route('id') ?? $this->input('id');

        return [
            'name'        => [
                'sometimes',
                'string',
                'max:100',
                Rule::unique('inventory_categories')
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at')
                    ->ignore($categoryId),
            ],
            'description' => 'nullable|string|max:255',
        ];
    }
}

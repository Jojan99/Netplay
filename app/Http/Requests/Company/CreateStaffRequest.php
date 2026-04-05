<?php

namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class CreateStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'names'      => 'required|string|max:255',
            'lastname'   => 'required|string|max:255',
            'email'      => 'required|email|max:255',
            'username'   => 'required|string|max:100',
            'password'   => 'required|string|min:6',
            'profile_id' => 'required|integer|in:2,3,4',
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

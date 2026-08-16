<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignUserStoresRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'store_ids' => ['required', 'array'],
            'store_ids.*' => ['integer', 'distinct', 'exists:stores,id'],
        ];
    }
}

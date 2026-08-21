<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStoreFeishuDataLinksRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'values' => ['required', 'array'],
            'values.*' => ['nullable', 'string', 'max:4096'],
        ];
    }
}

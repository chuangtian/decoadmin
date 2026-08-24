<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreReputationResourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'description' => ['required', 'string', 'max:5000'],
            'request_type' => ['required', 'in:content,staff,budget,technical,product'],
            'priority' => ['required', 'in:normal,important,urgent'],
            'owner_name' => ['nullable', 'string', 'max:120'],
            'due_date' => ['nullable', 'date'],
        ];
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateReputationResourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'required', 'in:pending,processing,completed,cancelled'],
            'priority' => ['sometimes', 'required', 'in:normal,important,urgent'],
            'owner_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'due_date' => ['sometimes', 'nullable', 'date'],
        ];
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateReputationMentionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'processing_status' => ['sometimes', 'nullable', 'in:pending,resolved'],
            'response_note' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateReputationRiskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'required', 'in:pending,processing,watching,resolved,dismissed'],
            'severity' => ['sometimes', 'required', 'in:low,medium,high,critical'],
            'recommended_action' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}

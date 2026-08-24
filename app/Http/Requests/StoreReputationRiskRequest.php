<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreReputationRiskRequest extends FormRequest
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
            'severity' => ['required', 'in:low,medium,high,critical'],
            'source' => ['required', 'in:trustpilot,website,facebook,reddit,threads,multiple'],
            'recommended_action' => ['nullable', 'string', 'max:5000'],
            'occurred_at' => ['nullable', 'date'],
        ];
    }
}

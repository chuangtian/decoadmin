<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreReputationMentionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'source' => ['required', 'in:trustpilot,website,facebook'],
            'content' => ['required', 'string', 'max:20000'],
            'title' => ['nullable', 'string', 'max:500'],
            'rating' => ['required', 'numeric', 'between:1,5'],
            'published_at' => ['required', 'date'],
            'week_number' => ['nullable', 'integer', 'between:1,53'],
            'model_name' => ['nullable', 'string', 'max:120'],
            'order_reference' => ['nullable', 'string', 'max:255'],
            'processing_status' => ['nullable', 'in:pending,resolved'],
            'response_note' => ['nullable', 'string', 'max:5000'],
            'url' => ['nullable', 'url:http,https', 'max:2048'],
        ];
    }
}

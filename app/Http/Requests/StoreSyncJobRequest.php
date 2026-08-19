<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSyncJobRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'app_installation_id' => ['nullable', 'integer', 'exists:app_installations,id'],
            'type' => ['required', 'string', Rule::in(['products', 'orders', 'customers', 'inventory'])],
            'mode' => ['sometimes', 'string', Rule::in(['full', 'incremental'])],
            'return_to_store' => ['sometimes', 'boolean'],
        ];
    }
}

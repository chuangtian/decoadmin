<?php

namespace App\Http\Requests;

use App\Support\CurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFinanceCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('finance.manage', app(CurrentOrganization::class)->get()) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', Rule::in(['income', 'expense'])],
            'color' => ['nullable', Rule::in(['slate', 'emerald', 'blue', 'amber', 'rose', 'violet'])],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}

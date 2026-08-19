<?php

namespace App\Http\Requests;

use App\Support\CurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFinanceEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('finance.manage', app(CurrentOrganization::class)->get()) ?? false;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(['income', 'expense'])],
            'category_id' => ['required', 'integer'],
            'store_id' => ['nullable', 'integer'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'occurred_on' => ['required', 'date'],
            'description' => ['required', 'string', 'max:500'],
            'reference' => ['nullable', 'string', 'max:150'],
        ];
    }
}

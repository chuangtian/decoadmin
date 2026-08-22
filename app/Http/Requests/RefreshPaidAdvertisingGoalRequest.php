<?php

namespace App\Http\Requests;

use App\Support\CurrentOrganization;
use App\Support\CurrentStore;
use Illuminate\Foundation\Http\FormRequest;

class RefreshPaidAdvertisingGoalRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organization = app(CurrentOrganization::class)->get();
        $store = app(CurrentStore::class)->get();

        return $organization !== null
            && $store !== null
            && ($this->user()?->hasPermission('sync.run', $organization, $store) ?? false);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'tab' => ['required', 'string', 'regex:/^(overall|board-[1-9][0-9]*)$/'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['tab' => trim((string) $this->input('tab'))]);
    }
}

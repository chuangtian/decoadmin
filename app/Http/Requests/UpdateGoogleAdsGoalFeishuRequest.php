<?php

namespace App\Http\Requests;

use App\Support\CurrentOrganization;
use App\Support\CurrentStore;
use Illuminate\Foundation\Http\FormRequest;

class UpdateGoogleAdsGoalFeishuRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organization = app(CurrentOrganization::class)->get();
        $store = app(CurrentStore::class)->get();

        return $organization !== null
            && $store !== null
            && ($this->user()?->hasPermission('store.update', $organization, $store) ?? false);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'app_token' => ['required', 'string', 'max:4096'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'app_token' => trim((string) $this->input('app_token')),
        ]);
    }
}

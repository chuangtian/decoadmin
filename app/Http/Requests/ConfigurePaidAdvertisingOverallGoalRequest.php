<?php

namespace App\Http\Requests;

use App\Support\CurrentOrganization;
use App\Support\CurrentStore;
use Illuminate\Foundation\Http\FormRequest;

class ConfigurePaidAdvertisingOverallGoalRequest extends FormRequest
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
            'feishu_app_token' => ['required', 'string', 'max:4096'],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['feishu_app_token'] as $key) {
            $this->merge([$key => trim((string) $this->input($key))]);
        }
    }
}

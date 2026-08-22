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
            'feishu_table_id' => ['required', 'string', 'max:1024'],
            'feishu_view_id' => ['required', 'string', 'max:1024'],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['feishu_app_token', 'feishu_table_id', 'feishu_view_id'] as $key) {
            $this->merge([$key => trim((string) $this->input($key))]);
        }
    }
}

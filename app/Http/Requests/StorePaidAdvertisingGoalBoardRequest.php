<?php

namespace App\Http\Requests;

use App\Services\PaidAdvertisingGoalService;
use App\Support\CurrentOrganization;
use App\Support\CurrentStore;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePaidAdvertisingGoalBoardRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organization = app(CurrentOrganization::class)->get();
        $store = app(CurrentStore::class)->get();

        return $organization !== null
            && $store !== null
            && ($this->user()?->hasPermission('store.update', $organization, $store) ?? false);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $organization = app(CurrentOrganization::class)->require();
        $store = app(CurrentStore::class)->require();

        return [
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('paid_advertising_goal_boards')->where(
                    fn ($query) => $query
                        ->where('organization_id', $organization->id)
                        ->where('store_id', $store->id),
                ),
            ],
            'type' => ['required', Rule::in(PaidAdvertisingGoalService::TYPES)],
            'feishu_app_token' => ['required', 'string', 'max:4096'],
            'feishu_table_id' => ['required', 'string', 'max:1024'],
            'feishu_view_id' => ['required', 'string', 'max:1024'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => '当前店铺已有同名目标页签。',
            'type.in' => '请选择有效的目标类型。',
        ];
    }

    protected function prepareForValidation(): void
    {
        $keys = ['name', 'type', 'feishu_app_token', 'feishu_table_id', 'feishu_view_id'];

        $this->merge(collect($keys)
            ->mapWithKeys(fn (string $key): array => [$key => trim((string) $this->input($key))])
            ->all());
    }
}

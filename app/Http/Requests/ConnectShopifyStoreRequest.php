<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConnectShopifyStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'shop_domain' => [
                'required',
                'string',
                'max:255',
                'regex:/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/',
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'shop_domain.regex' => '请输入有效的 myshopify.com 店铺域名，例如 example.myshopify.com。',
        ];
    }

    protected function prepareForValidation(): void
    {
        $domain = strtolower(trim((string) $this->input('shop_domain')));
        $domain = preg_replace('#^https?://#i', '', $domain) ?? $domain;
        $domain = rtrim(explode('/', $domain, 2)[0], '.');

        $this->merge(['shop_domain' => $domain]);
    }
}

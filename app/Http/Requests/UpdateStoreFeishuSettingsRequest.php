<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStoreFeishuSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'feishu_enabled' => ['required', 'boolean'],
            'feishu_webhook_url' => ['nullable', 'url:https', 'max:2048', 'required_if:feishu_enabled,true'],
            'feishu_secret' => ['nullable', 'string', 'max:1024'],
        ];
    }
}

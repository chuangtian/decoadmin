<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStoreNotificationSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mail_enabled' => ['required', 'boolean'],
            'mail_host' => ['nullable', 'string', 'max:255', 'required_if:mail_enabled,true'],
            'mail_port' => ['required', 'integer', 'between:1,65535'],
            'mail_encryption' => ['required', Rule::in(['tls', 'ssl', 'none'])],
            'mail_username' => ['nullable', 'string', 'max:255'],
            'mail_password' => ['nullable', 'string', 'max:1024'],
            'mail_from_address' => ['nullable', 'email:rfc', 'max:255', 'required_if:mail_enabled,true'],
            'mail_from_name' => ['nullable', 'string', 'max:255'],
            'mail_recipients' => ['nullable', 'array', 'max:10'],
            'mail_recipients.*' => ['required', 'email:rfc', 'max:255', 'distinct'],
            'feishu_enabled' => ['required', 'boolean'],
            'feishu_webhook_url' => ['nullable', 'url:https', 'max:2048', 'required_if:feishu_enabled,true'],
            'feishu_secret' => ['nullable', 'string', 'max:1024'],
            'notify_sync_failed' => ['required', 'boolean'],
            'notify_webhook_failed' => ['required', 'boolean'],
            'notify_connection_unhealthy' => ['required', 'boolean'],
        ];
    }
}

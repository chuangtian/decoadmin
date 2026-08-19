<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStoreMailSettingsRequest extends FormRequest
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
            'notification_email' => ['nullable', 'email:rfc', 'max:255', 'required_if:mail_enabled,true'],
        ];
    }
}

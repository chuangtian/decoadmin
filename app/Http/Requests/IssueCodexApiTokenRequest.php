<?php

namespace App\Http\Requests;

use App\Models\CodexApiToken;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IssueCodexApiTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'name' => ['required', 'string', 'max:120'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['required', 'string', 'distinct', Rule::in(CodexApiToken::SUPPORTED_ABILITIES)],
            'expires_in_days' => ['required', 'integer', 'min:1', 'max:365'],
            'idempotency_key' => ['required', 'uuid'],
            'confirmation_text' => ['required', Rule::in(['确认签发'])],
        ];
    }
}

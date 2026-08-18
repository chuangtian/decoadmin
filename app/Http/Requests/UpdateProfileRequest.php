<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->user())],
            'password' => ['nullable', 'string', 'min:8'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => '请输入姓名。',
            'email.required' => '请输入邮箱。',
            'email.email' => '请输入有效的邮箱地址。',
            'email.unique' => '该邮箱已被其他用户使用。',
            'password.min' => '密码至少需要 8 个字符。',
        ];
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $user = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user)],
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:8'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'remove_avatar' => ['sometimes', 'boolean'],
            'role_ids' => ['sometimes', 'array'],
            'role_ids.*' => ['integer', 'distinct', 'exists:roles,id'],
            'store_ids' => ['sometimes', 'array'],
            'store_ids.*' => ['integer', 'distinct', 'exists:stores,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'avatar.image' => '请选择有效的图片文件。',
            'avatar.mimes' => '头像仅支持 JPG、PNG 或 WebP 格式。',
            'avatar.max' => '头像大小不能超过 2MB。',
        ];
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUserAvatarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            'avatar.required' => '请选择需要上传的头像。',
            'avatar.image' => '请选择有效的图片文件。',
            'avatar.mimes' => '头像仅支持 JPG、PNG 或 WebP 格式。',
            'avatar.max' => '头像大小不能超过 2MB。',
        ];
    }
}

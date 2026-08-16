<?php

return [
    'required' => '请填写:attribute。',
    'required_without' => '缺少必填的:attribute。',
    'email' => ':attribute格式不正确。',
    'confirmed' => '两次输入的:attribute不一致。',
    'string' => ':attribute必须是文本。',
    'boolean' => ':attribute必须为是或否。',
    'array' => ':attribute格式不正确。',
    'integer' => ':attribute必须是整数。',
    'distinct' => ':attribute存在重复项。',
    'exists' => '选择的:attribute不存在。',
    'in' => '选择的:attribute无效。',
    'unique' => ':attribute已被使用。',
    'alpha_dash' => ':attribute只能包含字母、数字、短横线和下划线。',
    'min' => [
        'string' => ':attribute至少需要 :min 个字符。',
    ],
    'max' => [
        'string' => ':attribute不能超过 :max 个字符。',
    ],
    'attributes' => [
        'name' => '名称',
        'email' => '邮箱',
        'password' => '密码',
        'password_confirmation' => '确认密码',
        'status' => '状态',
        'slug' => '标识',
        'description' => '说明',
        'store_id' => '店铺',
        'store_ids' => '店铺',
        'role_ids' => '角色',
        'permission_ids' => '权限',
    ],
];

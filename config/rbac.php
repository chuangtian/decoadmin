<?php

return [
    'admin' => [
        'name' => env('RBAC_ADMIN_NAME', '平台管理员'),
        'email' => env('RBAC_ADMIN_EMAIL', 'admin@example.com'),
        'password' => env('RBAC_ADMIN_PASSWORD', 'change-me-in-production'),
    ],

    'organization' => [
        'name' => env('RBAC_ORGANIZATION_NAME', '默认组织'),
        'code' => env('RBAC_ORGANIZATION_CODE', 'default'),
    ],
];

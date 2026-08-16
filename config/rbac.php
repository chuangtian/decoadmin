<?php

return [
    'admin' => [
        'name' => env('RBAC_ADMIN_NAME', 'Platform Administrator'),
        'email' => env('RBAC_ADMIN_EMAIL', 'admin@example.com'),
        'password' => env('RBAC_ADMIN_PASSWORD', 'change-me-in-production'),
    ],

    'organization' => [
        'name' => env('RBAC_ORGANIZATION_NAME', 'Default Organization'),
        'code' => env('RBAC_ORGANIZATION_CODE', 'default'),
    ],
];

<?php

return [
    'authorization' => [
        'default_organization_unit_id' => env(
            'IDENTITY_DEFAULT_ORGANIZATION_UNIT_ID',
            env('APP_ENV') === 'production' ? null : '018f6f7d-0c00-7000-8000-000000000011',
        ),
    ],

    'session' => [
        'cookie' => env('IDENTITY_SESSION_COOKIE', 'cluster_identity_session'),
        'ttl_minutes' => 480,
        'idle_minutes' => 30,
        'same_site' => 'lax',
        'secure' => true,
        'http_only' => true,
        'path' => '/',
        'max_concurrent' => 3,
    ],

    'csrf' => [
        'header' => 'X-CSRF-Token',
    ],

    'activation' => [
        'ttl_minutes' => 60,
    ],

    'password' => [
        'min_length' => 14,
        'max_length' => 128,
        'history_size' => 5,
        'policy_version' => 'identity-password-v1',
        'denylist' => [
            'path' => env('IDENTITY_PASSWORD_DENYLIST_PATH', storage_path('identity/password-denylist.txt')),
        ],
        'common' => [
            'password',
            'password123',
            'qwertyuiop',
            'letmein123456',
            'welcome123456',
        ],
    ],

    'pre_auth_throttle' => [
        'source_username_max_attempts' => 4,
        'account_max_attempts' => 20,
        'window_seconds' => 60,
        'lock_durations_minutes' => [15, 30, 60, 120],
        'account_lock_threshold' => 5,
        'account_lock_durations_minutes' => [15, 30, 60, 120],
    ],

    'totp' => [
        'issuer' => 'Third Health Cluster',
        'period_seconds' => 30,
        'digits' => 6,
        'window' => 1,
    ],
];

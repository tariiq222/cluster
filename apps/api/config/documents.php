<?php

$documentsTesting = ($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? getenv('APP_ENV')) === 'testing'
    || in_array('test', $_SERVER['argv'] ?? [], true)
    || in_array('config:clear', $_SERVER['argv'] ?? [], true)
    || str_contains(implode(' ', $_SERVER['argv'] ?? []), 'phpstan');
$documentUploadEndpointAllowlist = $documentsTesting
    ? ['storage.invalid']
    : array_values(array_filter(array_map(
        static fn (string $host): string => strtolower(trim($host)),
        explode(',', (string) env('DOCUMENTS_UPLOAD_ENDPOINT_ALLOWLIST', '')),
    )));
if (! $documentsTesting && $documentUploadEndpointAllowlist === []) {
    throw new RuntimeException('Documents upload endpoint allowlist is required outside testing.');
}

return [
    'worker' => [
        'token' => env('DOCUMENTS_WORKER_TOKEN', $documentsTesting ? str_repeat('t', 32) : ''),
        'user_id' => env('DOCUMENTS_WORKER_USER_ID', '018f6f7d-0c00-7000-8000-000000000021'),
        'organization_unit_id' => env('DOCUMENTS_WORKER_ORGANIZATION_UNIT_ID', '018f6f7d-0c00-7000-8000-000000000011'),
    ],

    'storage' => [
        'quarantine_disk' => 'documents-quarantine',
        'available_disk' => 'documents-available',
        'upload_intent_ttl_seconds' => 300,
        'upload_endpoint_allowlist' => $documentUploadEndpointAllowlist,
    ],

    'uploads' => [
        'max_size_bytes' => 200 * 1024 * 1024,
        'allowed_mime_types' => [
            'pdf' => ['application/pdf'],
            'doc' => ['application/msword'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'xls' => ['application/vnd.ms-excel'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            'ppt' => ['application/vnd.ms-powerpoint'],
            'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation'],
            'png' => ['image/png'],
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'tif' => ['image/tiff'],
            'tiff' => ['image/tiff'],
            'txt' => ['text/plain'],
            'csv' => ['text/csv', 'text/plain'],
            'md' => ['text/markdown'],
            'zip' => ['application/zip'],
            '7z' => ['application/x-7z-compressed'],
            'rar' => ['application/x-rar-compressed'],
            'tar' => ['application/x-tar'],
            'gz' => ['application/gzip'],
        ],
    ],

    'retention' => [
        'classification_policies' => [
            'public' => 'administrative_7_years',
            'internal' => 'administrative_7_years',
            'confidential' => 'confidential_10_years',
            'top_secret' => 'top_secret_15_years',
        ],
        'policies' => [
            'administrative_7_years' => [
                'retention_days' => 2557,
                'legal_hold' => false,
            ],
            'confidential_10_years' => [
                'retention_days' => 3653,
                'legal_hold' => false,
            ],
            'top_secret_15_years' => [
                'retention_days' => 5479,
                'legal_hold' => true,
                'legal_hold_reason' => 'classification_policy',
            ],
        ],
    ],
];

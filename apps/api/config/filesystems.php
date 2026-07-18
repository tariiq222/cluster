<?php

$documentsQuarantine = [
    'key' => env('DOCUMENTS_QUARANTINE_AWS_ACCESS_KEY_ID'),
    'secret' => env('DOCUMENTS_QUARANTINE_AWS_SECRET_ACCESS_KEY'),
    'region' => env('DOCUMENTS_QUARANTINE_AWS_DEFAULT_REGION'),
    'bucket' => env('DOCUMENTS_QUARANTINE_AWS_BUCKET'),
    'kms_key_id' => env('DOCUMENTS_QUARANTINE_KMS_KEY_ID'),
];
$documentsAvailable = [
    'key' => env('DOCUMENTS_AVAILABLE_AWS_ACCESS_KEY_ID'),
    'secret' => env('DOCUMENTS_AVAILABLE_AWS_SECRET_ACCESS_KEY'),
    'region' => env('DOCUMENTS_AVAILABLE_AWS_DEFAULT_REGION'),
    'bucket' => env('DOCUMENTS_AVAILABLE_AWS_BUCKET'),
    'kms_key_id' => env('DOCUMENTS_AVAILABLE_KMS_KEY_ID'),
];

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        'documents-quarantine' => [
            'driver' => 's3',
            'key' => $documentsQuarantine['key'],
            'secret' => $documentsQuarantine['secret'],
            'region' => $documentsQuarantine['region'],
            'bucket' => $documentsQuarantine['bucket'],
            'endpoint' => env('DOCUMENTS_QUARANTINE_AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('DOCUMENTS_QUARANTINE_AWS_USE_PATH_STYLE_ENDPOINT', false),
            'root' => 'documents/quarantine',
            'visibility' => 'private',
            'throw' => true,
            'report' => true,
            'options' => [
                'ServerSideEncryption' => 'aws:kms',
                'SSEKMSKeyId' => $documentsQuarantine['kms_key_id'],
            ],
        ],

        'documents-available' => [
            'driver' => 's3',
            'key' => $documentsAvailable['key'],
            'secret' => $documentsAvailable['secret'],
            'region' => $documentsAvailable['region'],
            'bucket' => $documentsAvailable['bucket'],
            'endpoint' => env('DOCUMENTS_AVAILABLE_AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('DOCUMENTS_AVAILABLE_AWS_USE_PATH_STYLE_ENDPOINT', false),
            'root' => 'documents/available',
            'visibility' => 'private',
            'throw' => true,
            'report' => true,
            'options' => [
                'ServerSideEncryption' => 'aws:kms',
                'SSEKMSKeyId' => $documentsAvailable['kms_key_id'],
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];

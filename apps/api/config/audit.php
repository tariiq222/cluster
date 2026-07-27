<?php

declare(strict_types=1);


$rawIntegrityKeys = trim((string) env('AUDIT_INTEGRITY_KEYS', ''));
$integrityKeys = [];
if ($rawIntegrityKeys !== '') {
    foreach (explode(',', $rawIntegrityKeys) as $entry) {
        $entry = trim($entry);
        if ($entry === '' || substr_count($entry, ':') < 1) {
            throw new InvalidArgumentException('audit_integrity_keys_invalid');
        }

        [$version, $key] = explode(':', $entry, 2);
        $version = trim($version);
        $key = trim($key);
        if (preg_match('/\A[a-z][a-z0-9_.-]{0,31}\z/', $version) !== 1
            || strlen($key) < 32
            || array_key_exists($version, $integrityKeys)) {
            throw new InvalidArgumentException('audit_integrity_keys_invalid');
        }
        $integrityKeys[$version] = $key;
    }
}

$activeIntegrityKeyVersion = trim((string) env('AUDIT_INTEGRITY_KEY_VERSION', ''));
$appEnvironment = (string) env('APP_ENV', 'production');
if ($appEnvironment === 'production'
    && ($integrityKeys === []
        || $activeIntegrityKeyVersion === ''
        || ! array_key_exists($activeIntegrityKeyVersion, $integrityKeys))) {
    throw new UnexpectedValueException('audit_integrity_runtime_unavailable');
}

$retentionFloorDays = (int) env('AUDIT_RETENTION_DAYS', 2555);
if ($retentionFloorDays < 2555) {
    throw new InvalidArgumentException('audit_retention_floor_too_low');
}

$integrityBatchSize = (int) env('AUDIT_INTEGRITY_BATCH_SIZE', 500);
if ($integrityBatchSize < 1) {
    throw new InvalidArgumentException('audit_integrity_batch_size_invalid');
}

return [
    'streams' => [
        'audit_events_recorded' => 'audit.events.recorded',
        'audit_exports_completed' => 'audit.exports.completed',
        'audit_integrity_violations' => 'audit.integrity.violations',
    ],

    'integrity' => [
        'keys' => $integrityKeys,
        'active_key_version' => $activeIntegrityKeyVersion,
        'batch_size' => $integrityBatchSize,
    ],

    'retention' => [
        'floor_days' => $retentionFloorDays,
        'classes' => [
            'standard' => 2555,
            'security' => 3650,
            'regulated' => 3650,
        ],
    ],

    'export' => [
        'max_window_days' => (int) env('AUDIT_EXPORT_MAX_WINDOW_DAYS', 90),
        'expires_after_days' => (int) env('AUDIT_EXPORT_EXPIRES_DAYS', 7),
    ],
];

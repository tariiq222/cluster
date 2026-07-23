<?php

return [
    'runtime' => [
        'enabled' => filter_var(env('PLATFORM_OPERATIONS_RUNTIME_ENABLED', false), FILTER_VALIDATE_BOOL),
    ],
    'commands' => [
        // Each environment value is one executable path, never a shell command or argv string.
        'backup' => array_values(array_filter([env('PLATFORM_BACKUP_COMMAND')], 'is_string')),
        'restore_validation' => array_values(array_filter([env('PLATFORM_RESTORE_VALIDATION_COMMAND')], 'is_string')),
    ],
    'command_timeout_seconds' => 30,
    // Must be longer than command_timeout_seconds so a healthy worker keeps its claim.
    'dispatch_claim_timeout_seconds' => 120,
    'health' => [
        // pcntl_alarm is second-granularity; keep this aligned to a real hard deadline.
        'timeout_ms' => 1000,
    ],
    'logs' => [
        'archive_disk' => env('TECHNICAL_LOG_ARCHIVE_DISK', 'technical-log-archive'),
    ],
    'restore_operator_runbook_reference' => env('PLATFORM_RESTORE_RUNBOOK_REFERENCE', 'docs/operations/ha-dr-backup.md'),
];

<?php

declare(strict_types=1);

return [
    'tasks' => (bool) env('CLUSTER_TASKS_ENABLED', true),
    'destructive_migrations' => [
        'backup_id' => env('DESTRUCTIVE_MIGRATION_BACKUP_ID'),
        'restore_validation_id' => env('DESTRUCTIVE_MIGRATION_RESTORE_VALIDATION_ID'),
    ],
];

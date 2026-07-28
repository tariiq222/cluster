<?php

declare(strict_types=1);

return [
    'work_management' => (bool) env('CLUSTER_WORK_MANAGEMENT_ENABLED', false),
    'tasks' => (bool) env('CLUSTER_TASKS_ENABLED', true),
];

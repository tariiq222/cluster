<?php

namespace Modules\Tasks\Domain;

use InvalidArgumentException;

final readonly class Task
{
    public function __construct(
        public string $id,
        public string $title,
        public string $assigneeUserId,
        public string $status = 'open',
    ) {
        if ($title === '' || $assigneeUserId === '') {
            throw new InvalidArgumentException('A task title and assignee are required.');
        }
    }
}

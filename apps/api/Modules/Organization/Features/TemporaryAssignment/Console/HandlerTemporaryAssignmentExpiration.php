<?php

namespace Modules\Organization\Features\TemporaryAssignment\Console;

use Modules\Organization\Features\TemporaryAssignment\Handler\ExpireTemporaryAssignmentsHandler;

final class HandlerTemporaryAssignmentExpiration implements RunTemporaryAssignmentExpiration
{
    public function __construct(private readonly ExpireTemporaryAssignmentsHandler $handler) {}

    public function run(int $limit, string $subjectId, string $correlationId): array
    {
        return $this->handler->handle($limit, $subjectId, $correlationId);
    }
}

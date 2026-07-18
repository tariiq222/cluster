<?php

namespace Modules\Organization\Features\TemporaryAssignment\Console;

interface RunTemporaryAssignmentExpiration
{
    /** @return array{expired_count: int, expired_ids: list<string>, has_more: bool} */
    public function run(int $limit, string $subjectId, string $correlationId): array;
}

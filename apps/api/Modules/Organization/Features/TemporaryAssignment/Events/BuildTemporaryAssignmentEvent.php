<?php

namespace Modules\Organization\Features\TemporaryAssignment\Events;

interface BuildTemporaryAssignmentEvent
{
    /**
     * Implementations must include the governed access_context and must not
     * copy reasons, actor profile data, credentials, or secrets into data.
     *
     * @param  array<string, mixed>  $temporaryAssignment
     * @return array<string, mixed>
     */
    public function make(
        string $type,
        array $temporaryAssignment,
        string $subjectId,
        string $tenantId,
        string $correlationId,
    ): array;
}

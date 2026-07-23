<?php

namespace Modules\Workflow\Contracts;

use Modules\Authorization\Contracts\RecordFacts;

/** Implemented by the application integration owned by each source module. */
interface ResolveWorkflowSourceAuthorizationFacts
{
    public function resolve(WorkflowSourceReference $reference): ?RecordFacts;

    /**
     * @param  list<WorkflowSourceReference>  $references
     * @return array<string, RecordFacts> keyed by WorkflowSourceReference::key()
     */
    public function resolveMany(array $references): array;
}

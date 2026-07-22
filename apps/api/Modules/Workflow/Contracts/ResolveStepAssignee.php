<?php

namespace Modules\Workflow\Contracts;

interface ResolveStepAssignee
{
    public function resolve(RuleContext $ctx, RuleSpec $spec): ?string;
}

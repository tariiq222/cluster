<?php

namespace Modules\Organization\Features\TemporaryAssignment\Contracts;

interface ValidateTemporaryAssignmentCapabilities
{
    /**
     * Returns true only when every code exists in the published governed
     * capability catalogue and is currently active. Unknown and inactive
     * codes must return false; catalogue failures may throw and callers must
     * deny rather than infer validity.
     *
     * @param  list<string>  $capabilityCodes
     */
    public function allAreActive(array $capabilityCodes): bool;
}

<?php

namespace Modules\Organization\Infrastructure\Authorization;

use Modules\Organization\Features\TemporaryAssignment\Contracts\ValidateTemporaryAssignmentCapabilities;
use RuntimeException;

final class ConfiguredTemporaryAssignmentCapabilityValidator implements ValidateTemporaryAssignmentCapabilities
{
    public function allAreActive(array $capabilityCodes): bool
    {
        $published = config('authorization.temporary_assignment_capabilities');
        if (! is_array($published) || $published === []) {
            throw new RuntimeException('temporary_assignment_capability_catalogue_unavailable');
        }

        return array_diff($capabilityCodes, $published) === [];
    }
}

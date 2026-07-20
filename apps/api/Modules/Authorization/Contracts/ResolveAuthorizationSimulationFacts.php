<?php

namespace Modules\Authorization\Contracts;

interface ResolveAuthorizationSimulationFacts
{
    public function resolve(AuthorizationResourceReference $reference): ?RecordFacts;
}

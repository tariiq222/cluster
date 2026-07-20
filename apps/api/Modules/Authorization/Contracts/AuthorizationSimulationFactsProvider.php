<?php

namespace Modules\Authorization\Contracts;

interface AuthorizationSimulationFactsProvider
{
    public function supports(AuthorizationResourceReference $reference): bool;

    public function resolve(AuthorizationResourceReference $reference): ?RecordFacts;
}

<?php

namespace Modules\Authorization\Contracts;

interface DecideAccess
{
    /**
     * @param  array{facility_id?: string}  $actor
     */
    public function decide(array $actor, string $capability, ?RecordFacts $facts): AccessDecision;
}

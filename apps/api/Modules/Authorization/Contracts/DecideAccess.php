<?php

namespace Modules\Authorization\Contracts;

interface DecideAccess
{
    /**
     * @param  array{user_id?: string, facility_id?: string}  $actor
     */
    public function decide(array $actor, string $capability, ?RecordFacts $facts): AccessDecision;
}

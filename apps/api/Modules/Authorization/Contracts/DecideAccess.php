<?php

namespace Modules\Authorization\Contracts;

interface DecideAccess
{
    /**
     * @param  array{user_id?: string, facility_id?: string, organization_unit_ids?: list<string>, correlation_id?: string, original_user_id?: string}  $actor
     */
    public function decide(array $actor, string $capability, ?RecordFacts $facts): AccessDecision;

    /**
     * Evaluates the decision without persisting an access_decisions row or
     * emitting a sensitive_access_events row. Used by read-side projections
     * and shell-rendered capability checks where every check would otherwise
     * amplify access_decisions write traffic.
     *
     * @param  array{user_id?: string, facility_id?: string, organization_unit_ids?: list<string>, correlation_id?: string, original_user_id?: string}  $actor
     */
    public function evaluateOnly(array $actor, string $capability, ?RecordFacts $facts): AccessDecision;
}

<?php

namespace Modules\Organization\Contracts;

/**
 * Capability-decision contract used by Organization HTTP controllers. The
 * contract lives in the Organization module so the lower-ranked controllers can
 * consume it directly; the Authorization module provides an implementation
 * that adapts between this Organization-owned surface and the Authorization
 * engine.
 */
interface DecideAccess
{
    /**
     * @param  array{user_id?: string, facility_id?: string, organization_unit_ids?: list<string>, correlation_id?: string, original_user_id?: string}  $actor
     */
    public function decide(array $actor, string $capability, ?RecordFacts $facts): AccessDecision;
}
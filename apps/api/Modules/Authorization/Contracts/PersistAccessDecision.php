<?php

namespace Modules\Authorization\Contracts;

interface PersistAccessDecision
{
    /** @param array{user_id?: string, facility_id?: string, organization_unit_ids?: list<string>, correlation_id?: string, original_user_id?: string} $actor */
    public function persist(AccessDecision $decision, ?RecordFacts $facts, array $actor): bool;
}

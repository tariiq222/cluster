<?php

namespace Modules\Authorization\Contracts;

/**
 * Read-side query that returns the current size of the active operations office
 * membership, regardless of cluster.
 *
 * The office is a single platform-level body whose cluster-scoped role rows are
 * unified for the bootstrap-time self-approval decision: with two or more
 * active members the author cannot approve their own workflow version even if
 * they hold `workflow.approve`; with one member the bootstrap exception fires;
 * with zero members the office is empty and no approval can land.
 *
 * No privilege check belongs here. The caller is the office itself.
 */
interface CountOperationsOfficeMembers
{
    public function activeMembers(): int;
}

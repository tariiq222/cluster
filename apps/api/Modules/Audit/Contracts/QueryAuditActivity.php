<?php

declare(strict_types=1);

namespace Modules\Audit\Contracts;

/**
 * Frozen M00 query contract. Returns a bounded page of audit items ordered
 * by descending occurredAt then descending recordedAt. The implementation
 * must apply capability-based authorization and exclude events whose
 * classification exceeds the caller's classification.
 */
interface QueryAuditActivity
{
    public function query(AuditActivityQuery $query): AuditActivityPage;
}

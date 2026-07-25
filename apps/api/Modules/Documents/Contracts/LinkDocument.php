<?php

namespace Modules\Documents\Contracts;

/**
 * Owner of a source record (for example a work record) calls this
 * contract to attach a document to itself. The Documents module is the
 * authoritative side of the link — it owns storage, classification and
 * quarantine — so the contract lives in Documents and any consumer
 * module only depends on it.
 */
interface LinkDocument
{
    /**
     * @param  string  $relationType  Must be one of the supported relation types
     *                                (e.g. "attachment", "evidence").
     * @param  string  $facilityId  The principal's facility id; used for
     *                              authorization facts only.
     * @return string The link id (UUIDv7).
     *
     * @throws \DomainException When the document is not available for
     *                          linking, or the source reference cannot
     *                          be resolved.
     */
    public function link(
        string $documentId,
        DocumentSourceReference $reference,
        string $relationType,
        string $principalId,
        string $facilityId,
        ?string $constraintPolicyKey = null,
    ): string;
}

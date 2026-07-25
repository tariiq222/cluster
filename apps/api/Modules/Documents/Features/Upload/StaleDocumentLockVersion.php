<?php

namespace Modules\Documents\Features\Upload;

use DomainException;

/**
 * Surfaced when DocumentUploadHandler::complete is asked to finalize an
 * upload against a document whose lock_version no longer matches the version
 * the caller observed. The handler raises this from its SQL CAS predicate
 * so callers can distinguish a stale submit from a legitimate idempotent
 * outcome.
 */
final class StaleDocumentLockVersion extends DomainException
{
    public const CODE = 'stale_document_lock_version';

    public function __construct(
        public readonly string $documentId,
        public readonly int $expectedVersion,
    ) {
        parent::__construct(self::CODE);
    }

    public function detail(): string
    {
        return sprintf(
            'Document %s no longer has the expected lock version %d.',
            $this->documentId,
            $this->expectedVersion,
        );
    }
}

<?php

namespace Modules\Documents\Contracts;

/**
 * Lightweight value object that identifies the producer-side source a
 * document is being linked to (for example a work record id). Lives in
 * Contracts so producer modules can construct references without
 * reaching into Documents' Application namespace.
 */
readonly class DocumentSourceReference
{
    public function __construct(
        public string $sourceModule,
        public string $sourceType,
        public string $sourceId,
    ) {
    }
}

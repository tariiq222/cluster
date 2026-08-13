<?php

namespace Modules\Documents\Contracts;

use InvalidArgumentException;

/**
 * Lightweight value object that identifies the producer-side source a
 * document is being linked to (for example a task id). Lives in
 * Contracts so producer modules can construct references without
 * reaching into Documents' Application namespace.
 */
final readonly class DocumentSourceReference
{
    public function __construct(
        public string $sourceModule,
        public string $sourceType,
        public string $sourceId,
    ) {
        foreach ([
            'source module' => $sourceModule,
            'source type' => $sourceType,
            'source id' => $sourceId,
        ] as $label => $value) {
            if (trim($value) === '' || strlen($value) > 128) {
                throw new InvalidArgumentException("Document {$label} is invalid.");
            }
        }
        if (preg_match('/\A[A-Za-z0-9._-]+\z/', $sourceModule) !== 1
            || preg_match('/\A[A-Za-z0-9._-]+\z/', $sourceType) !== 1) {
            throw new InvalidArgumentException('Document source identifiers are invalid.');
        }
    }

    /** @return array{source_module:string, source_type:string, source_id:string} */
    public function toArray(): array
    {
        return [
            'source_module' => $this->sourceModule,
            'source_type' => $this->sourceType,
            'source_id' => $this->sourceId,
        ];
    }
}

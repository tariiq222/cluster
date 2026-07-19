<?php

namespace Modules\Documents\Application;

use InvalidArgumentException;

/**
 * A source reference is deliberately an ID-only boundary.  Documents never
 * stores an Eloquent relation or a foreign key into a business module.
 */
final readonly class DocumentSourceReference
{
    public function __construct(
        public string $sourceModule,
        public string $sourceType,
        public string $sourceId,
    ) {
        foreach ([
            'source module' => $this->sourceModule,
            'source type' => $this->sourceType,
            'source id' => $this->sourceId,
        ] as $label => $value) {
            if (trim($value) === '' || strlen($value) > 128) {
                throw new InvalidArgumentException("Document {$label} is invalid.");
            }
        }
        if (preg_match('/\A[A-Za-z0-9._-]+\z/', $this->sourceModule) !== 1
            || preg_match('/\A[A-Za-z0-9._-]+\z/', $this->sourceType) !== 1) {
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

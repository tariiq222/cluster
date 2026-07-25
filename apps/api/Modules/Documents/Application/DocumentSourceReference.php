<?php

namespace Modules\Documents\Application;

use InvalidArgumentException;
use Modules\Documents\Contracts\DocumentSourceReference as ContractDocumentSourceReference;

/**
 * A source reference is deliberately an ID-only boundary.  Documents never
 * stores an Eloquent relation or a foreign key into a business module.
 *
 * The class is a typed alias of the Contracts variant so producer modules
 * can construct references without reaching into the Application
 * namespace, while internal callers (e.g. tests) keep their existing
 * validation semantics.
 */
final readonly class DocumentSourceReference extends ContractDocumentSourceReference
{
    public function __construct(
        string $sourceModule,
        string $sourceType,
        string $sourceId,
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
        parent::__construct($sourceModule, $sourceType, $sourceId);
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

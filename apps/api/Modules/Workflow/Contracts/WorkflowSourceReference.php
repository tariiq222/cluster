<?php

namespace Modules\Workflow\Contracts;

use InvalidArgumentException;

/**
 * ID-only source boundary for Workflow authorization. Workflow never follows
 * this reference into another business module's persistence itself.
 */
final readonly class WorkflowSourceReference
{
    public function __construct(
        public string $sourceModule,
        public string $sourceType,
        public string $sourceId,
    ) {
        foreach ([$this->sourceModule, $this->sourceType, $this->sourceId] as $value) {
            if (trim($value) === '' || strlen($value) > 128) {
                throw new InvalidArgumentException('Workflow source reference is invalid.');
            }
        }
    }

    /** Stable map key for batch resolvers; values may contain delimiters. */
    public function key(): string
    {
        return json_encode([$this->sourceModule, $this->sourceType, $this->sourceId], JSON_THROW_ON_ERROR);
    }
}

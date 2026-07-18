<?php

namespace Modules\Documents\Application;

use InvalidArgumentException;

final readonly class DocumentMetadata
{
    /** @var list<string> */
    private const CLASSIFICATIONS = ['public', 'internal', 'confidential', 'top_secret'];

    public function __construct(
        public string $name,
        public ?string $description,
        public string $classification,
    ) {
        if (trim($this->name) === '' || mb_strlen($this->name) > 255) {
            throw new InvalidArgumentException('Document name must contain between 1 and 255 characters.');
        }
        if ($this->description !== null && mb_strlen($this->description) > 10000) {
            throw new InvalidArgumentException('Document description is too long.');
        }
        if (! in_array($this->classification, self::CLASSIFICATIONS, true)) {
            throw new InvalidArgumentException('Document classification is unsupported.');
        }
    }
}

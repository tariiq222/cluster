<?php

namespace Modules\Documents\Application;

use Modules\Documents\Domain\UuidV7;

final readonly class QuarantineObjectReference
{
    public function __construct(public string $storageObjectId)
    {
        UuidV7::assert($this->storageObjectId, 'Storage object id');
    }
}

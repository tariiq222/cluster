<?php

namespace Modules\Documents\Tests\Infrastructure\Security;

use Modules\Documents\Infrastructure\Storage\S3\QuarantineObjectByteSource;

final class InMemoryByteSource implements QuarantineObjectByteSource
{
    public function __construct(private readonly string $payload) {}

    public function fetchBytes(string $storageObjectId): string
    {
        return $this->payload;
    }
}

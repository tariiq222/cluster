<?php

namespace Modules\Documents\Tests\Infrastructure\Security;

use Modules\Documents\Infrastructure\Security\ClamAvSocketTransport;

final class FakeClamAvTransport implements ClamAvSocketTransport
{
    public function __construct(private readonly string $response) {}

    public function instream(string $payload, int $chunkBytes): string
    {
        return $this->response;
    }
}

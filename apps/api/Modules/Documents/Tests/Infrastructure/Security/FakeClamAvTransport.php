<?php

namespace Modules\Documents\Tests\Infrastructure\Security;

use Modules\Documents\Infrastructure\Security\ClamAvSocketTransport;
use Modules\Documents\Infrastructure\Storage\QuarantineByteChunkReader;

final class FakeClamAvTransport implements ClamAvSocketTransport
{
    public function __construct(private readonly string $response) {}

    public function instream(QuarantineByteChunkReader $reader): string
    {
        while ($reader->readChunk() !== null) {
            // discard — this fake only cares about the response mapping
        }
        $reader->close();

        return $this->response;
    }
}

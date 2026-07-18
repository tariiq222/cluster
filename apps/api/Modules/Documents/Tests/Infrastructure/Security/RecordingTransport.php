<?php

namespace Modules\Documents\Tests\Infrastructure\Security;

use Modules\Documents\Infrastructure\Security\ClamAvSocketTransport;

final class RecordingTransport implements ClamAvSocketTransport
{
    public string $lastPayload = '';

    public int $lastChunkBytes = 0;

    public function instream(string $payload, int $chunkBytes): string
    {
        $this->lastPayload = $payload;
        $this->lastChunkBytes = $chunkBytes;

        return 'stream: OK';
    }
}

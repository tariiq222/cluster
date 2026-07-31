<?php

namespace Modules\Documents\Tests\Infrastructure\Security;

use Modules\Documents\Infrastructure\Security\ClamAvSocketTransport;
use Modules\Documents\Infrastructure\Storage\QuarantineByteChunkReader;

/**
 * Records the chunks the transport observed and the total bytes consumed.
 * Used by tests that need to assert that the scanner handed the transport
 * the right byte sequence without materialising it.
 */
final class RecordingTransport implements ClamAvSocketTransport
{
    /** @var list<string> */
    public array $observedChunks = [];

    public int $totalBytes = 0;

    public function instream(QuarantineByteChunkReader $reader): string
    {
        while (($chunk = $reader->readChunk()) !== null) {
            $this->observedChunks[] = $chunk;
            $this->totalBytes += strlen($chunk);
        }
        $reader->close();

        return 'stream: OK';
    }
}

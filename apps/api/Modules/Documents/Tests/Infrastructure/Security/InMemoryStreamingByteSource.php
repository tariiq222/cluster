<?php

namespace Modules\Documents\Tests\Infrastructure\Security;

use Modules\Documents\Infrastructure\Storage\QuarantineByteChunkReader;

/**
 * Test double that hands out a {@see QuarantineByteChunkReader} backed by a
 * fixed chunk sequence. The reader is single-pass, returns the supplied chunks
 * in order, then {@code null} on the next call to mark end of stream, and
 * records the configured chunk size for assertion.
 */
final class InMemoryStreamingByteSource implements \Modules\Documents\Infrastructure\Storage\StreamingQuarantineObjectByteSource
{
    /** @var list<string> */
    private array $chunks;

    public int $requestedChunkBytes = 0;

    public ?string $lastStorageObjectId = null;

    public int $openCount = 0;

    public int $closeCount = 0;

    /** @param list<string> $chunks */
    public function __construct(array $chunks)
    {
        $this->chunks = $chunks;
    }

    /** @return list<string> */
    public function chunks(): array
    {
        return $this->chunks;
    }

    public function openChunkReader(string $storageObjectId, int $chunkBytes): QuarantineByteChunkReader
    {
        $this->requestedChunkBytes = $chunkBytes;
        $this->lastStorageObjectId = $storageObjectId;
        $this->openCount++;
        $reader = new InMemoryChunkReader($this->chunks);
        $reader->onClose = function (): void {
            $this->closeCount++;
        };

        return $reader;
    }
}

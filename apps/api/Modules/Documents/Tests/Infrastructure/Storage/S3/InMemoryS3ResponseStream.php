<?php

namespace Modules\Documents\Tests\Infrastructure\Storage\S3;

use Modules\Documents\Infrastructure\Storage\S3\S3ResponseStream;

/**
 * In-memory {@see S3ResponseStream} backed by a fixed chunk list. Used by
 * tests so the streaming byte source can be exercised without binding to
 * Guzzle's PSR-7 implementation or a real network round-trip.
 */
final class InMemoryS3ResponseStream implements S3ResponseStream
{
    /** @var list<string> */
    private array $chunks;

    private int $index = 0;

    public bool $closed = false;

    public int $closeCount = 0;

    public int $peakChunkBytes = 0;

    /** @param list<string> $chunks */
    public function __construct(private readonly int $statusCode, private readonly array $headers, array $chunks)
    {
        $this->chunks = $chunks;
    }

    public function status(): int
    {
        return $this->statusCode;
    }

    public function headers(): array
    {
        return $this->headers;
    }

    public function readChunk(int $chunkBytes): ?string
    {
        if ($this->index >= count($this->chunks)) {
            return null;
        }
        $chunk = $this->chunks[$this->index];
        $this->index++;
        if (strlen($chunk) > $chunkBytes) {
            $sliced = substr($chunk, 0, $chunkBytes);
            $this->peakChunkBytes = max($this->peakChunkBytes, strlen($sliced));

            return $sliced;
        }
        $this->peakChunkBytes = max($this->peakChunkBytes, strlen($chunk));

        return $chunk;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->closeCount++;
    }
}

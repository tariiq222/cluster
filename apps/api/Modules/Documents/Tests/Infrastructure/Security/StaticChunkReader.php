<?php

namespace Modules\Documents\Tests\Infrastructure\Security;

use Modules\Documents\Infrastructure\Storage\QuarantineByteChunkReader;

/**
 * Static chunk reader used by {@see StreamSocketClamAvTransportTest}. It
 * returns each chunk in {@see $chunks} once, then {@code null}. It can also
 * be configured to throw on the first read so the test exercises the failure
 * path without relying on real network errors.
 */
final class StaticChunkReader implements QuarantineByteChunkReader
{
    /** @var list<string> */
    private array $chunks;

    private int $index = 0;

    public bool $closed = false;

    public ?\Throwable $throwOnRead = null;

    /** @param list<string> $chunks */
    public function __construct(array $chunks)
    {
        $this->chunks = $chunks;
    }

    public function readChunk(): ?string
    {
        if ($this->throwOnRead !== null) {
            throw $this->throwOnRead;
        }
        if ($this->index >= count($this->chunks)) {
            return null;
        }
        $chunk = $this->chunks[$this->index];
        $this->index++;

        return $chunk;
    }

    public function close(): void
    {
        $this->closed = true;
    }
}

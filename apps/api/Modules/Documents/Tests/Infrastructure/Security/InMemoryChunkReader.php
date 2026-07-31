<?php

namespace Modules\Documents\Tests\Infrastructure\Security;

use Modules\Documents\Infrastructure\Storage\QuarantineByteChunkReader;

/**
 * Test double that backs a {@see QuarantineByteChunkReader} on an in-memory
 * chunk list. The reader records peak concurrent buffer size so a test can
 * prove that the scanner never asks for the full payload at once. It also
 * counts {@code close()} invocations so callers can assert resource cleanup.
 */
final class InMemoryChunkReader implements QuarantineByteChunkReader
{
    /** @var list<string> */
    private array $chunks;

    private int $index = 0;

    public int $closeCount = 0;

    public bool $closed = false;

    /** @var \Closure|null */
    public $onClose = null;

    public int $peakBufferBytes = 0;

    /** @param list<string> $chunks */
    public function __construct(array $chunks)
    {
        $this->chunks = $chunks;
    }

    public function readChunk(): ?string
    {
        if ($this->closed || $this->index >= count($this->chunks)) {
            return null;
        }
        $chunk = $this->chunks[$this->index];
        $this->index++;
        $this->peakBufferBytes = max($this->peakBufferBytes, strlen($chunk));

        return $chunk;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->closeCount++;
        if ($this->onClose !== null) {
            ($this->onClose)();
        }
    }

    public function totalBytes(): int
    {
        $total = 0;
        foreach ($this->chunks as $chunk) {
            $total += strlen($chunk);
        }

        return $total;
    }
}

<?php

namespace Modules\Documents\Infrastructure\Storage\S3;

use Modules\Documents\Infrastructure\Storage\QuarantineByteChunkReader;
use Throwable;

/**
 * Pull-based {@see QuarantineByteChunkReader} backed by an S3 streaming
 * response. The reader performs the status check on the first read, then
 * delegates subsequent reads to the underlying {@see S3ResponseStream}. The
 * stream is released exactly once on {@see close()}; double-close is a no-op
 * so callers can safely invoke it on the failure path.
 */
final class S3QuarantineByteChunkReader implements QuarantineByteChunkReader
{
    private bool $closed = false;

    private bool $validated = false;

    public function __construct(
        private readonly S3ResponseStream $stream,
        private readonly int $chunkBytes,
    ) {}

    public function readChunk(): ?string
    {
        if ($this->closed) {
            return null;
        }
        if (! $this->validated) {
            $status = $this->stream->status();
            if ($status !== 200) {
                $this->close();
                throw new \Modules\Documents\Application\RetryableStorageException('documents_s3_fetch_failed');
            }
            $this->validated = true;
        }
        try {
            $chunk = $this->stream->readChunk($this->chunkBytes);
        } catch (Throwable $exception) {
            $this->close();
            throw $exception;
        }
        if ($chunk === null) {
            $this->close();

            return null;
        }

        return $chunk;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->stream->close();
    }
}

<?php

namespace Modules\Documents\Infrastructure\Storage\S3;

/**
 * Pull-based response stream returned by {@see StreamingS3RequestExecutor}.
 * The contract mirrors the small subset of PSR-7 {@code StreamInterface} the
 * scanner actually uses, so production code does not need to depend on
 * psr/http-message while tests can substitute an in-memory implementation.
 */
interface S3ResponseStream
{
    public function status(): int;

    /** @return array<string, string> lower-cased response headers. */
    public function headers(): array;

    /**
     * @return string|null a chunk of at most {@code $chunkBytes}, or
     *                     {@code null} when the stream is exhausted.
     */
    public function readChunk(int $chunkBytes): ?string;

    /** Release the underlying socket/buffer. Idempotent. */
    public function close(): void;
}

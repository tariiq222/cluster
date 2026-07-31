<?php

namespace Modules\Documents\Infrastructure\Storage;

/**
 * Single-pass reader over the verified quarantine object. The interface is
 * deliberately pull-based so the scanner never has to materialise the entire
 * object before the ClamAV transport starts consuming bytes.
 *
 * <p>Implementations must return {@code null} from {@see readChunk()} when
 * the stream is exhausted, never throw, and must guarantee that {@see close()}
 * is idempotent so callers can safely invoke it on the failure path without
 * worrying about double-release.
 */
interface QuarantineByteChunkReader
{
    /**
     * @return string|null non-empty bounded chunk, or {@code null} at EOF.
     */
    public function readChunk(): ?string;

    /** Release any underlying resources. Idempotent. */
    public function close(): void;
}

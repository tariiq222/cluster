<?php

namespace Modules\Documents\Infrastructure\Storage;

/**
 * Streaming boundary for the verified quarantine object. The scanner opens a
 * chunk reader, hands it to the ClamAV transport, and lets the transport pull
 * chunks lazily. This contract replaces the byte-materialising fetch used by
 * the legacy {@code QuarantineObjectByteSource::fetchBytes} path for INSTREAM
 * scans so a 200 MiB upload no longer allocates the entire object in PHP
 * memory before the first byte reaches clamd.
 */
interface StreamingQuarantineObjectByteSource
{
    /**
     * @param  int  $chunkBytes  upper bound on the size of each returned chunk.
     *                           Implementations may return shorter chunks near
     *                           the tail of the object.
     *
     * @throws \RuntimeException when the object cannot be opened. The scanner
     *                           maps any such failure to {@code unavailable}.
     */
    public function openChunkReader(string $storageObjectId, int $chunkBytes): QuarantineByteChunkReader;
}

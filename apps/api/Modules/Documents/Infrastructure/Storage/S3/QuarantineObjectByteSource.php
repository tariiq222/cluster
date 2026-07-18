<?php

namespace Modules\Documents\Infrastructure\Storage\S3;

/**
 * Pulls the verified quarantine bytes from the configured object store so the
 * ClamAV scanner can stream them. The contract stays narrow: callers do not
 * see any object keys, ETag, or generation.
 */
interface QuarantineObjectByteSource
{
    /** @return string raw quarantine bytes for the verified object. */
    public function fetchBytes(string $storageObjectId): string;
}

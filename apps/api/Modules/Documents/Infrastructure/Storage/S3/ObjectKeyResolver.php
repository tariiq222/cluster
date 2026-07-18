<?php

namespace Modules\Documents\Infrastructure\Storage\S3;

/**
 * Computes the opaque, root-relative object key inside the configured bucket.
 * The boundary never reveals object keys to callers; the resolver is the only
 * place where the storage_object_id is mapped to a server-side key.
 */
interface ObjectKeyResolver
{
    public function quarantineKey(string $seedKey): string;

    public function quarantineKeyById(string $storageObjectId): string;

    public function availableKeyById(string $storageObjectId): string;
}

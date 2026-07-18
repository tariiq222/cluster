<?php

namespace Modules\Documents\Infrastructure\Storage\S3;

use DomainException;
use Modules\Documents\Domain\UuidV7;

/**
 * Default {@see ObjectKeyResolver}: each storage object maps to a UUIDv7-rooted
 * {@code .blob} suffix. The same id is used in quarantine and available zones
 * so the mapping is symmetric and reversible from a {@code storage_object_id}.
 */
final class DeterministicObjectKeyResolver implements ObjectKeyResolver
{
    public function quarantineKey(string $seedKey): string
    {
        if (! $this->looksLikeKey($seedKey)) {
            throw new DomainException('documents_object_key_invalid');
        }

        return $seedKey;
    }

    public function quarantineKeyById(string $storageObjectId): string
    {
        UuidV7::assert($storageObjectId, 'Storage object id');

        return $storageObjectId.'.blob';
    }

    public function availableKeyById(string $storageObjectId): string
    {
        return $this->quarantineKeyById($storageObjectId);
    }

    private function looksLikeKey(string $key): bool
    {
        return preg_match('/\A[0-9a-f-]{36}\.blob\z/', $key) === 1;
    }
}

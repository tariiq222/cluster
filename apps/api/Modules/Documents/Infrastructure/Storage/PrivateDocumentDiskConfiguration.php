<?php

namespace Modules\Documents\Infrastructure\Storage;

use RuntimeException;

final class PrivateDocumentDiskConfiguration
{
    /** @param array{key: string|null, secret: string|null, region: string|null, bucket: string|null, kms_key_id: string|null} $quarantine @param array{key: string|null, secret: string|null, region: string|null, bucket: string|null, kms_key_id: string|null} $available */
    public static function assertRuntimeSafe(bool $testing, array $quarantine, array $available): void
    {
        if ($testing) {
            return;
        }

        foreach (['quarantine' => $quarantine, 'available' => $available] as $zone => $disk) {
            foreach ($disk as $name => $value) {
                if (! is_string($value) || trim($value) === '') {
                    throw new RuntimeException("Documents {$zone} storage requires a dedicated {$name} outside testing.");
                }
            }
        }
        if (hash_equals((string) $quarantine['bucket'], (string) $available['bucket'])) {
            throw new RuntimeException('Documents quarantine and available storage require separate buckets outside testing.');
        }
        if (hash_equals((string) $quarantine['key'], (string) $available['key'])) {
            throw new RuntimeException('Documents quarantine and available storage require separate credentials outside testing.');
        }
    }
}

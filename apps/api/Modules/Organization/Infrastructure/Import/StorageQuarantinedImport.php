<?php

declare(strict_types=1);

namespace Modules\Organization\Infrastructure\Import;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Modules\Organization\Contracts\ResolveQuarantinedImport;

/**
 * Reads quarantined import payloads from the Organization quarantine disk.
 * Each submitted job references a quarantine object id; the object is a
 * JSON document `{source_filename, rows}` written by the uploader that
 * produced the quarantine entry.
 */
final class StorageQuarantinedImport implements ResolveQuarantinedImport
{
    public function __construct(private readonly ?Filesystem $disk = null) {}

    public function resolve(string $quarantineObjectId, string $templateCode, string $format): ?array
    {
        $disk = $this->disk ?? Storage::disk((string) config('organization.import.quarantine_disk', 'organization-quarantine'));
        $path = $quarantineObjectId.'.json';
        if (! $disk->exists($path)) {
            return null;
        }

        $raw = $disk->get($path);
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true, 512);
        if (! is_array($decoded)
            || ! is_string($decoded['source_filename'] ?? null)
            || ! is_array($decoded['rows'] ?? null)) {
            return null;
        }
        $rows = array_values(array_filter(
            $decoded['rows'],
            static fn (mixed $row): bool => is_array($row),
        ));

        return ['source_filename' => $decoded['source_filename'], 'rows' => $rows];
    }
}

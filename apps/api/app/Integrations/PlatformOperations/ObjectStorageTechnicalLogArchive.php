<?php

namespace App\Integrations\PlatformOperations;

use Closure;
use DateTimeImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Modules\PlatformSettings\Contracts\TechnicalLogArchive;
use Modules\PlatformSettings\Contracts\TechnicalLogArchiveStore;
use Modules\PlatformSettings\Domain\ArchiveBatch;
use Modules\PlatformSettings\Domain\ArchiveManifest;
use Modules\PlatformSettings\Domain\TechnicalLogEntry;
use RuntimeException;

final class ObjectStorageTechnicalLogArchive implements TechnicalLogArchive
{
    /**
     * @param  null|Closure(): DateTimeImmutable  $clock
     */
    public function __construct(
        private readonly Filesystem $storage,
        private readonly TechnicalLogArchiveStore $store,
        private readonly ?Closure $clock = null,
        private readonly int $restoreReadModelMinutes = 60,
    ) {
        if ($restoreReadModelMinutes < 1 || $restoreReadModelMinutes > 1440) {
            throw new RuntimeException('Technical log restore read model lifetime is invalid.');
        }
    }

    public function archive(ArchiveBatch $batch): ArchiveManifest
    {
        $existing = $this->store->manifest($batch->id);
        if ($existing !== null) {
            if ($existing->status !== 'verified') {
                throw new RuntimeException('Technical log archive manifest state is invalid.');
            }
            $this->verifyObject($existing);
            $batch->markArchived();

            return $existing;
        }
        if (! $batch->isEligibleAt($this->now())) {
            throw new RuntimeException('Technical log archive batch is not old enough.');
        }

        $entries = array_map($this->entryData(...), $batch->entries);
        $json = json_encode($entries, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $compressed = gzencode($json, 9);
        if ($compressed === false) {
            throw new RuntimeException('Technical log archive compression failed.');
        }
        $encryptedObject = Crypt::encryptString(base64_encode($compressed));
        $storageReference = "technical-log-archives/objects/{$batch->id}.json.gz.enc";
        if (! $this->storage->put($storageReference, $encryptedObject)) {
            throw new RuntimeException('Technical log archive storage write failed.');
        }

        $entriesByTime = $batch->entries;
        usort($entriesByTime, static fn (TechnicalLogEntry $left, TechnicalLogEntry $right): int => $left->occurredAt <=> $right->occurredAt);
        $manifestReference = "technical-log-archives/manifests/{$batch->id}.json";
        $manifest = new ArchiveManifest(
            id: $batch->id,
            count: count($batch->entries),
            firstOccurredAt: $entriesByTime[0]->occurredAt,
            lastOccurredAt: $entriesByTime[array_key_last($entriesByTime)]->occurredAt,
            sha256: hash('sha256', $encryptedObject),
            storageReference: $storageReference,
            manifestReference: $manifestReference,
        );
        $this->verifyObject($manifest);
        if (! $this->storage->put($manifestReference, json_encode($this->manifestData($manifest), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))) {
            throw new RuntimeException('Technical log archive manifest write failed.');
        }

        $persisted = $this->store->recordVerifiedArchive($batch, $manifest);
        $batch->markArchived();

        return $persisted;
    }

    public function requestRestore(string $manifestId, string $actorId, string $reason): string
    {
        if ($actorId === '' || trim($reason) === '') {
            throw new RuntimeException('Technical log restore actor and reason are required.');
        }
        $manifest = $this->store->manifest($manifestId);
        if ($manifest === null || $manifest->status !== 'verified') {
            throw new RuntimeException('Technical log archive manifest was not found.');
        }

        $jobId = Str::uuid7()->toString();
        $this->store->createRestoreRequest(
            $jobId,
            $manifest->id,
            $actorId,
            $reason,
            [
                'job_id' => $jobId,
                'manifest_id' => $manifest->id,
                'requested_by' => $actorId,
                'reason' => $reason,
                'entries' => $this->readEntries($manifest),
            ],
            $this->now()->modify("+{$this->restoreReadModelMinutes} minutes"),
        );

        return $jobId;
    }

    /** @return array<string, mixed>|null */
    public function restoreReadModel(string $jobId, ?DateTimeImmutable $now = null): ?array
    {
        return $this->store->activeRestoreReadModel($jobId, $now ?? $this->now());
    }

    public function restoreStatus(string $jobId): ?string
    {
        return $this->store->restoreStatus($jobId);
    }

    private function now(): DateTimeImmutable
    {
        if ($this->clock !== null) {
            $now = ($this->clock)();
        }

        return $now ?? new DateTimeImmutable('now');
    }

    /** @return array<string, mixed> */
    private function entryData(TechnicalLogEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'source' => $entry->source,
            'category' => $entry->category,
            'occurred_at' => $entry->occurredAt->format(DATE_ATOM),
            'correlation_id' => $entry->correlationId,
            'context' => $entry->context,
        ];
    }

    /** @return array<string, scalar> */
    private function manifestData(ArchiveManifest $manifest): array
    {
        return [
            'id' => $manifest->id,
            'status' => $manifest->status,
            'count' => $manifest->count,
            'first_occurred_at' => $manifest->firstOccurredAt->format(DATE_ATOM),
            'last_occurred_at' => $manifest->lastOccurredAt->format(DATE_ATOM),
            'sha256' => $manifest->sha256,
            'storage_reference' => $manifest->storageReference,
            'manifest_reference' => $manifest->manifestReference,
        ];
    }

    private function verifyObject(ArchiveManifest $manifest): void
    {
        if (! $this->storage->exists($manifest->storageReference)
            || ! hash_equals($manifest->sha256, hash('sha256', $this->storage->get($manifest->storageReference)))) {
            throw new RuntimeException('Technical log archive verification failed.');
        }
    }

    /** @return list<array<string, mixed>> */
    private function readEntries(ArchiveManifest $manifest): array
    {
        $this->verifyObject($manifest);
        $compressed = base64_decode(Crypt::decryptString($this->storage->get($manifest->storageReference)), true);
        if ($compressed === false) {
            throw new RuntimeException('Technical log archive object is invalid.');
        }
        $json = gzdecode($compressed);
        if ($json === false) {
            throw new RuntimeException('Technical log archive object is invalid.');
        }
        $entries = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($entries)) {
            throw new RuntimeException('Technical log archive object is invalid.');
        }

        return $entries;
    }
}

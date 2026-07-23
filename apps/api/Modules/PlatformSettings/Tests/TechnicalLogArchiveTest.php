<?php

namespace Modules\PlatformSettings\Tests;

use App\Integrations\PlatformOperations\ObjectStorageTechnicalLogArchive;
use DateTimeImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Modules\PlatformSettings\Contracts\TechnicalLogArchive;
use Modules\PlatformSettings\Domain\ArchiveBatch;
use Modules\PlatformSettings\Domain\TechnicalLogEntry;
use Modules\PlatformSettings\Infrastructure\Persistence\DatabaseTechnicalLogArchiveStore;
use RuntimeException;
use Tests\TestCase;

final class TechnicalLogArchiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_archive_verifies_the_encrypted_object_before_marking_an_old_batch_archived(): void
    {
        Storage::fake('local');
        $archive = new ObjectStorageTechnicalLogArchive(
            Storage::disk('local'),
            new DatabaseTechnicalLogArchiveStore,
            static fn (): DateTimeImmutable => new DateTimeImmutable('2026-07-23T00:00:00+00:00'),
        );
        $batch = new ArchiveBatch('batch-001', [
            $this->entry('audit-001', '2026-01-01T00:00:00+00:00'),
            $this->entry('audit-002', '2026-01-02T00:00:00+00:00'),
        ], 6);

        $manifest = $archive->archive($batch);
        $storedObject = Storage::disk('local')->get($manifest->storageReference);

        $this->assertSame('archived', $batch->status);
        $this->assertCount(2, $batch->entries);
        $this->assertSame(2, $manifest->count);
        $this->assertSame('2026-01-01T00:00:00+00:00', $manifest->firstOccurredAt->format(DATE_ATOM));
        $this->assertSame('2026-01-02T00:00:00+00:00', $manifest->lastOccurredAt->format(DATE_ATOM));
        $this->assertTrue(Storage::disk('local')->exists($manifest->storageReference));
        $this->assertTrue(Storage::disk('local')->exists($manifest->manifestReference));
        $this->assertSame(hash('sha256', $storedObject), $manifest->sha256);
        $this->assertStringNotContainsString('sensitive-token', $storedObject);
    }

    public function test_archive_leaves_a_batch_active_when_it_is_not_old_enough(): void
    {
        Storage::fake('local');
        $archive = new ObjectStorageTechnicalLogArchive(
            Storage::disk('local'),
            new DatabaseTechnicalLogArchiveStore,
            static fn (): DateTimeImmutable => new DateTimeImmutable('2026-07-23T00:00:00+00:00'),
        );
        $batch = new ArchiveBatch('batch-current', [$this->entry('current-001', '2026-07-01T00:00:00+00:00')], 6);

        $this->expectException(RuntimeException::class);
        try {
            $archive->archive($batch);
        } finally {
            $this->assertSame('active', $batch->status);
            $this->assertDatabaseMissing('technical_log_archive_manifests', ['id' => 'batch-corrupted']);
            $this->assertDatabaseMissing('technical_log_archive_batches', ['id' => 'batch-corrupted']);
        }
    }

    public function test_archive_does_not_mark_a_batch_archived_when_object_hash_verification_fails(): void
    {
        $storage = Mockery::mock(Filesystem::class);
        $storage->shouldReceive('put')->once()->andReturnTrue();
        $storage->shouldReceive('exists')->once()->andReturnTrue();
        $storage->shouldReceive('get')->once()->andReturn('corrupted-object');
        $archive = new ObjectStorageTechnicalLogArchive(
            $storage,
            new DatabaseTechnicalLogArchiveStore,
            static fn (): DateTimeImmutable => new DateTimeImmutable('2026-07-23T00:00:00+00:00'),
        );
        $batch = new ArchiveBatch('batch-corrupted', [$this->entry('corrupted-001', '2026-01-01T00:00:00+00:00')], 6);

        $this->expectException(RuntimeException::class);
        try {
            $archive->archive($batch);
        } finally {
            $this->assertSame('active', $batch->status);
        }
    }

    public function test_restore_read_model_expires_without_deleting_the_permanent_archive(): void
    {
        Storage::fake('local');
        $now = new DateTimeImmutable('2026-07-23T00:00:00+00:00');
        $archive = new ObjectStorageTechnicalLogArchive(Storage::disk('local'), new DatabaseTechnicalLogArchiveStore, static fn (): DateTimeImmutable => $now, restoreReadModelMinutes: 15);
        $manifest = $archive->archive(new ArchiveBatch('batch-restore', [$this->entry('restore-001', '2026-01-01T00:00:00+00:00')], 6));

        $jobId = $archive->requestRestore($manifest->id, 'actor-001', 'Investigate a security incident');

        $this->assertNotSame('', $jobId);
        $this->assertSame($manifest->id, $archive->restoreReadModel($jobId)['manifest_id']);
        $this->assertTrue(Storage::disk('local')->exists($manifest->storageReference));
        $this->assertNull($archive->restoreReadModel($jobId, new DateTimeImmutable('2026-07-23T00:16:00+00:00')));
        $this->assertSame('expired', $archive->restoreStatus($jobId));
        $this->assertTrue(Storage::disk('local')->exists($manifest->storageReference));
    }

    public function test_container_uses_the_dedicated_configured_archive_disk(): void
    {
        Storage::fake('technical-log-archive');
        config(['platform_operations.logs.archive_disk' => 'technical-log-archive']);
        $this->app->forgetInstance(TechnicalLogArchive::class);

        $archive = $this->app->make(TechnicalLogArchive::class);
        $manifest = $archive->archive(new ArchiveBatch('batch-dedicated-disk', [$this->entry('disk-001', '2026-01-01T00:00:00+00:00')], 6));

        $this->assertTrue(Storage::disk('technical-log-archive')->exists($manifest->storageReference));
        $this->assertFalse(Storage::disk('local')->exists($manifest->storageReference));
    }

    public function test_verified_manifest_and_restore_read_model_survive_a_new_archive_adapter_instance(): void
    {
        Storage::fake('local');
        $now = new DateTimeImmutable('2026-07-23T00:00:00+00:00');
        $firstRequest = new ObjectStorageTechnicalLogArchive(Storage::disk('local'), new DatabaseTechnicalLogArchiveStore, static fn (): DateTimeImmutable => $now);
        $manifest = $firstRequest->archive(new ArchiveBatch('batch-durable', [$this->entry('durable-001', '2026-01-01T00:00:00+00:00')], 6));

        $secondRequest = new ObjectStorageTechnicalLogArchive(Storage::disk('local'), new DatabaseTechnicalLogArchiveStore, static fn (): DateTimeImmutable => $now);
        $jobId = $secondRequest->requestRestore($manifest->id, 'actor-001', 'Durable restore check');

        $thirdRequest = new ObjectStorageTechnicalLogArchive(Storage::disk('local'), new DatabaseTechnicalLogArchiveStore, static fn (): DateTimeImmutable => $now);

        $this->assertSame('available', $thirdRequest->restoreStatus($jobId));
        $this->assertSame($manifest->id, $thirdRequest->restoreReadModel($jobId)['manifest_id']);
        $this->assertDatabaseHas('technical_log_archive_batches', ['id' => 'batch-durable', 'status' => 'archived']);
        $this->assertDatabaseHas('technical_log_archive_manifests', ['id' => 'batch-durable', 'status' => 'verified', 'sha256' => $manifest->sha256]);
        $this->assertDatabaseHas('technical_log_archive_restore_requests', ['id' => $jobId, 'status' => 'available']);
    }

    private function entry(string $id, string $occurredAt): TechnicalLogEntry
    {
        return new TechnicalLogEntry($id, 'mock-audit', 'audit', new DateTimeImmutable($occurredAt), 'corr-'.$id, ['token' => 'sensitive-token']);
    }
}

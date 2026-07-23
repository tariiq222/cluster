<?php

namespace Modules\PlatformSettings\Tests;

use App\Integrations\PlatformOperations\CompositeTechnicalLogSource;
use DateTimeImmutable;
use InvalidArgumentException;
use Modules\PlatformSettings\Contracts\TechnicalLogArchive;
use Modules\PlatformSettings\Contracts\TechnicalLogSource;
use Modules\PlatformSettings\Domain\ArchiveBatch;
use Modules\PlatformSettings\Domain\ArchiveManifest;
use Modules\PlatformSettings\Domain\TechnicalLogEntry;
use Modules\PlatformSettings\Domain\TechnicalLogFilter;
use Modules\PlatformSettings\Domain\TechnicalLogPage;
use Modules\PlatformSettings\Features\Logs\Handler\TechnicalLogsHandler;
use Tests\TestCase;

final class TechnicalLogsHandlerTest extends TestCase
{
    public function test_entry_redacts_sensitive_context_values_without_hiding_its_operational_identity(): void
    {
        $entry = new TechnicalLogEntry(
            id: 'security-001',
            source: 'security',
            category: 'security',
            occurredAt: new DateTimeImmutable('2026-01-05T08:30:00+03:00'),
            correlationId: 'corr-security-001',
            context: [
                'password' => 'dont-store-this-password',
                'token' => 'secret-token',
                'authorization' => 'Bearer secret-authorization',
                'cookie' => 'session=secret-cookie',
                'document_content' => 'confidential document body',
                'national_id' => '1023456789',
                'safe_value' => 'visible',
            ],
        );

        $serialized = json_encode($entry->context, JSON_THROW_ON_ERROR);

        foreach ([
            'dont-store-this-password',
            'secret-token',
            'secret-authorization',
            'secret-cookie',
            'confidential document body',
            '1023456789',
        ] as $sensitiveValue) {
            $this->assertStringNotContainsString($sensitiveValue, $serialized);
        }

        $this->assertSame('security', $entry->source);
        $this->assertSame('security', $entry->category);
        $this->assertSame('corr-security-001', $entry->correlationId);
        $this->assertSame('visible', $entry->context['safe_value']);
    }

    public function test_composite_reads_each_source_separately_orders_entries_and_signs_its_cursor(): void
    {
        $audit = new TechnicalLogsTestSource('audit', [
            $this->entry('audit-2', 'audit', '2026-01-03T08:00:00+03:00'),
            $this->entry('audit-1', 'audit', '2026-01-01T08:00:00+03:00'),
        ]);
        $security = new TechnicalLogsTestSource('security', [
            $this->entry('security-1', 'security', '2026-01-02T08:00:00+03:00'),
        ]);
        $source = new CompositeTechnicalLogSource([$audit, $security], 'test-cursor-secret');

        $first = $source->search(new TechnicalLogFilter(perPage: 2));
        $second = $source->search(new TechnicalLogFilter(perPage: 2, cursor: $first->nextCursor));

        $this->assertSame(2, $audit->calls);
        $this->assertSame(2, $security->calls);
        $this->assertSame(['audit-1', 'security-1'], array_map(static fn (TechnicalLogEntry $entry): string => $entry->id, $first->entries));
        $this->assertNotNull($first->nextCursor);
        $this->assertSame(['audit-2'], array_map(static fn (TechnicalLogEntry $entry): string => $entry->id, $second->entries));
        $this->assertNull($second->nextCursor);

        $this->expectException(InvalidArgumentException::class);
        $source->search(new TechnicalLogFilter(perPage: 2, cursor: $first->nextCursor.'tampered'));
    }

    public function test_restore_requires_the_logs_restore_capability_and_a_reason(): void
    {
        $archive = new TechnicalLogsTestArchive;
        $handler = new TechnicalLogsHandler(new TechnicalLogsTestSource('audit', []), $archive);

        try {
            $handler->requestRestore('manifest-001', 'actor-001', 'Incident investigation', []);
            $this->fail('Expected a missing capability exception.');
        } catch (\DomainException $exception) {
            $this->assertSame('platform_operations.logs.restore is required.', $exception->getMessage());
        }

        $this->assertSame('restore-job-001', $handler->requestRestore(
            'manifest-001',
            'actor-001',
            'Incident investigation',
            ['platform_operations.logs.restore'],
        ));
        $this->assertSame(['manifest-001', 'actor-001', 'Incident investigation'], $archive->lastRequest);
    }

    public function test_v1_container_binding_exposes_only_the_deterministic_redacted_mock_source(): void
    {
        $source = $this->app->make(TechnicalLogSource::class);

        $page = $source->search(new TechnicalLogFilter(perPage: 10));

        $this->assertSame(['audit', 'security', 'system', 'operations'], array_map(
            static fn (TechnicalLogEntry $entry): string => $entry->category,
            $page->entries,
        ));
        $this->assertSame('[REDACTED]', $page->entries[0]->context['document_content']);
        $this->assertSame('[REDACTED]', $page->entries[1]->context['password']);
        $this->assertSame('[REDACTED]', $page->entries[0]->context['cookie']);
        $this->assertSame('[REDACTED]', $page->entries[3]->context['national_id']);
    }

    public function test_composite_drains_each_source_cursor_before_building_the_signed_composite_page(): void
    {
        $source = new TechnicalLogsPagedTestSource(125);
        $composite = new CompositeTechnicalLogSource([$source], 'test-cursor-secret');

        $first = $composite->search(new TechnicalLogFilter(perPage: 100));
        $second = $composite->search(new TechnicalLogFilter(perPage: 100, cursor: $first->nextCursor));

        $this->assertCount(100, $first->entries);
        $this->assertCount(25, $second->entries);
        $this->assertSame('paged-000', $first->entries[0]->id);
        $this->assertSame('paged-124', $second->entries[24]->id);
        $this->assertSame(4, $source->calls);
    }

    private function entry(string $id, string $category, string $occurredAt): TechnicalLogEntry
    {
        return new TechnicalLogEntry($id, $category, $category, new DateTimeImmutable($occurredAt), 'corr-'.$id, []);
    }
}

final class TechnicalLogsTestSource implements TechnicalLogSource
{
    public int $calls = 0;

    /** @param list<TechnicalLogEntry> $entries */
    public function __construct(private readonly string $name, private readonly array $entries) {}

    public function search(TechnicalLogFilter $filter): TechnicalLogPage
    {
        $this->calls++;

        return new TechnicalLogPage($this->entries, null);
    }
}

final class TechnicalLogsTestArchive implements TechnicalLogArchive
{
    /** @var list<string>|null */
    public ?array $lastRequest = null;

    public function archive(ArchiveBatch $batch): ArchiveManifest
    {
        throw new \LogicException('Not needed by this test.');
    }

    public function requestRestore(string $manifestId, string $actorId, string $reason): string
    {
        $this->lastRequest = [$manifestId, $actorId, $reason];

        return 'restore-job-001';
    }
}

final class TechnicalLogsPagedTestSource implements TechnicalLogSource
{
    public int $calls = 0;

    public function __construct(private readonly int $count) {}

    public function search(TechnicalLogFilter $filter): TechnicalLogPage
    {
        $this->calls++;
        $offset = $filter->cursor === null ? 0 : (int) $filter->cursor;
        $entries = [];
        for ($index = $offset; $index < min($offset + $filter->perPage, $this->count); $index++) {
            $entries[] = new TechnicalLogEntry(
                sprintf('paged-%03d', $index),
                'paged',
                'system',
                new DateTimeImmutable(sprintf('2026-01-01T00:%02d:00+00:00', intdiv($index, 60))),
                sprintf('corr-paged-%03d', $index),
                [],
            );
        }

        $nextOffset = $offset + count($entries);

        return new TechnicalLogPage($entries, $nextOffset < $this->count ? (string) $nextOffset : null);
    }
}

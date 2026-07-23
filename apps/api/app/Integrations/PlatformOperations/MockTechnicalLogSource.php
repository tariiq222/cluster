<?php

namespace App\Integrations\PlatformOperations;

use DateTimeImmutable;
use Modules\PlatformSettings\Contracts\TechnicalLogSource;
use Modules\PlatformSettings\Domain\TechnicalLogEntry;
use Modules\PlatformSettings\Domain\TechnicalLogFilter;
use Modules\PlatformSettings\Domain\TechnicalLogPage;

final readonly class MockTechnicalLogSource implements TechnicalLogSource
{
    public function search(TechnicalLogFilter $filter): TechnicalLogPage
    {
        $entries = array_values(array_filter($this->fixtures(), static fn (TechnicalLogEntry $entry): bool => ($filter->category === null || $entry->category === $filter->category)
            && ($filter->source === null || $entry->source === $filter->source)
            && ($filter->correlationId === null || $entry->correlationId === $filter->correlationId),
        ));

        return new TechnicalLogPage(array_slice($entries, 0, $filter->perPage), null);
    }

    /** @return list<TechnicalLogEntry> */
    private function fixtures(): array
    {
        return [
            new TechnicalLogEntry('audit-001', 'mock-audit', 'audit', new DateTimeImmutable('2026-01-05T05:00:00+00:00'), 'corr-audit-001', ['actor' => 'system', 'document_content' => 'never exposed', 'cookie' => 'never exposed']),
            new TechnicalLogEntry('security-001', 'mock-security', 'security', new DateTimeImmutable('2026-01-05T06:00:00+00:00'), 'corr-security-001', ['password' => 'never exposed', 'event' => 'login_failed']),
            new TechnicalLogEntry('system-001', 'mock-system', 'system', new DateTimeImmutable('2026-01-05T07:00:00+00:00'), 'corr-system-001', ['token' => 'never exposed', 'event' => 'worker_restarted']),
            new TechnicalLogEntry('operations-001', 'mock-operations', 'operations', new DateTimeImmutable('2026-01-05T08:00:00+00:00'), 'corr-operations-001', ['authorization' => 'never exposed', 'national_id' => 'never exposed', 'event' => 'backup_completed']),
        ];
    }
}

<?php

namespace Modules\PlatformSettings\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

final class ArchiveBatch
{
    public string $status = 'active';

    /** @param list<TechnicalLogEntry> $entries */
    public function __construct(
        public readonly string $id,
        public readonly array $entries,
        public readonly int $activeLogMonths,
    ) {
        if ($id === '' || $activeLogMonths < 1 || $activeLogMonths > 120) {
            throw new InvalidArgumentException('Technical log archive batch is invalid.');
        }
        foreach ($entries as $entry) {
            if (! $entry instanceof TechnicalLogEntry) {
                throw new InvalidArgumentException('Archive batches contain only technical log entries.');
            }
        }
    }

    public function isEligibleAt(DateTimeImmutable $now): bool
    {
        $cutoff = $now->modify("-{$this->activeLogMonths} months");

        foreach ($this->entries as $entry) {
            if ($entry->occurredAt >= $cutoff) {
                return false;
            }
        }

        return $this->entries !== [];
    }

    public function markArchived(): void
    {
        $this->status = 'archived';
    }
}

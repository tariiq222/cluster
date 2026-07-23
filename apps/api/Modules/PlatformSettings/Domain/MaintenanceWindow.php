<?php

namespace Modules\PlatformSettings\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class MaintenanceWindow
{
    public function __construct(
        public string $id,
        public DateTimeImmutable $startsAt,
        public ?DateTimeImmutable $endsAt,
        public string $messageAr,
        public string $messageEn,
        public string $status = 'scheduled',
    ) {
        if ($id === '') {
            throw new InvalidArgumentException('maintenance_window_id_required');
        }
        if ($endsAt !== null && $endsAt <= $startsAt) {
            throw new InvalidArgumentException('maintenance_window_end_must_follow_start');
        }
        if (trim($messageAr) === '' || trim($messageEn) === '') {
            throw new InvalidArgumentException('maintenance_window_localized_messages_required');
        }
        if (! in_array($status, ['scheduled', 'active', 'cancelled'], true)) {
            throw new InvalidArgumentException('maintenance_window_status_invalid');
        }
    }

    public function isActiveAt(DateTimeImmutable $now): bool
    {
        return $this->status !== 'cancelled'
            && $now >= $this->startsAt
            && ($this->endsAt === null || $now < $this->endsAt);
    }

    public function messageFor(string $locale): string
    {
        return $locale === 'ar' ? $this->messageAr : $this->messageEn;
    }
}

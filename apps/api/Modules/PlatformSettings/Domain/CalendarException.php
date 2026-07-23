<?php

namespace Modules\PlatformSettings\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class CalendarException
{
    private function __construct(
        public string $type,
        public DateTimeImmutable $startsOn,
        public ?DateTimeImmutable $endsOn,
        public bool $isOfficialHoliday,
        public bool $isWorkingDay,
        public ?string $startsAt,
        public ?string $endsAt,
        public ?string $reason,
    ) {}

    public static function forRange(string $type, DateTimeImmutable $startsOn, ?DateTimeImmutable $endsOn, bool $isWorkingDay, ?string $startsAt, ?string $endsAt, ?string $reason = null): self
    {
        if (! in_array($type, ['official_holiday', 'local_closure', 'local_hours', 'official_holiday_work_override', 'ramadan'], true)) {
            throw new InvalidArgumentException('Calendar exception type is invalid.');
        }
        if ($endsOn !== null && $endsOn->format('Y-m-d') < $startsOn->format('Y-m-d')) {
            throw new InvalidArgumentException('Calendar exception range is invalid.');
        }
        if (! $isWorkingDay && ($startsAt !== null || $endsAt !== null)) {
            throw new InvalidArgumentException('Non-working exception cannot have hours.');
        }
        if ($isWorkingDay && (! self::isTime($startsAt) || ! self::isTime($endsAt) || $startsAt >= $endsAt)) {
            throw new InvalidArgumentException('Calendar exception hours are invalid.');
        }

        return new self($type, $startsOn, $endsOn, $type === 'official_holiday', $isWorkingDay, $startsAt, $endsAt, $reason);
    }

    public static function officialHoliday(DateTimeImmutable $date, string $reason): self
    {
        return self::forRange('official_holiday', $date, null, false, null, null, $reason);
    }

    public static function officialHolidayWorkOverride(DateTimeImmutable $date, string $startsAt, string $endsAt, string $reason): self
    {
        return self::forRange('official_holiday_work_override', $date, null, true, $startsAt, $endsAt, $reason);
    }

    public function appliesOn(DateTimeImmutable $date): bool
    {
        $day = $date->format('Y-m-d');

        return $day >= $this->startsOn->format('Y-m-d')
            && ($this->endsOn === null || $day <= $this->endsOn->format('Y-m-d'));
    }

    public function requiresOfficialHolidayOverrideCapability(): bool
    {
        return $this->type === 'official_holiday_work_override';
    }

    private static function isTime(?string $value): bool
    {
        return is_string($value) && preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9])?\z/', $value) === 1;
    }
}

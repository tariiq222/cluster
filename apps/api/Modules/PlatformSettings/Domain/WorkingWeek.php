<?php

namespace Modules\PlatformSettings\Domain;

use InvalidArgumentException;

final readonly class WorkingWeek
{
    private function __construct(
        public int $weekday,
        public bool $isWorkingDay,
        public ?string $startsAt,
        public ?string $endsAt,
    ) {}

    public static function forDay(int $weekday, bool $isWorkingDay, ?string $startsAt, ?string $endsAt): self
    {
        if ($weekday < 1 || $weekday > 7) {
            throw new InvalidArgumentException('Weekday must use ISO-8601 numbering.');
        }
        if (! $isWorkingDay && ($startsAt !== null || $endsAt !== null)) {
            throw new InvalidArgumentException('Non-working weekdays cannot have working hours.');
        }
        if ($isWorkingDay && (! self::isTime($startsAt) || ! self::isTime($endsAt) || $startsAt >= $endsAt)) {
            throw new InvalidArgumentException('Working weekday hours are invalid.');
        }

        return new self($weekday, $isWorkingDay, $startsAt, $endsAt);
    }

    /** @param list<array{0: string, 1: string}> $intervals */
    public static function fromIntervals(int $weekday, array $intervals): self
    {
        if (count($intervals) > 1) {
            throw new InvalidArgumentException('A weekday may contain only one working period.');
        }
        if ($intervals === []) {
            return self::forDay($weekday, false, null, null);
        }

        return self::forDay($weekday, true, $intervals[0][0], $intervals[0][1]);
    }

    private static function isTime(?string $value): bool
    {
        return is_string($value) && preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9])?\z/', $value) === 1;
    }
}

<?php

namespace Modules\PlatformSettings\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use Modules\PlatformSettings\Domain\CalendarException;
use Modules\PlatformSettings\Domain\CalendarScope;
use Modules\PlatformSettings\Domain\WorkingWeek;
use Tests\TestCase;

final class BusinessCalendarDomainTest extends TestCase
{
    public function test_calendar_scope_allows_only_the_supported_scope_types_and_platform_identifier(): void
    {
        $platform = CalendarScope::from('platform', 'platform');

        $this->assertSame('platform', $platform->type);
        $this->assertSame('platform', $platform->id);

        $this->expectException(InvalidArgumentException::class);
        CalendarScope::from('unit', '0197f0e0-0000-7000-8000-000000000001');
    }

    public function test_working_week_rejects_a_second_period_for_one_weekday(): void
    {
        $this->expectException(InvalidArgumentException::class);

        WorkingWeek::fromIntervals(1, [
            ['08:00', '12:00'],
            ['13:00', '17:00'],
        ]);
    }

    public function test_ramadan_exception_accepts_only_a_valid_gregorian_range(): void
    {
        $exception = CalendarException::forRange(
            'ramadan',
            new DateTimeImmutable('2026-02-18'),
            new DateTimeImmutable('2026-03-19'),
            true,
            '10:00',
            '15:00',
            'Ramadan hours',
        );

        $this->assertTrue($exception->appliesOn(new DateTimeImmutable('2026-03-19')));
        $this->assertFalse($exception->appliesOn(new DateTimeImmutable('2026-03-20')));
    }
}

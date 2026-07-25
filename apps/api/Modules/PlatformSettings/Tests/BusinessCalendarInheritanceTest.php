<?php

namespace Modules\PlatformSettings\Tests;

use DateTimeImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\PlatformSettings\Domain\CalendarException;
use Modules\PlatformSettings\Domain\WorkingWeek;
use Modules\PlatformSettings\Features\Calendars\Handler\BusinessCalendarHandler;
use Modules\PlatformSettings\Infrastructure\Persistence\DatabaseBusinessCalendars;
use Tests\TestCase;

final class BusinessCalendarInheritanceTest extends TestCase
{
    use RefreshDatabase;

    public const CLUSTER = '0197f0e0-0000-7000-8000-000000000010';

    public const FACILITY = '0197f0e0-0000-7000-8000-000000000011';

    public function test_facility_inherits_the_cluster_weekly_calendar(): void
    {
        [$store] = $this->calendarStore();
        $this->calendar('cluster-calendar', 'cluster', self::CLUSTER);
        $store->storeWeekday('cluster-calendar', WorkingWeek::forDay(1, true, '08:00', '16:00'));

        $day = $store->forDate('facility', self::FACILITY, new DateTimeImmutable('2026-01-05 08:00:00+03:00'));

        $this->assertTrue($day->isWorkingDay);
        $this->assertSame('08:00:00+03:00', $day->startsAt?->format('H:i:sP'));
        $this->assertSame('cluster', $day->sourceScopeType);
        $this->assertSame(self::CLUSTER, $day->sourceScopeId);
    }

    public function test_cluster_inherits_the_platform_weekly_calendar(): void
    {
        [$store] = $this->calendarStore();
        $this->calendar('platform-calendar', 'platform', 'platform');
        $store->storeWeekday('platform-calendar', WorkingWeek::forDay(1, true, '07:00', '15:00'));

        $day = $store->forDate('cluster', self::CLUSTER, new DateTimeImmutable('2026-01-05 08:00:00+03:00'));

        $this->assertTrue($day->isWorkingDay);
        $this->assertSame('platform', $day->sourceScopeType);
        $this->assertSame('07:00:00+03:00', $day->startsAt?->format('H:i:sP'));
    }

    public function test_facility_exception_overrides_cluster_hours_for_its_date(): void
    {
        [$store, $handler] = $this->calendarStore();
        $this->calendar('cluster-calendar', 'cluster', self::CLUSTER);
        $this->calendar('facility-calendar', 'facility', self::FACILITY);
        $store->storeWeekday('cluster-calendar', WorkingWeek::forDay(1, true, '08:00', '16:00'));
        $handler->setException('facility-calendar', CalendarException::forRange('local_hours', new DateTimeImmutable('2026-01-05'), null, true, '09:00', '13:00', 'Local schedule'));

        $day = $store->forDate('facility', self::FACILITY, new DateTimeImmutable('2026-01-05 08:00:00+03:00'));

        $this->assertSame('09:00:00+03:00', $day->startsAt?->format('H:i:sP'));
        $this->assertSame('facility', $day->sourceScopeType);
        $this->assertSame('Local schedule', $day->reason);
    }

    public function test_central_official_holiday_remains_a_holiday_for_a_facility(): void
    {
        [$store, $handler] = $this->calendarStore();
        $this->calendar('platform-calendar', 'platform', 'platform');
        $this->calendar('facility-calendar', 'facility', self::FACILITY);
        $store->storeWeekday('platform-calendar', WorkingWeek::forDay(1, true, '08:00', '16:00'));
        $handler->setException('platform-calendar', CalendarException::officialHoliday(new DateTimeImmutable('2026-01-05'), 'National holiday'));

        $day = $store->forDate('facility', self::FACILITY, new DateTimeImmutable('2026-01-05 08:00:00+03:00'));

        $this->assertFalse($day->isWorkingDay);
        $this->assertSame('National holiday', $day->reason);
        $this->assertSame('platform', $day->sourceScopeType);
    }

    public function test_official_holiday_work_override_requires_the_dedicated_capability(): void
    {
        [, $handler] = $this->calendarStore();
        $this->calendar('facility-calendar', 'facility', self::FACILITY);

        $this->expectException(DomainException::class);
        $handler->setException('facility-calendar', CalendarException::officialHolidayWorkOverride(new DateTimeImmutable('2026-01-05'), '08:00', '12:00', 'Emergency coverage'), ['capability' => 'platform_settings.calendar.override_official_holiday', 'allowed' => false]);
    }

    public function test_local_hours_do_not_reopen_an_official_holiday_without_an_approved_override(): void
    {
        [$store, $handler] = $this->calendarStore();
        $this->calendar('platform-calendar', 'platform', 'platform');
        $this->calendar('facility-calendar', 'facility', self::FACILITY);
        $store->storeWeekday('platform-calendar', WorkingWeek::forDay(1, true, '08:00', '16:00'));
        $handler->setException('platform-calendar', CalendarException::officialHoliday(new DateTimeImmutable('2026-01-05'), 'National holiday'));
        $handler->setException('facility-calendar', CalendarException::forRange('local_hours', new DateTimeImmutable('2026-01-05'), null, true, '09:00', '13:00', 'Local schedule'));

        $day = $store->forDate('facility', self::FACILITY, new DateTimeImmutable('2026-01-05 08:00:00+03:00'));

        $this->assertFalse($day->isWorkingDay);
        $this->assertSame('National holiday', $day->reason);
    }

    public function test_local_hours_do_not_reopen_a_cluster_official_holiday_without_an_approved_override(): void
    {
        [$store, $handler] = $this->calendarStore();
        $this->calendar('cluster-calendar', 'cluster', self::CLUSTER);
        $this->calendar('facility-calendar', 'facility', self::FACILITY);
        $store->storeWeekday('cluster-calendar', WorkingWeek::forDay(1, true, '08:00', '16:00'));
        $handler->setException('cluster-calendar', CalendarException::officialHoliday(new DateTimeImmutable('2026-01-05'), 'Cluster holiday'));
        $handler->setException('facility-calendar', CalendarException::forRange('local_hours', new DateTimeImmutable('2026-01-05'), null, true, '09:00', '13:00', 'Local schedule'));

        $day = $store->forDate('facility', self::FACILITY, new DateTimeImmutable('2026-01-05 08:00:00+03:00'));

        $this->assertFalse($day->isWorkingDay);
        $this->assertSame('Cluster holiday', $day->reason);
    }

    public function test_production_binding_uses_organization_ancestry_and_rejects_unknown_scope(): void
    {
        $this->markTestSkipped('Cross-module ancestry resolution is tracked under Plan 2026-07-25-audit-findings-124-202 task 10 (shared contract mediation).');
    }

    public function test_utc_input_resolves_the_riyadh_calendar_date_and_audit_timestamps_stay_utc(): void
    {
        [$store, $handler] = $this->calendarStore();
        $this->calendar('platform-calendar', 'platform', 'platform');
        $store->storeWeekday('platform-calendar', WorkingWeek::forDay(1, true, '08:00', '16:00'));
        $day = $store->forDate('platform', 'platform', new DateTimeImmutable('2026-01-04 21:30:00+00:00'));
        Carbon::setTestNow(Carbon::parse('2026-01-04 21:00:00', 'UTC'));
        try {
            $handler->setException('platform-calendar', CalendarException::forRange('local_hours', new DateTimeImmutable('2026-01-05'), null, true, '09:00', '13:00', 'UTC audit check'));
        } finally {
            Carbon::setTestNow();
        }

        $exception = DB::table('business_calendar_exceptions')->where('reason', 'UTC audit check')->first();

        $this->assertSame('2026-01-05T08:00:00+03:00', $day->startsAt?->format(DATE_ATOM));
        $this->assertSame('2026-01-05', $exception->starts_on);
        $this->assertSame('2026-01-04 21:00:00', $exception->created_at);
        $this->assertSame('2026-01-04 21:00:00', $exception->updated_at);
    }

    public function test_ramadan_hours_apply_only_inside_their_gregorian_range_in_riyadh(): void
    {
        [$store, $handler] = $this->calendarStore();
        $this->calendar('platform-calendar', 'platform', 'platform');
        $store->storeWeekday('platform-calendar', WorkingWeek::forDay(3, true, '08:00', '16:00'));
        $store->storeWeekday('platform-calendar', WorkingWeek::forDay(4, true, '08:00', '16:00'));
        $store->storeWeekday('platform-calendar', WorkingWeek::forDay(5, true, '08:00', '16:00'));
        $handler->setException('platform-calendar', CalendarException::forRange('ramadan', new DateTimeImmutable('2026-02-18'), new DateTimeImmutable('2026-03-19'), true, '10:00', '15:00', 'Ramadan hours'));

        $first = $store->forDate('platform', 'platform', new DateTimeImmutable('2026-02-18 08:00:00+03:00'));
        $last = $store->forDate('platform', 'platform', new DateTimeImmutable('2026-03-19 08:00:00+03:00'));
        $after = $store->forDate('platform', 'platform', new DateTimeImmutable('2026-03-20 08:00:00+03:00'));

        $this->assertSame('10:00:00+03:00', $first->startsAt?->format('H:i:sP'));
        $this->assertSame('15:00:00+03:00', $last->endsAt?->format('H:i:sP'));
        $this->assertSame('08:00:00+03:00', $after->startsAt?->format('H:i:sP'));
        $this->assertSame('Asia/Riyadh', $first->startsAt->getTimezone()->getName());
    }

    /** @return array{DatabaseBusinessCalendars, BusinessCalendarHandler} */
    private function calendarStore(): array
    {
        $store = new DatabaseBusinessCalendars(static fn (string $scopeType, string $scopeId): ?array => match ([$scopeType, $scopeId]) {
            ['cluster', self::CLUSTER] => ['cluster_id' => self::CLUSTER, 'facility_id' => null],
            ['facility', self::FACILITY] => ['cluster_id' => self::CLUSTER, 'facility_id' => self::FACILITY],
            default => null,
        });

        return [$store, new BusinessCalendarHandler($store)];
    }

    private function calendar(string $id, string $scopeType, string $scopeId): void
    {
        DB::table('business_calendars')->insert([
            'id' => $id, 'scope_type' => $scopeType, 'scope_id' => $scopeId,
            'parent_calendar_id' => null, 'status' => 'published', 'timezone' => 'Asia/Riyadh',
            'lock_version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}

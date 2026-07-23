<?php

namespace Modules\PlatformSettings\Infrastructure\Persistence;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\PlatformSettings\Contracts\EffectiveBusinessDay;
use Modules\PlatformSettings\Contracts\ResolveBusinessCalendar;
use Modules\PlatformSettings\Domain\CalendarException;
use Modules\PlatformSettings\Domain\CalendarScope;
use Modules\PlatformSettings\Domain\WorkingWeek;
use stdClass;

final class DatabaseBusinessCalendars implements ResolveBusinessCalendar
{
    private const RIYADH = 'Asia/Riyadh';

    /**
     * @param  Closure(string, string): ?array{cluster_id: ?string, facility_id: ?string}  $organizationAncestry
     *
     * This is an Organization-owned read-only contract supplied by the application
     * composition root. Keeping the port inbound prevents a same-rank module import.
     */
    public function __construct(private readonly Closure $organizationAncestry) {}

    public function forDate(string $scopeType, string $scopeId, DateTimeImmutable $date): EffectiveBusinessDay
    {
        $scope = CalendarScope::from($scopeType, $scopeId);
        $layers = $this->layersFor($scope);
        $localDate = $date->setTimezone(new DateTimeZone(self::RIYADH));
        $day = $localDate->format('Y-m-d');
        $weekday = (int) $localDate->format('N');
        $calendars = [];
        foreach ($layers as $layer) {
            $calendars[] = ['scope' => $layer, 'calendar' => $this->activeCalendar($layer)];
        }

        $effective = new EffectiveBusinessDay(false, null, null, 'platform', 'platform', 'non_working_weekday');
        foreach ($calendars as $entry) {
            $calendar = $entry['calendar'];
            if (! $calendar instanceof stdClass) {
                continue;
            }
            $weekly = DB::table('business_calendar_weekdays')
                ->where('business_calendar_id', $calendar->id)
                ->where('weekday', $weekday)
                ->first();
            if (! $weekly instanceof stdClass) {
                continue;
            }
            $effective = $this->dayFromHours(
                (bool) $weekly->is_working_day,
                $weekly->starts_at,
                $weekly->ends_at,
                $entry['scope'],
                (bool) $weekly->is_working_day ? 'weekly_schedule' : 'non_working_weekday',
                $localDate,
            );
        }

        $officialHolidayApplied = false;
        foreach ($calendars as $entry) {
            if (! $entry['calendar'] instanceof stdClass) {
                continue;
            }
            foreach ($this->exceptionsFor($entry['calendar']->id, $day) as $exception) {
                if ((bool) $exception->is_official_holiday) {
                    $effective = $this->dayFromHours(false, null, null, $entry['scope'], 'official_holiday', $localDate, $exception->reason);
                    $officialHolidayApplied = true;
                }
            }
        }

        foreach (array_slice($calendars, 1) as $entry) {
            if (! $entry['calendar'] instanceof stdClass) {
                continue;
            }
            foreach ($this->exceptionsFor($entry['calendar']->id, $day) as $exception) {
                if (! (bool) $exception->is_working_day && ! (bool) $exception->is_official_holiday) {
                    $effective = $this->dayFromHours(false, null, null, $entry['scope'], 'local_closure', $localDate, $exception->reason);
                }
            }
        }

        foreach (array_slice($calendars, 1) as $entry) {
            if (! $entry['calendar'] instanceof stdClass) {
                continue;
            }
            foreach ($this->exceptionsFor($entry['calendar']->id, $day) as $exception) {
                if ($exception->exception_type === 'official_holiday_work_override' && (bool) $exception->is_working_day) {
                    $effective = $this->dayFromHours(true, $exception->starts_at, $exception->ends_at, $entry['scope'], 'official_holiday_work_override', $localDate, $exception->reason);
                }
            }
        }

        foreach ($calendars as $entry) {
            if (! $entry['calendar'] instanceof stdClass) {
                continue;
            }
            foreach ($this->exceptionsFor($entry['calendar']->id, $day) as $exception) {
                if (! $officialHolidayApplied && $exception->exception_type === 'local_hours' && (bool) $exception->is_working_day) {
                    $effective = $this->dayFromHours(true, $exception->starts_at, $exception->ends_at, $entry['scope'], (string) ($exception->reason ?? 'local_hours'), $localDate, $exception->reason);
                }
                if ($exception->exception_type === 'ramadan' && $effective->isWorkingDay) {
                    $effective = $this->dayFromHours(true, $exception->starts_at, $exception->ends_at, $entry['scope'], 'ramadan_hours', $localDate, $exception->reason);
                }
            }
        }

        return $effective;
    }

    public function storeWeekday(string $calendarId, WorkingWeek $weekday): void
    {
        $this->calendar($calendarId);
        DB::table('business_calendar_weekdays')->updateOrInsert(
            ['business_calendar_id' => $calendarId, 'weekday' => $weekday->weekday],
            ['id' => (string) Str::uuid(), 'is_working_day' => $weekday->isWorkingDay, 'starts_at' => $weekday->startsAt, 'ends_at' => $weekday->endsAt, 'lock_version' => 1, 'created_at' => now(), 'updated_at' => now()],
        );
    }

    public function storeException(string $calendarId, CalendarException $exception): void
    {
        $this->calendar($calendarId);
        DB::table('business_calendar_exceptions')->insert([
            'id' => (string) Str::uuid(), 'business_calendar_id' => $calendarId, 'exception_type' => $exception->type,
            // Calendar dates and clock hours are civil Riyadh schedule values, not instants.
            // Laravel's audit timestamps remain UTC (the application default) independently.
            'starts_on' => $exception->startsOn->format('Y-m-d'), 'ends_on' => $exception->endsOn?->format('Y-m-d'),
            'is_official_holiday' => $exception->isOfficialHoliday, 'is_working_day' => $exception->isWorkingDay,
            'starts_at' => $exception->startsAt, 'ends_at' => $exception->endsAt, 'reason' => $exception->reason,
            'created_by' => null, 'lock_version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return list<CalendarScope> */
    private function layersFor(CalendarScope $scope): array
    {
        if ($scope->type === 'platform') {
            return [$scope];
        }
        $ancestry = ($this->organizationAncestry)($scope->type, $scope->id);
        if ($ancestry === null || ! is_string($ancestry['cluster_id'] ?? null)) {
            throw new DomainException('calendar_scope_not_found');
        }
        $layers = [CalendarScope::from('platform', 'platform'), CalendarScope::from('cluster', $ancestry['cluster_id'])];
        if ($scope->type === 'facility') {
            if (! is_string($ancestry['facility_id'] ?? null)) {
                throw new DomainException('calendar_scope_not_found');
            }
            $layers[] = CalendarScope::from('facility', $ancestry['facility_id']);
        }

        return $layers;
    }

    private function activeCalendar(CalendarScope $scope): ?stdClass
    {
        return DB::table('business_calendars')
            ->where('scope_type', $scope->type)
            ->where('scope_id', $scope->id)
            ->where('status', 'published')
            ->orderByDesc('updated_at')
            ->first();
    }

    /** @return list<stdClass> */
    private function exceptionsFor(string $calendarId, string $day): array
    {
        return DB::table('business_calendar_exceptions')
            ->where('business_calendar_id', $calendarId)
            ->where('starts_on', '<=', $day)
            ->where(fn ($query) => $query->whereNull('ends_on')->orWhere('ends_on', '>=', $day))
            ->orderBy('starts_on')
            ->get()
            ->all();
    }

    private function calendar(string $calendarId): stdClass
    {
        $calendar = DB::table('business_calendars')->where('id', $calendarId)->first();
        if (! $calendar instanceof stdClass) {
            throw new DomainException('business_calendar_not_found');
        }

        return $calendar;
    }

    private function dayFromHours(bool $isWorkingDay, ?string $startsAt, ?string $endsAt, CalendarScope $scope, string $reason, DateTimeImmutable $date, ?string $exceptionReason = null): EffectiveBusinessDay
    {
        return new EffectiveBusinessDay(
            $isWorkingDay,
            $isWorkingDay ? $this->atRiyadhTime($date, $startsAt) : null,
            $isWorkingDay ? $this->atRiyadhTime($date, $endsAt) : null,
            $scope->type,
            $scope->id,
            $exceptionReason ?? $reason,
        );
    }

    private function atRiyadhTime(DateTimeImmutable $date, ?string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($date->format('Y-m-d').' '.$time, new DateTimeZone(self::RIYADH));
    }
}

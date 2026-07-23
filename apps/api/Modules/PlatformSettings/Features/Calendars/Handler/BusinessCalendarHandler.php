<?php

namespace Modules\PlatformSettings\Features\Calendars\Handler;

use DateTimeImmutable;
use DomainException;
use Modules\PlatformSettings\Contracts\EffectiveBusinessDay;
use Modules\PlatformSettings\Contracts\ResolveBusinessCalendar;
use Modules\PlatformSettings\Domain\CalendarException;
use Modules\PlatformSettings\Domain\WorkingWeek;
use Modules\PlatformSettings\Infrastructure\Persistence\DatabaseBusinessCalendars;

final class BusinessCalendarHandler implements ResolveBusinessCalendar
{
    public function __construct(private readonly DatabaseBusinessCalendars $calendars) {}

    public function forDate(string $scopeType, string $scopeId, DateTimeImmutable $date): EffectiveBusinessDay
    {
        return $this->calendars->forDate($scopeType, $scopeId, $date);
    }

    public function setWeekday(string $calendarId, WorkingWeek $weekday): void
    {
        $this->calendars->storeWeekday($calendarId, $weekday);
    }

    /** @param array{capability?: string, allowed?: bool} $authorizationDecision */
    public function setException(string $calendarId, CalendarException $exception, array $authorizationDecision = []): void
    {
        $hasOfficialHolidayOverride = ($authorizationDecision['capability'] ?? null) === 'platform_settings.calendar.override_official_holiday'
            && ($authorizationDecision['allowed'] ?? false) === true;
        if ($exception->requiresOfficialHolidayOverrideCapability() && ! $hasOfficialHolidayOverride) {
            throw new DomainException('official_holiday_override_not_allowed');
        }
        $this->calendars->storeException($calendarId, $exception);
    }
}

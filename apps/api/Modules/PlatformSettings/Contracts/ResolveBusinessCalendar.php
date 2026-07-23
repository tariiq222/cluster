<?php

namespace Modules\PlatformSettings\Contracts;

use DateTimeImmutable;

interface ResolveBusinessCalendar
{
    public function forDate(string $scopeType, string $scopeId, DateTimeImmutable $date): EffectiveBusinessDay;
}

final readonly class EffectiveBusinessDay
{
    public function __construct(
        public bool $isWorkingDay,
        public ?DateTimeImmutable $startsAt,
        public ?DateTimeImmutable $endsAt,
        public string $sourceScopeType,
        public string $sourceScopeId,
        public ?string $reason,
    ) {}
}

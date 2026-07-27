<?php

declare(strict_types=1);

namespace Modules\Audit\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class AuditRetentionPolicy
{
    public const MINIMUM_RETENTION_DAYS = 2555;

    public const STANDARD_DAYS = 2555;

    public const SECURITY_DAYS = 3650;

    public const REGULATED_DAYS = 3650;

    public function __construct(private int $floorDays)
    {
        if ($floorDays < self::MINIMUM_RETENTION_DAYS) {
            throw new InvalidArgumentException('audit_retention_floor_too_low');
        }
    }

    public function retentionUntil(DateTimeImmutable $recordedAt, string $class): DateTimeImmutable
    {
        $classDays = match ($class) {
            'standard' => self::STANDARD_DAYS,
            'security' => self::SECURITY_DAYS,
            'regulated' => self::REGULATED_DAYS,
            default => throw new InvalidArgumentException('audit_retention_class_invalid'),
        };

        return $recordedAt->modify('+'.max($classDays, $this->floorDays).' days');
    }
}

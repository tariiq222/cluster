<?php

namespace Modules\Authorization\Domain;

enum ClassificationLevel: string
{
    case PUBLIC = 'public';
    case INTERNAL = 'internal';
    case CONFIDENTIAL = 'confidential';
    case TOP_SECRET = 'top_secret';

    /**
     * @return list<self>
     */
    public static function ordered(): array
    {
        return [
            self::PUBLIC,
            self::INTERNAL,
            self::CONFIDENTIAL,
            self::TOP_SECRET,
        ];
    }

    public function compare(self $other): int
    {
        return $this->rank() <=> $other->rank();
    }

    public function isAtLeast(self $other): bool
    {
        return $this->compare($other) >= 0;
    }

    public function requiresSensitiveAccessAudit(): bool
    {
        return $this->isAtLeast(self::CONFIDENTIAL);
    }

    private function rank(): int
    {
        return match ($this) {
            self::PUBLIC => 0,
            self::INTERNAL => 1,
            self::CONFIDENTIAL => 2,
            self::TOP_SECRET => 3,
        };
    }
}

<?php

namespace Modules\PlatformSettings\Domain;

use InvalidArgumentException;

final readonly class CalendarScope
{
    private const TYPES = ['platform', 'cluster', 'facility'];

    private function __construct(public string $type, public string $id) {}

    public static function from(string $type, string $id): self
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('Calendar scope type is invalid.');
        }
        if ($type === 'platform' && $id !== 'platform') {
            throw new InvalidArgumentException('Platform calendar scope id must be platform.');
        }
        if ($type !== 'platform' && $id === '') {
            throw new InvalidArgumentException('Calendar scope id is required.');
        }

        return new self($type, $id);
    }
}

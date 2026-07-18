<?php

namespace Modules\Documents\Domain;

use Illuminate\Support\Str;
use InvalidArgumentException;

final class UuidV7
{
    public static function generate(): string
    {
        return Str::uuid7()->toString();
    }

    public static function assert(string $value, string $field): void
    {
        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $value) !== 1) {
            throw new InvalidArgumentException("{$field} must be a lowercase UUIDv7.");
        }
    }
}

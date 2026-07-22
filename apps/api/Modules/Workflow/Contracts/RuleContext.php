<?php

namespace Modules\Workflow\Contracts;

final readonly class RuleContext
{
    /** @param array<string, mixed> $values */
    public function __construct(public array $values = []) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }
}

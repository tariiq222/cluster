<?php

declare(strict_types=1);

namespace Modules\Audit\Domain;

use InvalidArgumentException;
use JsonException;
use Modules\Authorization\Contracts\AccessDecision;
use Modules\Authorization\Contracts\AccessProjection;

/**
 * Canonical read-time redaction and ABAC field projection for Audit context.
 *
 * List, detail, and export paths share this service so an allowed row cannot
 * bypass field-level hidden/masked decisions on a secondary projection path.
 */
final class AuditContextProjection
{
    public function __construct(
        private readonly SensitiveValueRedactor $redactor,
    ) {}

    /** @return array<string, mixed>|null */
    public function decode(mixed $stored): ?array
    {
        try {
            $context = is_string($stored)
                ? json_decode($stored, true, 16, JSON_THROW_ON_ERROR)
                : $stored;
        } catch (JsonException) {
            return null;
        }
        if (! is_array($context)) {
            return null;
        }

        try {
            return $this->redactor->redact($context);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function apply(array $context, AccessDecision $decision): array
    {
        $projected = AccessProjection::fromDecision($decision)->compose(
            ['payload' => $context],
            fn (array $payload, array $fieldAccess): array => $this->filter($payload, $fieldAccess),
        );

        return is_array($projected['payload'] ?? null) ? $projected['payload'] : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $fieldAccess
     * @return array<string, mixed>
     */
    private function filter(array $payload, array $fieldAccess): array
    {
        $wildcard = $fieldAccess['*'] ?? null;
        if ($wildcard === 'hidden') {
            return [];
        }
        if ($wildcard === 'masked') {
            $payload = $this->maskValues($payload);
        }

        foreach ($fieldAccess as $path => $mode) {
            if ($path === '*' || ($mode !== 'hidden' && $mode !== 'masked')) {
                continue;
            }
            $segments = explode('.', $path);
            if (in_array('', $segments, true)) {
                continue;
            }
            $this->applyRule($payload, $segments, $mode);
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function applyRule(array &$payload, array $segments, string $mode): void
    {
        $segment = array_shift($segments);
        if (! is_string($segment) || ! array_key_exists($segment, $payload)) {
            return;
        }
        if ($segments === []) {
            if ($mode === 'hidden') {
                unset($payload[$segment]);
            } else {
                $payload[$segment] = SensitiveValueRedactor::REDACTED;
            }

            return;
        }
        if (! is_array($payload[$segment])) {
            return;
        }
        $nested = $payload[$segment];
        $this->applyRule($nested, $segments, $mode);
        $payload[$segment] = $nested;
    }

    /** @param array<string, mixed> $value */
    private function maskValues(array $value): array
    {
        foreach ($value as $key => $item) {
            $value[$key] = is_array($item)
                ? $this->maskValues($item)
                : SensitiveValueRedactor::REDACTED;
        }

        return $value;
    }
}

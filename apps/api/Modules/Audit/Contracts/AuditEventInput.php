<?php

declare(strict_types=1);

namespace Modules\Audit\Contracts;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;

final readonly class AuditEventInput
{
    public const ACTOR_USER = 'user';

    public const ACTOR_SERVICE = 'service';

    public const ACTOR_SYSTEM = 'system';

    public const OUTCOME_SUCCEEDED = 'succeeded';

    public const OUTCOME_DENIED = 'denied';

    public const OUTCOME_FAILED = 'failed';

    public const CLASSIFICATION_PUBLIC = 'public';

    public const CLASSIFICATION_INTERNAL = 'internal';

    public const CLASSIFICATION_CONFIDENTIAL = 'confidential';

    public const CLASSIFICATION_TOP_SECRET = 'top_secret';

    public const RETENTION_STANDARD = 'standard';

    public const RETENTION_SECURITY = 'security';

    public const RETENTION_REGULATED = 'regulated';

    public const MAX_CONTEXT_DEPTH = 6;

    public const MAX_CONTEXT_KEYS = 100;

    public const MAX_CONTEXT_BYTES = 16 * 1024;

    /** @var list<string> */
    public const ALLOWED_ACTOR_TYPES = [
        self::ACTOR_USER,
        self::ACTOR_SERVICE,
        self::ACTOR_SYSTEM,
    ];

    /** @var list<string> */
    public const ALLOWED_OUTCOMES = [
        self::OUTCOME_SUCCEEDED,
        self::OUTCOME_DENIED,
        self::OUTCOME_FAILED,
    ];

    /** @var list<string> */
    public const ALLOWED_CLASSIFICATIONS = [
        self::CLASSIFICATION_PUBLIC,
        self::CLASSIFICATION_INTERNAL,
        self::CLASSIFICATION_CONFIDENTIAL,
        self::CLASSIFICATION_TOP_SECRET,
    ];

    /** @var list<string> */
    public const ALLOWED_RETENTION_CLASSES = [
        self::RETENTION_STANDARD,
        self::RETENTION_SECURITY,
        self::RETENTION_REGULATED,
    ];

    /**
     * @param  array<array-key, mixed>  $context
     */
    public function __construct(
        public string $eventId,
        public string $sourceModule,
        public string $action,
        public string $eventType,
        public string $actorType,
        public ?string $actorId,
        public ?string $originalActorId,
        public string $subjectType,
        public ?string $subjectId,
        public string $correlationId,
        public string $outcome,
        public string $classification,
        public array $context,
        public DateTimeImmutable $occurredAt,
        public string $retentionClass,
    ) {
        self::assertUuidV7($eventId, 'eventId');
        self::assertModuleToken($sourceModule, 'sourceModule');
        self::assertCatalogToken($action, 128, 'action');
        self::assertEventType($eventType);

        if (! in_array($actorType, self::ALLOWED_ACTOR_TYPES, true)) {
            throw new InvalidArgumentException('audit_actor_type_invalid');
        }

        if ($actorType === self::ACTOR_SYSTEM) {
            if ($actorId !== null || $originalActorId !== null) {
                throw new InvalidArgumentException('audit_system_actor_must_not_have_id');
            }
        } elseif ($actorId === null) {
            throw new InvalidArgumentException('audit_actor_id_required');
        }

        self::assertNullableUuidV7($actorId, 'actorId');
        self::assertNullableUuidV7($originalActorId, 'originalActorId');
        self::assertModuleToken($subjectType, 'subjectType');
        self::assertNullableUuidV7($subjectId, 'subjectId');
        self::assertUuidV7($correlationId, 'correlationId');

        if (! in_array($outcome, self::ALLOWED_OUTCOMES, true)) {
            throw new InvalidArgumentException('audit_outcome_invalid');
        }
        if (! in_array($classification, self::ALLOWED_CLASSIFICATIONS, true)) {
            throw new InvalidArgumentException('audit_classification_invalid');
        }
        if (! in_array($retentionClass, self::ALLOWED_RETENTION_CLASSES, true)) {
            throw new InvalidArgumentException('audit_retention_class_invalid');
        }

        self::assertContext($context);
        self::assertUtcMilliseconds($occurredAt, 'occurredAt');
    }

    public static function assertUuidV7(string $value, string $field): void
    {
        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $value) !== 1) {
            throw new InvalidArgumentException("audit_{$field}_invalid");
        }
    }

    public static function assertNullableUuidV7(?string $value, string $field): void
    {
        if ($value !== null) {
            self::assertUuidV7($value, $field);
        }
    }

    public static function assertModuleToken(string $value, string $field): void
    {
        if (strlen($value) > 64 || preg_match('/\A[a-z][a-z0-9_-]*\z/', $value) !== 1) {
            throw new InvalidArgumentException("audit_{$field}_invalid");
        }
    }

    public static function assertCatalogToken(string $value, int $maximumLength, string $field): void
    {
        if (strlen($value) > $maximumLength
            || preg_match('/\A[a-z][a-z0-9_-]*(?:\.[a-z][a-z0-9_-]*)*\z/', $value) !== 1) {
            throw new InvalidArgumentException("audit_{$field}_invalid");
        }
    }

    public static function assertEventType(string $value): void
    {
        if (strlen($value) > 160
            || preg_match('/\Acom\.cluster\.[a-z][a-z0-9_-]*\.[a-z][a-z0-9]*\.v[1-9][0-9]*\z/', $value) !== 1) {
            throw new InvalidArgumentException('audit_eventType_invalid');
        }
    }

    public static function assertUtcMilliseconds(DateTimeImmutable $value, string $field): void
    {
        if ($value->getTimezone()->getName() !== 'Z'
            && $value->getTimezone()->getName() !== 'UTC'
            && $value->format('P') !== '+00:00') {
            throw new InvalidArgumentException("audit_{$field}_must_be_utc");
        }

        $canonical = $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.v\Z', $canonical, new DateTimeZone('UTC'));
        if ($parsed === false || $parsed->format('Y-m-d\TH:i:s.v\Z') !== $canonical) {
            throw new InvalidArgumentException("audit_{$field}_invalid");
        }
    }

    /**
     * @param  array<array-key, mixed>  $context
     */
    public static function assertContext(array $context): void
    {
        $keyCount = 0;
        self::validateContextValue($context, 0, $keyCount);

        try {
            $encoded = json_encode(
                $context,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('audit_context_invalid', 0, $exception);
        }

        if (strlen($encoded) > self::MAX_CONTEXT_BYTES) {
            throw new InvalidArgumentException('audit_context_too_large');
        }
    }

    /**
     * Canonicalize a validated JSON-compatible value. Object keys are sorted;
     * list order is preserved.
     *
     * @return array<array-key, mixed>
     */
    public static function canonicalizeContext(array $context): array
    {
        self::assertContext($context);

        return self::canonicalizeArray($context);
    }

    private static function validateContextValue(mixed $value, int $depth, int &$keyCount): void
    {
        if ($depth > self::MAX_CONTEXT_DEPTH) {
            throw new InvalidArgumentException('audit_context_depth_exceeded');
        }

        if (is_array($value)) {

            $isList = array_is_list($value);
            foreach ($value as $key => $nested) {
                if (! $isList) {
                    if (! is_string($key) || $key === '' || str_contains($key, "\0")) {
                        throw new InvalidArgumentException('audit_context_key_invalid');
                    }
                    ++$keyCount;
                    if ($keyCount > self::MAX_CONTEXT_KEYS) {
                        throw new InvalidArgumentException('audit_context_key_limit_exceeded');
                    }
                }
                self::validateContextValue($nested, $depth + 1, $keyCount);
            }

            return;
        }

        if (is_string($value) || is_int($value) || is_bool($value) || $value === null) {
            return;
        }

        throw new InvalidArgumentException('audit_context_value_unsupported');
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function canonicalizeArray(array $value): array
    {
        if (array_is_list($value)) {
            foreach ($value as $index => $nested) {
                if (is_array($nested)) {
                    $value[$index] = self::canonicalizeArray($nested);
                }
            }

            return $value;
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $nested) {
            if (is_array($nested)) {
                $value[$key] = self::canonicalizeArray($nested);
            }
        }

        return $value;
    }
}

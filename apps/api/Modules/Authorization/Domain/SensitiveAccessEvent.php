<?php

namespace Modules\Authorization\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class SensitiveAccessEvent
{
    public function __construct(
        public string $eventId,
        public string $accessDecisionId,
        public string $actorUserId,
        public string $originalActorUserId,
        public string $resourceType,
        public string $resourceId,
        public string $action,
        public ClassificationLevel $classification,
        public string $correlationId,
        public ?string $sourceIp,
        public ?string $deviceFingerprintHash,
        public DateTimeImmutable $occurredAt,
    ) {
        self::requireNonEmpty($eventId, 'Event id', 36);
        self::requireNonEmpty($accessDecisionId, 'Access decision id', 36);
        self::requireNonEmpty($actorUserId, 'Actor user id', 36);
        self::requireNonEmpty($originalActorUserId, 'Original actor user id', 36);
        self::requireNonEmpty($resourceType, 'Resource type', 64);
        self::requireNonEmpty($resourceId, 'Resource id', 36);
        self::requireNonEmpty($action, 'Action', 64);
        self::requireNonEmpty($correlationId, 'Correlation id', 36);

        if (! $classification->requiresSensitiveAccessAudit()) {
            throw new InvalidArgumentException('Sensitive access events require confidential or top_secret classification.');
        }

        if ($sourceIp !== null && filter_var($sourceIp, FILTER_VALIDATE_IP) === false) {
            throw new InvalidArgumentException('Source IP must be a valid IP address when provided.');
        }

        if ($deviceFingerprintHash !== null
            && preg_match('/\A[a-f0-9]{64}\z/', $deviceFingerprintHash) !== 1) {
            throw new InvalidArgumentException('Device fingerprint hash must be a lowercase SHA-256 hash when provided.');
        }
    }

    private static function requireNonEmpty(string $value, string $field, int $maximumLength): void
    {
        if (trim($value) === '' || strlen($value) > $maximumLength) {
            throw new InvalidArgumentException("{$field} must contain between 1 and {$maximumLength} characters.");
        }
    }
}

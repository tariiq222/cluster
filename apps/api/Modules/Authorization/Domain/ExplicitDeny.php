<?php

namespace Modules\Authorization\Domain;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class ExplicitDeny
{
    private function __construct(
        public string $id,
        public ?string $userId,
        public string $capabilityCode,
        public ?ClassificationLevel $classification,
        public ?string $organizationUnitId,
        public ?string $resourcePattern,
        public string $reason,
        public string $issuedByUserId,
        public string $issuedAt,
        public ?string $expiresAt,
        public bool $revocable,
    ) {}

    public static function create(
        string $id,
        ?string $userId,
        string $capabilityCode,
        ?ClassificationLevel $classification,
        ?string $organizationUnitId,
        ?string $resourcePattern,
        string $reason,
        string $issuedByUserId,
        string $issuedAt,
        ?string $expiresAt,
        bool $revocable,
    ): self {
        UuidV7::assert($id, 'Explicit deny id');
        if ($userId !== null) {
            UuidV7::assert($userId, 'Explicit deny user id');
        }
        if ($organizationUnitId !== null) {
            UuidV7::assert($organizationUnitId, 'Explicit deny organization unit id');
        }
        if ($userId === null && $organizationUnitId === null) {
            throw new InvalidArgumentException('Explicit deny must target a user or organization unit.');
        }

        $moduleCode = explode('.', $capabilityCode, 2)[0];
        if (! Capability::belongsToModule($capabilityCode, $moduleCode)) {
            throw new InvalidArgumentException('Explicit deny capability is invalid.');
        }
        if ($resourcePattern !== null && ! self::isValidResourcePattern($resourcePattern)) {
            throw new InvalidArgumentException('Explicit deny resource pattern is invalid.');
        }
        if (trim($reason) === '' || mb_strlen($reason) > 2000) {
            throw new InvalidArgumentException('Explicit deny reason is invalid.');
        }
        UuidV7::assert($issuedByUserId, 'Explicit deny issuer user id');

        $issued = self::parseUtc($issuedAt, 'Explicit deny issued time');
        if ($expiresAt !== null && self::parseUtc($expiresAt, 'Explicit deny expiry time') <= $issued) {
            throw new InvalidArgumentException('Explicit deny window is invalid.');
        }

        return new self(
            $id,
            $userId,
            $capabilityCode,
            $classification,
            $organizationUnitId,
            $resourcePattern,
            $reason,
            $issuedByUserId,
            $issuedAt,
            $expiresAt,
            $revocable,
        );
    }

    /** @return array{id: string, user_id: ?string, capability_code: string, classification: ?string, organization_unit_id: ?string, resource_pattern: ?string, reason: string, issued_by_user_id: string, issued_at: string, expires_at: ?string, revocable: bool} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->userId,
            'capability_code' => $this->capabilityCode,
            'classification' => $this->classification?->value,
            'organization_unit_id' => $this->organizationUnitId,
            'resource_pattern' => $this->resourcePattern,
            'reason' => $this->reason,
            'issued_by_user_id' => $this->issuedByUserId,
            'issued_at' => $this->issuedAt,
            'expires_at' => $this->expiresAt,
            'revocable' => $this->revocable,
        ];
    }

    public static function matchesResourceType(?string $resourcePattern, string $resourceType): bool
    {
        if ($resourcePattern === null) {
            return true;
        }
        if (! self::isValidResourcePattern($resourcePattern)) {
            return false;
        }
        if (str_ends_with($resourcePattern, '*')) {
            return str_starts_with($resourceType, substr($resourcePattern, 0, -1));
        }

        return hash_equals($resourcePattern, $resourceType);
    }

    public static function isValidResourcePattern(string $value): bool
    {
        return mb_strlen($value) <= 96
            && preg_match('/\A[a-z][a-z0-9_.-]*\*?\z/', $value) === 1;
    }

    private static function parseUtc(string $value, string $field): DateTimeImmutable
    {
        $timestamp = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.v\Z', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($timestamp === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $timestamp->format('Y-m-d\TH:i:s.v\Z') !== $value) {
            throw new InvalidArgumentException("{$field} must be an RFC3339 UTC timestamp with milliseconds.");
        }

        return $timestamp;
    }
}

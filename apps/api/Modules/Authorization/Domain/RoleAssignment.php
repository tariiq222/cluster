<?php

namespace Modules\Authorization\Domain;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class RoleAssignment
{
    private function __construct(
        public string $id,
        public string $userId,
        public string $roleId,
        public ?string $scopeId,
        public string $startAt,
        public ?string $endAt,
        public string $grantedByUserId,
    ) {}

    public static function assign(
        string $id,
        string $userId,
        string $roleId,
        ?string $scopeId,
        string $startAt,
        ?string $endAt,
        string $grantedByUserId,
    ): self {
        UuidV7::assert($id, 'Role assignment id');
        UuidV7::assert($userId, 'Role assignment user id');
        UuidV7::assert($roleId, 'Role assignment role id');
        UuidV7::assert($grantedByUserId, 'Role assignment grantor id');
        if ($scopeId !== null) {
            UuidV7::assert($scopeId, 'Role assignment scope id');
        }

        $start = self::parseUtc($startAt, 'Role assignment start time');
        if ($endAt !== null && self::parseUtc($endAt, 'Role assignment end time') <= $start) {
            throw new InvalidArgumentException('Role assignment window is invalid.');
        }

        return new self($id, $userId, $roleId, $scopeId, $startAt, $endAt, $grantedByUserId);
    }

    /** @return array{id: string, user_id: string, role_id: string, scope_id: ?string, start_at: string, end_at: ?string, status: string, granted_by_user_id: string} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->userId,
            'role_id' => $this->roleId,
            'scope_id' => $this->scopeId,
            'start_at' => $this->startAt,
            'end_at' => $this->endAt,
            'status' => 'pending',
            'granted_by_user_id' => $this->grantedByUserId,
        ];
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

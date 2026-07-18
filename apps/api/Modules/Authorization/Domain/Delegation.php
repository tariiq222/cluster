<?php

namespace Modules\Authorization\Domain;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class Delegation
{
    /** @param list<string> $capabilityCodes */
    private function __construct(
        public string $id,
        public string $delegatorUserId,
        public string $delegateUserId,
        public string $moduleCode,
        public array $capabilityCodes,
        public ?string $scopeId,
        public string $startAt,
        public string $endAt,
    ) {}

    /** @param list<string> $capabilityCodes */
    public static function create(
        string $id,
        string $delegatorUserId,
        string $delegateUserId,
        string $moduleCode,
        array $capabilityCodes,
        ?string $scopeId,
        string $startAt,
        string $endAt,
    ): self {
        UuidV7::assert($id, 'Delegation id');
        UuidV7::assert($delegatorUserId, 'Delegator user id');
        UuidV7::assert($delegateUserId, 'Delegate user id');
        if ($delegatorUserId === $delegateUserId) {
            throw new InvalidArgumentException('Delegation cannot target its delegator.');
        }
        if ($scopeId !== null) {
            UuidV7::assert($scopeId, 'Delegation scope id');
        }
        if ($capabilityCodes === [] || count(array_unique($capabilityCodes, SORT_STRING)) !== count($capabilityCodes)) {
            throw new InvalidArgumentException('Delegation capabilities must be a non-empty unique set.');
        }
        foreach ($capabilityCodes as $capabilityCode) {
            if (! is_string($capabilityCode) || ! Capability::belongsToModule($capabilityCode, $moduleCode)) {
                throw new InvalidArgumentException('Delegation capabilities must belong to its module.');
            }
        }

        $start = self::parseUtc($startAt, 'Delegation start time');
        if (self::parseUtc($endAt, 'Delegation end time') <= $start) {
            throw new InvalidArgumentException('Delegation window is invalid.');
        }

        return new self(
            $id,
            $delegatorUserId,
            $delegateUserId,
            $moduleCode,
            $capabilityCodes,
            $scopeId,
            $startAt,
            $endAt,
        );
    }

    /** @return array{id: string, delegator_user_id: string, delegate_user_id: string, module_code: string, scope_id: ?string, start_at: string, end_at: string, status: string, capability_codes: list<string>} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'delegator_user_id' => $this->delegatorUserId,
            'delegate_user_id' => $this->delegateUserId,
            'module_code' => $this->moduleCode,
            'scope_id' => $this->scopeId,
            'start_at' => $this->startAt,
            'end_at' => $this->endAt,
            'status' => 'pending',
            'capability_codes' => $this->capabilityCodes,
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

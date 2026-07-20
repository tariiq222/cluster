<?php

namespace Modules\WorkRecords\Domain;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class WorkRecord
{
    /** @var list<string> */
    private const CLASSIFICATIONS = ['public', 'internal', 'confidential', 'top_secret'];

    /**
     * @param  array<string, mixed>  $payload
     */
    private function __construct(
        private string $id,
        private string $recordNumber,
        private string $workTypeVersionId,
        private string $ownerFacilityId,
        private string $creatorUserId,
        private string $classification,
        private array $payload,
        private DateTimeImmutable $submittedAt,
        private ?string $fieldPolicyKey,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function submit(
        string $id,
        string $recordNumber,
        string $workTypeVersionId,
        string $ownerFacilityId,
        string $creatorUserId,
        string $classification,
        array $payload,
        DateTimeImmutable $submittedAt,
        ?string $fieldPolicyKey = null,
    ): self {
        self::requireUuidV7($id, 'id');
        self::requireUuidV7($workTypeVersionId, 'work type version');
        self::requireUuidV7($ownerFacilityId, 'owner facility');
        self::requireUuidV7($creatorUserId, 'creator user');

        if ($recordNumber === '' || strlen($recordNumber) > 64) {
            throw new InvalidArgumentException('Record number must contain between 1 and 64 characters.');
        }

        if (! in_array($classification, self::CLASSIFICATIONS, true)) {
            throw new InvalidArgumentException('Classification must be one of the supported record classifications.');
        }

        if ($payload === []) {
            throw new InvalidArgumentException('Work record payload must not be empty.');
        }

        return new self(
            $id,
            $recordNumber,
            $workTypeVersionId,
            $ownerFacilityId,
            $creatorUserId,
            $classification,
            $payload,
            $submittedAt,
            $fieldPolicyKey,
        );
    }

    /**
     * @return array{id: string, record_number: string, work_type_version_id: string, owner: array{facility_id: string, user_id: string}, status: string, classification: string, field_policy_key: ?string, payload: array<string, mixed>, lock_version: int, submitted_at: string, created_at: string, updated_at: string}
     */
    public function toEnvelope(): array
    {
        $timestamp = $this->formatTimestamp($this->submittedAt);

        return [
            'id' => $this->id,
            'record_number' => $this->recordNumber,
            'work_type_version_id' => $this->workTypeVersionId,
            'owner' => [
                'facility_id' => $this->ownerFacilityId,
                'user_id' => $this->creatorUserId,
            ],
            'status' => 'submitted',
            'classification' => $this->classification,
            'field_policy_key' => $this->fieldPolicyKey,
            'payload' => $this->payload,
            'lock_version' => 1,
            'submitted_at' => $timestamp,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];
    }

    private static function requireUuidV7(string $value, string $field): void
    {
        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $value) !== 1) {
            throw new InvalidArgumentException("{$field} must be a lowercase UUIDv7.");
        }
    }

    private function formatTimestamp(DateTimeImmutable $timestamp): string
    {
        return $timestamp->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }
}

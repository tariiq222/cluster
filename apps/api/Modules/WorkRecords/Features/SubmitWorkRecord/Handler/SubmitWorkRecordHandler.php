<?php

namespace Modules\WorkRecords\Features\SubmitWorkRecord\Handler;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Modules\WorkRecords\Domain\WorkRecord;
use stdClass;
use UnexpectedValueException;

final class SubmitWorkRecordHandler
{
    /**
     * Persist the source envelope and its CloudEvent in one caller-owned transaction.
     *
     * @param  array<string, mixed>  $cloudEvent
     * @param  array{principal_id: string, facility_id: string, operation: string, key_hash: string, request_hash: string}  $idempotency
     * @return array{created: bool, request_hash_matches: bool, record: array<string, mixed>}
     */
    public function persist(WorkRecord $record, array $cloudEvent, array $idempotency): array
    {
        $envelope = $record->toEnvelope();
        $this->assertCloudEvent($cloudEvent, $envelope);
        $this->assertIdempotency($idempotency);

        return DB::transaction(function () use ($cloudEvent, $envelope, $idempotency): array {
            $existingKey = $this->idempotencyQuery($idempotency)->lockForUpdate()->first();
            if ($existingKey instanceof stdClass) {
                return $this->replayResult($existingKey, $idempotency['request_hash']);
            }

            $claimed = DB::table('work_record_idempotency_keys')->insertOrIgnore([
                'principal_id' => $idempotency['principal_id'],
                'facility_id' => $idempotency['facility_id'],
                'operation' => $idempotency['operation'],
                'idempotency_key_hash' => $idempotency['key_hash'],
                'request_hash' => $idempotency['request_hash'],
                'work_record_id' => $envelope['id'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if (! $claimed) {
                $concurrentKey = $this->idempotencyQuery($idempotency)->lockForUpdate()->first();
                if (! $concurrentKey instanceof stdClass) {
                    throw new LogicException('The idempotency claim could not be resolved.');
                }

                return $this->replayResult($concurrentKey, $idempotency['request_hash']);
            }

            $submittedAt = $this->databaseTimestamp($envelope['submitted_at']);
            DB::table('work_records')->insert([
                'id' => $envelope['id'],
                'record_number' => $envelope['record_number'],
                'work_type_version_id' => $envelope['work_type_version_id'],
                'owner_facility_id' => $envelope['owner']['facility_id'],
                'creator_user_id' => $envelope['owner']['user_id'],
                'status' => $envelope['status'],
                'classification' => $envelope['classification'],
                'field_policy_key' => $envelope['field_policy_key'] ?? null,
                'payload' => json_encode($envelope['payload'], JSON_THROW_ON_ERROR),
                'lock_version' => $envelope['lock_version'],
                'submitted_at' => $submittedAt,
                'created_at' => $submittedAt,
                'updated_at' => $submittedAt,
            ]);

            DB::table('outbox_events')->insert([
                'event_id' => $cloudEvent['id'],
                'aggregate_id' => $envelope['id'],
                'event_type' => $cloudEvent['type'],
                'cloud_event' => json_encode($cloudEvent, JSON_THROW_ON_ERROR),
                'occurred_at' => $this->databaseTimestamp($cloudEvent['time']),
                'published_at' => null,
                'delivery_attempts' => 0,
                'created_at' => $submittedAt,
                'updated_at' => $submittedAt,
            ]);

            return [
                'created' => true,
                'request_hash_matches' => true,
                'record' => $envelope,
            ];
        });
    }

    /**
     * @param  array{principal_id: string, facility_id: string, operation: string, key_hash: string, request_hash: string}  $idempotency
     * @return array{created: bool, request_hash_matches: bool, record: array<string, mixed>}|null
     */
    public function findReplay(array $idempotency): ?array
    {
        $this->assertIdempotency($idempotency);
        $existingKey = $this->idempotencyQuery($idempotency)->first();

        return $existingKey instanceof stdClass
            ? $this->replayResult($existingKey, $idempotency['request_hash'])
            : null;
    }

    /**
     * @param  array<string, mixed>  $cloudEvent
     * @param  array{id: string, record_number: string, work_type_version_id: string, owner: array{facility_id: string, user_id: string}, status: string, classification: string, payload: array<string, mixed>, lock_version: int, submitted_at: string, created_at: string, updated_at: string}  $envelope
     */
    private function assertCloudEvent(array $cloudEvent, array $envelope): void
    {
        foreach (['id', 'source', 'type', 'subject', 'time', 'correlationid'] as $field) {
            if (! isset($cloudEvent[$field]) || ! is_string($cloudEvent[$field]) || $cloudEvent[$field] === '') {
                throw new InvalidArgumentException("CloudEvent {$field} is required.");
            }
        }

        if (($cloudEvent['specversion'] ?? null) !== '1.0'
            || ($cloudEvent['datacontenttype'] ?? null) !== 'application/json'
            || ! is_array($cloudEvent['data'] ?? null)) {
            throw new InvalidArgumentException('Outbox events must be complete CloudEvents JSON envelopes.');
        }

        $this->assertUuidV7($cloudEvent['id'], 'CloudEvent id');
        $this->assertUuidV7($cloudEvent['correlationid'], 'CloudEvent correlation id');

        if ($cloudEvent['source'] !== '/work-records'
            || $cloudEvent['type'] !== 'com.cluster.workrecord.submitted.v1'
            || $cloudEvent['subject'] !== '/work-records/'.$envelope['id']
            || ($cloudEvent['data']['record']['id'] ?? null) !== $envelope['id']
            || ($cloudEvent['data']['classification'] ?? null) !== $envelope['classification']
            || ! array_key_exists('access_context', $cloudEvent['data'])) {
            throw new InvalidArgumentException('CloudEvent does not represent the submitted WorkRecord envelope.');
        }
    }

    /** @param array{principal_id: string, facility_id: string, operation: string, key_hash: string, request_hash: string} $idempotency */
    private function assertIdempotency(array $idempotency): void
    {
        $this->assertUuidV7($idempotency['principal_id'], 'Idempotency principal id');
        $this->assertUuidV7($idempotency['facility_id'], 'Idempotency facility id');

        if ($idempotency['operation'] === '' || strlen($idempotency['operation']) > 96) {
            throw new InvalidArgumentException('Idempotency operation is required.');
        }

        foreach (['key_hash', 'request_hash'] as $hash) {
            if (preg_match('/\A[a-f0-9]{64}\z/', $idempotency[$hash]) !== 1) {
                throw new InvalidArgumentException("Idempotency {$hash} must be a SHA-256 hash.");
            }
        }
    }

    /** @param array{principal_id: string, facility_id: string, operation: string, key_hash: string, request_hash: string} $idempotency */
    private function idempotencyQuery(array $idempotency): mixed
    {
        return DB::table('work_record_idempotency_keys')
            ->where('principal_id', $idempotency['principal_id'])
            ->where('facility_id', $idempotency['facility_id'])
            ->where('operation', $idempotency['operation'])
            ->where('idempotency_key_hash', $idempotency['key_hash']);
    }

    /** @return array{created: bool, request_hash_matches: bool, record: array<string, mixed>} */
    private function replayResult(stdClass $idempotencyKey, string $requestHash): array
    {
        $row = DB::table('work_records')->where('id', $idempotencyKey->work_record_id)->first();
        if (! $row instanceof stdClass) {
            throw new UnexpectedValueException('Stored idempotency state is incomplete.');
        }

        return [
            'created' => false,
            'request_hash_matches' => is_string($idempotencyKey->request_hash)
                && hash_equals($idempotencyKey->request_hash, $requestHash),
            'record' => $this->serialize($row),
        ];
    }

    /** @return array<string, mixed> */
    private function serialize(stdClass $row): array
    {
        return [
            'id' => $row->id,
            'record_number' => $row->record_number,
            'work_type_version_id' => $row->work_type_version_id,
            'owner' => [
                'facility_id' => $row->owner_facility_id,
                'user_id' => $row->creator_user_id,
            ],
            'status' => $row->status,
            'classification' => $row->classification,
            'field_policy_key' => $row->field_policy_key ?? null,
            'payload' => json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR),
            'lock_version' => (int) $row->lock_version,
            'submitted_at' => $this->apiTimestamp($row->submitted_at),
            'created_at' => $this->apiTimestamp($row->created_at),
            'updated_at' => $this->apiTimestamp($row->updated_at),
        ];
    }

    private function databaseTimestamp(string $timestamp): string
    {
        return (new DateTimeImmutable($timestamp))->format('Y-m-d H:i:s');
    }

    private function apiTimestamp(string $timestamp): string
    {
        return (new DateTimeImmutable($timestamp))->format('Y-m-d\TH:i:s.v\Z');
    }

    private function assertUuidV7(string $value, string $field): void
    {
        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $value) !== 1) {
            throw new InvalidArgumentException("{$field} must be a lowercase UUIDv7.");
        }
    }
}

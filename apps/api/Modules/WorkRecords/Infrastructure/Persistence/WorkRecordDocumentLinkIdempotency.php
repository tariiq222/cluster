<?php

declare(strict_types=1);

namespace Modules\WorkRecords\Infrastructure\Persistence;

use Illuminate\Support\Facades\DB;
use Modules\WorkRecords\Domain\WorkRecordIdempotencyConflict;
use UnexpectedValueException;

final class WorkRecordDocumentLinkIdempotency
{
    /** @return array<string, mixed>|null */
    public function replay(
        string $principalId,
        string $facilityId,
        string $operation,
        string $key,
        string $requestHash,
    ): ?array {
        $row = DB::table('work_record_idempotency_keys')->where([
            'principal_id' => $principalId,
            'facility_id' => $facilityId,
            'operation' => $operation,
            'idempotency_key_hash' => hash('sha256', $key),
        ])->first();

        if ($row === null) {
            return null;
        }
        if (! hash_equals((string) $row->request_hash, $requestHash)) {
            throw new WorkRecordIdempotencyConflict;
        }
        if (! is_string($row->response_payload)) {
            throw new UnexpectedValueException('Stored document-link idempotency state is unavailable.');
        }

        $response = json_decode($row->response_payload, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($response)) {
            throw new UnexpectedValueException('Stored document-link idempotency state is invalid.');
        }

        return $response;
    }

    /** @param array<string, mixed> $response */
    public function store(
        string $principalId,
        string $facilityId,
        string $operation,
        string $key,
        string $requestHash,
        string $workRecordId,
        array $response,
    ): void {
        $now = now();
        $inserted = DB::table('work_record_idempotency_keys')->insertOrIgnore([
            'principal_id' => $principalId,
            'facility_id' => $facilityId,
            'operation' => $operation,
            'idempotency_key_hash' => hash('sha256', $key),
            'request_hash' => $requestHash,
            'work_record_id' => $workRecordId,
            'response_payload' => json_encode($response, JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($inserted !== 1) {
            throw new WorkRecordIdempotencyConflict;
        }
    }
}

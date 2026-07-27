<?php

declare(strict_types=1);

namespace Modules\Audit\Features\VerifyAuditIntegrity\Handler;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use Modules\Audit\Contracts\AuditEventInput;
use Modules\Audit\Contracts\RecordAuditEvent;
use Modules\Audit\Infrastructure\Persistence\AuditIdempotencyConflict;
use Modules\Audit\Infrastructure\Persistence\AuditIdempotencyStore;
use Modules\Audit\Infrastructure\Persistence\AuditIntegrityRepository;

/**
 * Synchronous, capability-gated integrity verification command.
 *
 * One DB transaction (or a no-op replay) covers the full Task 6 contract:
 *
 *   1. The (principalId, operation, keyHash) idempotency record is
 *      claimed, or the stored response is returned when an equal
 *      replay lands on the same key.
 *   2. {@see AuditIntegrityRepository::verifyStream()}/{@see verifyRange()}
 *      walks the stream chain, writes the immutable `verification`
 *      checkpoint, and — on violation — appends
 *      `AuditIntegrityViolationDetectedV1` atomically with the checkpoint.
 *   3. The sanitized response payload is stored in `audit_idempotency_keys`
 *      with status 201 on success or 409 on violation. The returned body
 *      NEVER carries `event_hash`, `previous_hash`, the canonical JSON,
 *      the context JSON, HMAC key material, or any secret-bearing field.
 *
 * The capability check belongs to the HTTP layer; the handler still
 * records the immutable Audit activity of the verification through the
 * shared {@see RecordAuditEvent} contract so a security-auditor call
 * is auditable like every other M01 command.
 */
final class VerifyAuditIntegrityHandler
{
    public const OPERATION = 'audit.integrity.verify';

    public function __construct(
        private readonly RecordAuditEvent $recorder,
        private readonly AuditIntegrityRepository $integrity,
        private readonly AuditIdempotencyStore $idempotency,
    ) {}

    /**
     * @param  array{
     *     principal_id: string,
     *     facility_id: ?string,
     *     correlation_id: string,
     *     stream_key: string,
     *     first_sequence: ?int,
     *     last_sequence: ?int,
     *     occurred_at: DateTimeImmutable
     * }  $command
     * @return array{
     *     result: array<string, mixed>,
     *     etag: string,
     *     status: int,
     *     replayed: bool
     * }
     */
    public function handle(array $command, string $idempotencyKey): array
    {
        AuditEventInput::assertUuidV7($command['principal_id'], 'principalId');
        AuditEventInput::assertNullableUuidV7($command['facility_id'], 'facilityId');
        AuditEventInput::assertUuidV7($command['correlation_id'], 'correlationId');

        $streamKey = (string) $command['stream_key'];
        $firstSequence = $command['first_sequence'] ?? null;
        $lastSequence = $command['last_sequence'] ?? null;

        if ($firstSequence !== null && $firstSequence < 1) {
            throw new InvalidArgumentException('audit_integrity_first_sequence_invalid');
        }
        if ($lastSequence !== null && $lastSequence < 1) {
            throw new InvalidArgumentException('audit_integrity_last_sequence_invalid');
        }

        if (($firstSequence === null) !== ($lastSequence === null)) {
            throw new InvalidArgumentException('audit_integrity_range_partial');
        }

        $canonicalRequest = [
            'principal_id' => $command['principal_id'],
            'facility_id' => $command['facility_id'],
            'stream_key' => $streamKey,
            'first_sequence' => $firstSequence,
            'last_sequence' => $lastSequence,
        ];

        try {
            $canonicalEncoded = (string) json_encode(
                $canonicalRequest,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('audit_integrity_canonical_invalid', 0, $exception);
        }

        $keyHash = hash('sha256', $idempotencyKey);
        $requestHash = hash('sha256', $canonicalEncoded);

        $existing = $this->idempotency->findResponse(
            $command['principal_id'],
            self::OPERATION,
            $keyHash,
        );
        if ($existing !== null) {
            if (! hash_equals((string) $existing->request_hash, $requestHash)) {
                throw new AuditIdempotencyMismatch(self::OPERATION);
            }

            $payload = $this->decodePayload((string) $existing->response_payload);

            return [
                'result' => $payload,
                'etag' => $this->etagFor($payload, (int) $existing->response_status),
                'status' => (int) $existing->response_status,
                'replayed' => true,
            ];
        }

        $verificationId = (string) Str::uuid7();
        $eventId = (string) Str::uuid7();
        $occurredAt = $command['occurred_at'];

        try {
            $result = DB::transaction(function () use (
                $command,
                $streamKey,
                $firstSequence,
                $lastSequence,
                $verificationId,
                $occurredAt,
                $eventId,
                $keyHash,
                $requestHash,
            ): array {
                if ($firstSequence !== null && $lastSequence !== null) {
                    $raw = $this->integrity->verifyRange(
                        verificationId: $verificationId,
                        correlationId: $command['correlation_id'],
                        streamKey: $streamKey,
                        actorId: $command['principal_id'],
                        firstSequence: $firstSequence,
                        lastSequence: $lastSequence,
                    );
                } else {
                    $raw = $this->integrity->verifyStream(
                        verificationId: $verificationId,
                        correlationId: $command['correlation_id'],
                        streamKey: $streamKey,
                        actorId: $command['principal_id'],
                    );
                }

                $isVerified = $raw['status'] === AuditIntegrityRepository::STATUS_VERIFIED;
                $status = $isVerified ? 201 : 409;
                $sanitized = $this->sanitize($raw, $streamKey);

                $this->idempotency->storeResponse(
                    id: $eventId,
                    principalId: $command['principal_id'],
                    operation: self::OPERATION,
                    keyHash: $keyHash,
                    requestHash: $requestHash,
                    responseStatus: $status,
                    responsePayload: $sanitized,
                    resourceId: $verificationId,
                );

                $this->recordAuditActivity(
                    command: $command,
                    verificationId: $verificationId,
                    raw: $raw,
                    occurredAt: $occurredAt,
                    eventId: $eventId,
                    isVerified: $isVerified,
                );

                return [
                    'result' => $sanitized,
                    'status' => $status,
                ];
            }, 1);
        } catch (AuditIdempotencyConflict) {
            $replayed = $this->idempotency->findResponse(
                $command['principal_id'],
                self::OPERATION,
                $keyHash,
            );
            if ($replayed === null) {
                throw new AuditIdempotencyMismatch(self::OPERATION);
            }
            if (! hash_equals((string) $replayed->request_hash, $requestHash)) {
                throw new AuditIdempotencyMismatch(self::OPERATION);
            }

            $payload = $this->decodePayload((string) $replayed->response_payload);

            return [
                'result' => $payload,
                'etag' => $this->etagFor($payload, (int) $replayed->response_status),
                'status' => (int) $replayed->response_status,
                'replayed' => true,
            ];
        }

        return [
            'result' => $result['result'],
            'etag' => $this->etagFor($result['result'], $result['status']),
            'status' => $result['status'],
            'replayed' => false,
        ];
    }

    /**
     * @param  array{principal_id: string, facility_id: ?string, correlation_id: string}  $command
     * @param  array<string, mixed>  $raw
     */
    private function recordAuditActivity(
        array $command,
        string $verificationId,
        array $raw,
        DateTimeImmutable $occurredAt,
        string $eventId,
        bool $isVerified,
    ): void {
        $classification = $isVerified
            ? AuditEventInput::CLASSIFICATION_CONFIDENTIAL
            : AuditEventInput::CLASSIFICATION_TOP_SECRET;
        $action = $isVerified
            ? 'audit.integrity.verified'
            : 'audit.integrity.violated';
        $outcome = $isVerified
            ? AuditEventInput::OUTCOME_SUCCEEDED
            : AuditEventInput::OUTCOME_FAILED;

        $input = new AuditEventInput(
            eventId: $eventId,
            sourceModule: 'audit',
            action: $action,
            eventType: 'com.cluster.audit.auditeventrecorded.v1',
            actorType: AuditEventInput::ACTOR_USER,
            actorId: $command['principal_id'],
            originalActorId: null,
            subjectType: 'audit_integrity_verification',
            subjectId: $verificationId,
            correlationId: $command['correlation_id'],
            outcome: $outcome,
            classification: $classification,
            context: [
                'stream_key' => (string) $raw['stream_key'],
                'first_sequence' => (int) $raw['first_sequence'],
                'last_sequence' => (int) $raw['last_sequence'],
                'verified_event_count' => (int) $raw['verified_event_count'],
                'integrity_status' => (string) $raw['status'],
            ],
            occurredAt: $occurredAt,
            retentionClass: AuditEventInput::RETENTION_REGULATED,
        );

        $this->recorder->record($input);
    }

    /**
     * Strip key/hash/canonical material. Public surface may never
     * surface the chain's HMAC inputs or the request canonicalization
     * to the caller.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function sanitize(array $raw, string $streamKey): array
    {
        return [
            'stream_key' => $streamKey,
            'first_sequence' => (int) $raw['first_sequence'],
            'last_sequence' => (int) $raw['last_sequence'],
            'verified_event_count' => (int) $raw['verified_event_count'],
            'integrity_status' => (string) $raw['status'],
            'checkpoint_id' => (string) $raw['checkpoint_id'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function etagFor(array $payload, int $status): string
    {
        $raw = (string) json_encode($payload, JSON_THROW_ON_ERROR);

        return '"'.hash('sha256', (string) $status.':'.$raw).'"';
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePayload(string $raw): array
    {
        try {
            $decoded = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('audit_integrity_idempotency_payload_invalid', 0, $exception);
        }

        if (! is_array($decoded)) {
            throw new InvalidArgumentException('audit_integrity_idempotency_payload_invalid');
        }

        return $decoded;
    }
}

/**
 * Raised when the same idempotency key is replayed with a request body
 * that differs from the original canonical request. The HTTP layer
 * surfaces this as a typed 409 `idempotency-conflict` problem.
 */
final class AuditIdempotencyMismatch extends InvalidArgumentException
{
    public function __construct(string $operation)
    {
        if (preg_match('/\A[a-z][a-z0-9_.-]{0,127}\z/', $operation) !== 1) {
            throw new InvalidArgumentException('audit_idempotency_operation_invalid');
        }

        parent::__construct('audit_idempotency_mismatch');
    }
}

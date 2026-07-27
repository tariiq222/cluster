<?php

declare(strict_types=1);

namespace Modules\Audit\Features\VerifyAuditIntegrity\Http;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Modules\Audit\Contracts\AuditEventInput;
use Modules\Audit\Features\VerifyAuditIntegrity\Handler\AuditIdempotencyMismatch;
use Modules\Audit\Features\VerifyAuditIntegrity\Handler\VerifyAuditIntegrityHandler;
use Modules\Audit\Http\AuditApi;
use Modules\Authorization\Contracts\AccessDecision;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Identity\Contracts\PrincipalContext;
use Modules\Identity\Contracts\ResolvePrincipalContext;

/**
 * POST /api/v1/audit/integrity-verifications
 *
 * Capability: `audit.integrity.verify`. Authorization is evaluated BEFORE
 * any query/body disclosure; a denial or absent principal returns a typed
 * problem without leaking the request, the stream, or its hashed contents.
 *
 * The Idempotency-Key header is required (CSRF + bounded retry). An equal
 * replay with the same canonical request hash returns the original payload
 * (status + verification_id + ETag). A replay with a mismatched body is a
 * typed 409 `idempotency-conflict`.
 *
 * Safe problem responses:
 *   - 401 authentication-required
 *   - 403 access-denied
 *   - 400 invalid-correlation-id, invalid-request-body, invalid-idempotency-key, invalid-stream-key, invalid-pagination-or-range
 *   - 409 idempotency-conflict (key reused with different body) or audit-integrity-violation (verification succeeded but mismatch detected)
 *   - 503 audit-runtime-unavailable when a historical integrity key cannot be loaded
 */
final class VerifyAuditIntegrityController
{
    public function __construct(
        private readonly ResolvePrincipalContext $principals,
        private readonly DecideAccess $access,
        private readonly VerifyAuditIntegrityHandler $handler,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $correlationId = AuditApi::correlationId($request);

        $principal = $this->principals->resolve($request);
        if ($principal === null) {
            return AuditApi::problem(
                401,
                'authentication-required',
                'Unauthorized',
                'Authentication is required.',
                $correlationId,
            );
        }

        $scope = AuditApi::scope($principal);
        $decision = $this->decision($principal, $scope, $correlationId);
        if (! $decision->isAllowed()) {
            return AuditApi::problem(403, 'access-denied', 'Forbidden', 'Access denied.', $correlationId);
        }

        if ($correlationId === null) {
            return AuditApi::problem(
                400,
                'invalid-correlation-id',
                'Bad Request',
                'X-Correlation-ID must be a lowercase UUIDv7.',
            );
        }

        if ($request->query() !== []) {
            return AuditApi::problem(
                400,
                'invalid-query-parameter',
                'Bad Request',
                'The query string is not supported.',
                $correlationId,
            );
        }

        $idempotencyKey = $request->header('Idempotency-Key');
        if (! is_string($idempotencyKey) || trim($idempotencyKey) === '') {
            return AuditApi::problem(
                400,
                'invalid-idempotency-key',
                'Bad Request',
                'A non-empty Idempotency-Key header is required.',
                $correlationId,
            );
        }

        $payload = $this->parseBody($request);
        if ($payload === null) {
            return AuditApi::problem(
                400,
                'invalid-request-body',
                'Bad Request',
                'The request body must be a JSON object containing a valid stream_key.',
                $correlationId,
            );
        }

        $command = $this->buildCommand($principal, $scope, $correlationId, $payload);
        if ($command instanceof JsonResponse) {
            return $command;
        }

        try {
            $result = $this->handler->handle($command, $idempotencyKey);
        } catch (AuditIdempotencyMismatch) {
            return AuditApi::problem(
                409,
                'idempotency-conflict',
                'Conflict',
                'Idempotency-Key was already used for a different request.',
                $correlationId,
            );
        }
        catch (InvalidArgumentException $exception) {
            $message = $exception->getMessage();
            if ($message === 'audit_integrity_key_version_unavailable') {
                return AuditApi::problem(
                    503,
                    'audit-runtime-unavailable',
                    'Service Unavailable',
                    'Audit integrity verification is temporarily unavailable.',
                    $correlationId,
                );
            }
            if ($message === 'audit_stream_key_invalid') {
                return AuditApi::problem(
                    400,
                    'invalid-stream-key',
                    'Bad Request',
                    'stream_key is malformed.',
                    $correlationId,
                );
            }
            if ($message === 'audit_integrity_range_too_large') {
                return AuditApi::problem(
                    422,
                    'range-too-large',
                    'Unprocessable Entity',
                    'The requested verification range exceeds the maximum allowed events.',
                    $correlationId,
                );
            }
            if ($message === 'audit_integrity_chain_gap') {
                return AuditApi::problem(
                    409,
                    'audit-integrity-violation',
                    'Conflict',
                    'The audit chain reported a violation.',
                    $correlationId,
                );
            }
            if (in_array($message, [
                'audit_integrity_range_invalid',
                'audit_integrity_first_sequence_invalid',
                'audit_integrity_last_sequence_invalid',
                'audit_integrity_range_partial',
            ], true)) {
                return AuditApi::problem(
                    400,
                    'invalid-pagination-or-range',
                    'Bad Request',
                    'first_sequence and last_sequence must both be positive integers in order, and the inclusive range may not exceed 5000 events.',
                    $correlationId,
                );
            }

            throw $exception;
        }

        $status = $result['status'];
        if ($status === 409) {
            return AuditApi::problem(
                409,
                'audit-integrity-violation',
                'Conflict',
                'The audit chain reported a violation.',
                $correlationId,
            );
        }

        $body = [
            'data' => $result['result'],
        ];
        $response = AuditApi::response($body, $correlationId);
        $response->setStatusCode($status);
        $response->header('ETag', $result['etag']);
        if (! $result['replayed']) {
            $response->header(
                'Location',
                AuditApi::ROUTE_VERIFY_INTEGRITY.'/'.$result['result']['checkpoint_id'],
            );
        }

        return $response;
    }

    /**
     * @param  array{facility_id: string|null, organization_unit_ids: list<string>}  $scope
     */
    private function decision(
        PrincipalContext $principal,
        array $scope,
        ?string $correlationId,
    ): AccessDecision {
        return $this->access->decide(
            AuditApi::actor($principal, $scope, $correlationId),
            'audit.integrity.verify',
            new RecordFacts(
                ownerFacilityId: $scope['facility_id'],
                resourceType: 'audit_integrity_verification',
                classification: AuditEventInput::CLASSIFICATION_CONFIDENTIAL,
                organizationUnitId: count($scope['organization_unit_ids']) === 1
                    ? $scope['organization_unit_ids'][0]
                    : null,
                sharedUnitIds: $scope['organization_unit_ids'],
            ),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseBody(Request $request): ?array
    {
        $raw = (string) $request->getContent();
        if ($raw === '') {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array{facility_id: string|null, organization_unit_ids: list<string>}  $scope
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|JsonResponse
     */
    private function buildCommand(
        PrincipalContext $principal,
        array $scope,
        string $correlationId,
        array $payload,
    ): array|JsonResponse {
        $streamKey = $payload['stream_key'] ?? null;
        if (! is_string($streamKey) || $streamKey === '') {
            return AuditApi::problem(
                400,
                'invalid-stream-key',
                'Bad Request',
                'stream_key is required.',
                $correlationId,
            );
        }
        try {
            AuditEventInput::assertModuleToken(explode(':', $streamKey)[0], 'sourceModule');
        } catch (InvalidArgumentException) {
            return AuditApi::problem(
                400,
                'invalid-stream-key',
                'Bad Request',
                'stream_key is malformed.',
                $correlationId,
            );
        }
        if (strlen($streamKey) > 160) {
            return AuditApi::problem(
                400,
                'invalid-stream-key',
                'Bad Request',
                'stream_key exceeds the maximum length.',
                $correlationId,
            );
        }
        if (preg_match(
            '/\A[a-z][a-z0-9_-]*:[a-z][a-z0-9_-]*:(?:[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}|global)\z/',
            $streamKey,
        ) !== 1) {
            return AuditApi::problem(
                400,
                'invalid-stream-key',
                'Bad Request',
                'stream_key is malformed.',
                $correlationId,
            );
        }

        $first = $payload['first_sequence'] ?? null;
        $last = $payload['last_sequence'] ?? null;

        if ($first !== null && (! is_int($first) || $first < 1)) {
            return $this->invalidRange($correlationId);
        }
        if ($last !== null && (! is_int($last) || $last < 1)) {
            return $this->invalidRange($correlationId);
        }
        if (($first === null) !== ($last === null)) {
            return $this->invalidRange($correlationId);
        }
        if ($first !== null && $last !== null && $last < $first) {
            return $this->invalidRange($correlationId);
        }
        if ($first !== null && $last !== null && ($last - $first + 1) > 5000) {
            return $this->invalidRange($correlationId);
        }

        return [
            'principal_id' => $principal->userId,
            'facility_id' => $scope['facility_id'],
            'correlation_id' => $correlationId,
            'stream_key' => $streamKey,
            'first_sequence' => $first,
            'last_sequence' => $last,
            'occurred_at' => new DateTimeImmutable('now', new DateTimeZone('UTC')),
        ];
    }

    private function invalidRange(string $correlationId): JsonResponse
    {
        return AuditApi::problem(
            400,
            'invalid-pagination-or-range',
            'Bad Request',
            'first_sequence and last_sequence must both be positive integers in order, and the inclusive range may not exceed 5000 events.',
            $correlationId,
        );
    }
}

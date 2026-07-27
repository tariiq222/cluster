<?php

declare(strict_types=1);

namespace Modules\Audit\Features\CreateAuditExport\Http;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Modules\Audit\Features\CreateAuditExport\Handler\AuditIdempotencyMismatch;
use Modules\Audit\Features\CreateAuditExport\Handler\CreateAuditExportHandler;
use Modules\Audit\Http\AuditApi;
use Modules\Authorization\Contracts\AccessDecision;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Identity\Contracts\PrincipalContext;
use Modules\Identity\Contracts\ResolvePrincipalContext;

/**
 * POST /api/v1/audit/exports
 *
 * Authorizes via {@see DecideAccess} for `audit.event.export` *before*
 * reading body fields, validating the idempotency key, or running
 * query / filter validation. Every 4xx problem is `application/problem+json`
 * with `X-Correlation-ID` echoed. The successful response is 201 + ETag.
 *
 * The idempotency contract is enforced inside the handler, not the
 * controller: a single DB transaction atomically claims the
 * idempotency record, the descriptor, the Audit creation activity, and
 * the AuditExportCompletedV1 outbox event.
 */
final class CreateAuditExportController
{
    private const IDEMPOTENCY_KEY_PATTERN = '/\A[\x21-\x7E]{1,255}\z/';

    public function __construct(
        private readonly ResolvePrincipalContext $principals,
        private readonly DecideAccess $access,
        private readonly CreateAuditExportHandler $handler,
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
        if ($correlationId === null) {
            return AuditApi::problem(
                400,
                'invalid-correlation-id',
                'Bad Request',
                'X-Correlation-ID must be a lowercase UUIDv7.',
            );
        }

        $scope = AuditApi::scope($principal);

        // Authorization runs BEFORE any body / idempotency / filter
        // validation and BEFORE any descriptor lookup.
        $decision = $this->authorizeExport($principal, $scope, $correlationId);
        if (! $decision->isAllowed()) {
            return AuditApi::problem(403, 'access-denied', 'Forbidden', 'Access denied.', $correlationId);
        }

        $idempotencyKey = $request->header('Idempotency-Key');
        if (! is_string($idempotencyKey) || preg_match(self::IDEMPOTENCY_KEY_PATTERN, $idempotencyKey) !== 1) {
            return AuditApi::problem(
                400,
                'invalid-idempotency-key',
                'Bad Request',
                'Idempotency-Key is required.',
                $correlationId,
            );
        }

        $body = $request->json()->all();

        $format = $body['format'] ?? null;
        if (! is_string($format) || ! in_array($format, ['csv', 'ndjson'], true)) {
            return AuditApi::problem(
                422,
                'invalid-export-format',
                'Unprocessable Entity',
                'The export format must be one of: csv, ndjson.',
                $correlationId,
            );
        }

        $reason = $body['reason'] ?? null;
        if (! is_string($reason) || trim($reason) === '') {
            return AuditApi::problem(
                422,
                'invalid-export-reason',
                'Unprocessable Entity',
                'A non-empty reason is required.',
                $correlationId,
            );
        }
        if (mb_strlen($reason, 'UTF-8') > 500) {
            return AuditApi::problem(
                422,
                'invalid-export-reason',
                'Unprocessable Entity',
                'The reason must not exceed 500 characters.',
                $correlationId,
            );
        }

        $filters = $body['filters'] ?? [];
        if (! is_array($filters)) {
            return AuditApi::problem(
                422,
                'invalid-export-filters',
                'Unprocessable Entity',
                'The export filters must be an object.',
                $correlationId,
            );
        }

        try {
            $command = [
                'principal_id' => $principal->userId,
                'facility_id' => $scope['facility_id'],
                'organization_unit_ids' => $scope['organization_unit_ids'],
                'correlation_id' => $correlationId,
                'format' => $format,
                'filters' => $filters,
                'reason' => $reason,
                'occurred_at' => new DateTimeImmutable('now', new DateTimeZone('UTC')),
            ];
            $result = $this->handler->handle($command, $idempotencyKey);
        } catch (AuditIdempotencyMismatch) {
            return AuditApi::problem(
                409,
                'idempotency-conflict',
                'Conflict',
                'Idempotency-Key was already used for a different request.',
                $correlationId,
            );
        } catch (InvalidArgumentException $exception) {
            $type = $this->mapHandlerError($exception->getMessage());

            return AuditApi::problem(422, $type, 'Unprocessable Entity', 'The export request is invalid.', $correlationId);
        }

        return AuditApi::response(['data' => $result['descriptor']], $correlationId)
            ->setStatusCode($result['status'])
            ->header('ETag', $result['etag']);
    }

    /**
     * @param  array{facility_id: string|null, organization_unit_ids: list<string>}  $scope
     */
    private function authorizeExport(
        PrincipalContext $principal,
        array $scope,
        string $correlationId,
    ): AccessDecision {
        return $this->access->decide(
            AuditApi::actor($principal, $scope, $correlationId),
            'audit.event.export',
            new RecordFacts(
                ownerFacilityId: $scope['facility_id'],
                resourceType: 'audit_export',
                classification: 'internal',
                organizationUnitId: count($scope['organization_unit_ids']) === 1
                    ? $scope['organization_unit_ids'][0]
                    : null,
                sharedUnitIds: $scope['organization_unit_ids'],
            ),
        );
    }

    private function mapHandlerError(string $message): string
    {
        return match ($message) {
            'audit_export_format_invalid' => 'invalid-export-format',
            'audit_export_reason_required',
            'audit_export_reason_too_long',
            'audit_export_reason_invalid' => 'invalid-export-reason',
            'audit_export_filter_value_invalid',
            'audit_export_filter_range_invalid',
            'audit_export_snapshot_in_future',
            'audit_export_snapshot_out_of_window',
            'audit_export_event_count_invalid',
            'audit_export_event_count_too_large' => 'invalid-export-payload',
        };
    }
}

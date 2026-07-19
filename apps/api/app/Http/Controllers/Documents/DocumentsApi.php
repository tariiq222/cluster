<?php

namespace App\Http\Controllers\Documents;

use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Documents\Application\AuthorizedDocumentActor;
use Modules\Documents\Application\DocumentAuthorizationFacts;
use Modules\Documents\Application\RetryableStorageException;
use Modules\Documents\Infrastructure\Authorization\GrantedDocumentAuthorizationContext;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use RuntimeException;
use UnexpectedValueException;

final class DocumentsApi
{
    public static function correlationId(Request $request): ?string
    {
        $value = $request->header('X-Correlation-ID');

        return is_string($value) && self::isUuidV7($value) ? $value : null;
    }

    public static function idempotencyKey(Request $request): ?string
    {
        $value = $request->header('Idempotency-Key');

        return is_string($value) && preg_match('/\A[\x21-\x7E]{1,255}\z/', $value) === 1 ? $value : null;
    }

    /** @return array{user_id: string, facility_id: string}|JsonResponse */
    public static function principalOrProblem(
        Request $request,
        ResolveDevelopmentFixturePrincipal $principals,
        string $correlationId,
    ): array|JsonResponse {
        $principal = $principals->resolve($request);
        if ($principal === null) {
            return self::problem(401, 'authentication-required', 'Unauthorized', 'Authentication is required.', $correlationId);
        }

        return $principal;
    }

    /** @param array{user_id: string, facility_id: string} $principal */
    public static function actorOrProblem(
        array $principal,
        DecideAccess $access,
        DocumentAuthorizationFacts $facts,
        string $operation,
        string $correlationId,
    ): AuthorizedDocumentActor|JsonResponse {
        $decision = $access->decide($principal, $operation, new RecordFacts(
            ownerFacilityId: $facts->ownerOrganizationUnitId,
            resourceType: 'document',
            classification: $facts->classification,
            organizationUnitId: $facts->ownerOrganizationUnitId,
        ));
        if (! $decision->isAllowed()) {
            return self::problem(403, 'access-denied', 'Forbidden', 'Access denied.', $correlationId);
        }

        try {
            return AuthorizedDocumentActor::fromTrustedContext(
                GrantedDocumentAuthorizationContext::fromGrantedDecision($principal, $correlationId, $decision, $operation),
                $operation,
            );
        } catch (DomainException) {
            return self::problem(403, 'access-denied', 'Forbidden', 'Access denied.', $correlationId);
        }
    }

    /** @param array<string, mixed> $payload */
    public static function response(array $payload, int $status, string $correlationId): JsonResponse
    {
        return response()->json($payload, $status)->header('X-Correlation-ID', $correlationId);
    }

    public static function problem(int $status, string $type, string $title, string $detail, ?string $correlationId = null): JsonResponse
    {
        $responseCorrelationId = $correlationId ?? Str::uuid7()->toString();

        return response()->json([
            'type' => "https://cluster.example/problems/{$type}",
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
        ], $status)
            ->header('Content-Type', 'application/problem+json')
            ->header('X-Correlation-ID', $responseCorrelationId);
    }

    public static function domainProblem(DomainException $exception, string $correlationId): JsonResponse
    {
        return match ($exception->getMessage()) {
            'document_access_denied', 'linked_resource_access_denied', 'linked_resource_facts_unavailable' => self::problem(403, 'access-denied', 'Forbidden', 'Access denied.', $correlationId),
            'upload_intent_not_found', 'document_version_not_found', 'document_owner_organization_mismatch' => self::problem(404, 'document-upload-not-found', 'Not Found', 'The document upload is not available.', $correlationId),
            'idempotency_request_mismatch', 'idempotency_resource_mismatch' => self::problem(409, 'idempotency-conflict', 'Conflict', 'Idempotency-Key was already used for a different request.', $correlationId),
            'upload_intent_already_consumed', 'upload_completion_invalid_state', 'document_scan_invalid_state', 'document_promotion_invalid_state' => self::problem(409, 'document-upload-invalid-state', 'Conflict', 'The document upload is not in a state for this action.', $correlationId),
            'document_promotion_integrity_mismatch' => self::problem(409, 'document-promotion-integrity-mismatch', 'Conflict', 'The document promotion integrity check failed.', $correlationId),
            'quarantine_object_unavailable', 'document_promotion_unavailable' => self::problem(503, 'document-storage-unavailable', 'Service Unavailable', 'The document storage service is unavailable.', $correlationId),
            default => self::problem(409, 'document-operation-conflict', 'Conflict', 'The document operation cannot be completed.', $correlationId),
        };
    }

    public static function retryableStorageProblem(RetryableStorageException $exception, string $correlationId): JsonResponse
    {
        return self::problem(503, 'document-storage-unavailable', 'Service Unavailable', 'The document storage service is temporarily unavailable.', $correlationId);
    }

    public static function unavailableProblem(RuntimeException $exception, string $correlationId): JsonResponse
    {
        return self::problem(503, 'document-infrastructure-unavailable', 'Service Unavailable', 'The document service is temporarily unavailable.', $correlationId);
    }

    public static function stateProblem(UnexpectedValueException $exception, string $correlationId): JsonResponse
    {
        return self::problem(500, 'document-state-unavailable', 'Internal Server Error', 'The document operation cannot be safely completed.', $correlationId);
    }

    public static function isUuidV7(string $value): bool
    {
        return preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $value) === 1;
    }
}

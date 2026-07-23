<?php

namespace App\Integrations\PlatformSettings;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Authorization\Contracts\AccessDecision;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Organization\Contracts\ResolveOrganizationScopeAncestry;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;
use Throwable;

final class PlatformSettingsApi
{
    public function __construct(private readonly ResolveDevelopmentFixturePrincipal $principals, private readonly DecideAccess $access, private readonly ?ResolveOrganizationScopeAncestry $ancestry = null) {}

    /** @return array{principal: array{user_id: string, facility_id: string}, decision: AccessDecision, correlation_id: string}|JsonResponse */
    public function authorize(Request $request, string $capability, RecordFacts $facts): array|JsonResponse
    {
        $correlationId = $this->correlationId($request);
        if ($correlationId === null) {
            return $this->problem(400, 'invalid-correlation-id', 'Bad Request', 'X-Correlation-ID must be a lowercase UUIDv7.');
        }
        $principal = $this->principals->resolve($request);
        if ($principal === null) {
            return $this->problem(401, 'authentication-required', 'Unauthorized', 'Authentication is required.', $correlationId);
        }
        $facts = $this->withPrincipalScope($facts, $principal['facility_id']);
        $decision = $this->access->decide(['user_id' => $principal['user_id'], 'facility_id' => $principal['facility_id'], 'organization_unit_ids' => array_filter([$principal['facility_id']]), 'correlation_id' => $correlationId], $capability, $facts);
        if (! $decision->isAllowed()) {
            return $this->problem(403, 'access-denied', 'Forbidden', 'Access denied.', $correlationId);
        }

        return ['principal' => $principal, 'decision' => $decision, 'correlation_id' => $correlationId];
    }

    public function correlationId(Request $request): ?string
    {
        $value = $request->header('X-Correlation-ID');

        return is_string($value) && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $value) === 1 ? $value : null;
    }

    public function ifMatch(Request $request): ?int
    {
        $value = $request->header('If-Match');
        if (! is_string($value) || preg_match('/\A"([1-9][0-9]*)"\z/', $value, $matches) !== 1) {
            return null;
        }

return (int) $matches[1];
    }

    public function idempotencyKey(Request $request): ?string
    {
        $value = $request->header('Idempotency-Key');

        return is_string($value) && preg_match('/\A[\x21-\x7E]{1,255}\z/', $value) === 1 ? $value : null;
    }

    /** @param array<string, mixed> $body */
    public function response(array $body, int $status, string $correlationId, ?int $lockVersion = null): JsonResponse
    {
        $response = response()->json($body, $status)->header('X-Correlation-ID', $correlationId);
        if ($lockVersion !== null) {
            $response->header('ETag', '"'.$lockVersion.'"');
        }

return $response;
    }

    public function problem(int $status, string $type, string $title, string $detail, ?string $correlationId = null): JsonResponse
    {
        $response = response()->json(['type' => "https://cluster.example/problems/{$type}", 'title' => $title, 'status' => $status, 'detail' => $detail], $status)->header('Content-Type', 'application/problem+json');

        return $correlationId === null ? $response : $response->header('X-Correlation-ID', $correlationId);
    }

    public function exception(Throwable $exception, string $correlationId): JsonResponse
    {
        return match (true) {
            $exception instanceof NotFoundHttpException => $this->problem(404, 'resource-not-found', 'Not Found', $exception->getMessage(), $correlationId), $exception instanceof PreconditionFailedHttpException => $this->problem(412, 'precondition-failed', 'Precondition Failed', $exception->getMessage(), $correlationId), $exception instanceof ConflictHttpException => $this->problem(409, 'conflict', 'Conflict', $exception->getMessage(), $correlationId), $exception instanceof \InvalidArgumentException || $exception instanceof \DomainException => $this->problem(422, 'validation-failed', 'Unprocessable Content', $exception->getMessage(), $correlationId), default => $this->problem(503, 'service-unavailable', 'Service Unavailable', 'The requested platform source is currently unavailable.', $correlationId),
        };
    }

    public function facts(string $resourceType, ?string $recordId = null, ?string $ownerFacilityId = null, ?string $createdBy = null, ?string $scopeType = null, ?string $scopeId = null): RecordFacts
    {
        $scope = $scopeType !== null && $scopeId !== null ? $this->targetScope($scopeType, $scopeId) : [];

        return new RecordFacts(ownerFacilityId: $ownerFacilityId ?? ($scope['facility_id'] ?? null), resourceType: $resourceType, classification: 'internal', recordId: $recordId, sourceModule: 'PlatformSettings', createdByUserId: $createdBy, organizationUnitId: $scope['unit_id'] ?? null, clusterId: $scope['cluster_id'] ?? null);
    }

    /** @return array{cluster_id?: string, facility_id?: string, unit_id?: string} */
    private function targetScope(string $scopeType, string $scopeId): array
    {
        if ($scopeType === 'cluster') {
            return ['cluster_id' => $scopeId];
        } if ($scopeType !== 'facility' || $this->ancestry === null) {
            return [];
        }

return $this->ancestry->ancestry('facility', $scopeId) ?? [];
    }

    private function withPrincipalScope(RecordFacts $facts, string $facilityId): RecordFacts
    {
        if ($facts->clusterId !== null || $this->ancestry === null || $facilityId === '') {
            return $facts;
        } $scope = $this->ancestry->ancestry('facility', $facilityId);
        if ($scope === null || ! is_string($scope['cluster_id'] ?? null)) {
            return $facts;
        }

return new RecordFacts(ownerFacilityId: $facts->ownerFacilityId ?? ($scope['facility_id'] ?? null), resourceType: $facts->resourceType, classification: $facts->classification, factsVersion: $facts->factsVersion, organizationUnitId: $facts->organizationUnitId ?? ($scope['unit_id'] ?? null), recordId: $facts->recordId, sourceModule: $facts->sourceModule, clusterId: $scope['cluster_id'], createdByUserId: $facts->createdByUserId, ownerUserId: $facts->ownerUserId, responsibleUserId: $facts->responsibleUserId, sharedUnitIds: $facts->sharedUnitIds, sharedUserIds: $facts->sharedUserIds, participantIds: $facts->participantIds, lifecycleState: $facts->lifecycleState, workflowState: $facts->workflowState, fieldPolicyKey: $facts->fieldPolicyKey, workTypeVersionId: $facts->workTypeVersionId, legalHold: $facts->legalHold, lockVersion: $facts->lockVersion);
    }
}

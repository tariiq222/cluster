<?php

declare(strict_types=1);

namespace Modules\Audit\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Audit\Contracts\AuditActivityItem;
use Modules\Audit\Contracts\AuditEventInput;
use Modules\Authorization\Contracts\AccessDecision;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Identity\Contracts\PrincipalContext;
use Shared\Http\ProblemEnvelope;

/** Public HTTP contract and shared serialization for the Audit module. */
final class AuditApi
{
    public const ROUTE_PREFIX = '/api/v1/audit';

    public const ROUTE_LIST = '/api/v1/audit/events';

    public const ROUTE_GET = '/api/v1/audit/events/{eventId}';

    public const ROUTE_CREATE_EXPORT = '/api/v1/audit/exports';

    public const ROUTE_GET_EXPORT = '/api/v1/audit/exports/{exportId}';

    public const ROUTE_DOWNLOAD_EXPORT = '/api/v1/audit/exports/{exportId}/download';

    public const ROUTE_VERIFY_INTEGRITY = '/api/v1/audit/integrity-verifications';

    public static function correlationId(Request $request): ?string
    {
        $value = $request->header('X-Correlation-ID');
        if (! is_string($value)) {
            return null;
        }

        try {
            AuditEventInput::assertUuidV7($value, 'correlationId');
        } catch (\InvalidArgumentException) {
            return null;
        }

        return $value;
    }

    /**
     * Canonical facility fallback reuses PrincipalContext::defaultFacilityId:
     * `facilityIds[0] ?? primaryOrganizationUnitId`. Unit-only principals
     * (no facilities, only an organization unit) must match the same
     * owner-facility facts used by every other module. The selected
     * facility scope still wins when it is one of the principal's
     * facilities; the primaryOrganizationUnitId fallback applies only
     * when the principal has no facilities and no explicit facility
     * selection. This does NOT disclose any principal data beyond what
     * the trusting producer already sees in PrincipalContext.
     *
     * @return array{facility_id: string|null, organization_unit_ids: list<string>}
     */
    public static function scope(PrincipalContext $principal): array
    {
        $organizationUnitIds = array_values(array_unique($principal->organizationUnitIds));
        $selected = $principal->selectedScope;

        $facilityId = $principal->facilityIds[0] ?? $principal->primaryOrganizationUnitId;
        if (is_array($selected) && $selected['scope_type'] === 'facility' && in_array($selected['scope_id'], $principal->facilityIds, true)) {
            $facilityId = $selected['scope_id'];
        }
        if (is_array($selected) && in_array($selected['scope_type'], ['unit', 'organization_unit'], true) && in_array($selected['scope_id'], $organizationUnitIds, true)) {
            $organizationUnitIds = [$selected['scope_id']];
        }

        sort($organizationUnitIds, SORT_STRING);

        return [
            'facility_id' => $facilityId,
            'organization_unit_ids' => $organizationUnitIds,
        ];
    }

    /**
     * @param  array{facility_id: string|null, organization_unit_ids: list<string>}  $scope
     * @return array<string, mixed>
     */
    public static function actor(PrincipalContext $principal, array $scope, ?string $correlationId): array
    {
        $actor = [
            'user_id' => $principal->userId,
            'facility_id' => $scope['facility_id'],
            'organization_unit_ids' => $scope['organization_unit_ids'],
        ];
        if ($correlationId !== null) {
            $actor['correlation_id'] = $correlationId;
        }

        return $actor;
    }

    /**
     * @param  array{facility_id: string|null, organization_unit_ids: list<string>}  $scope
     */
    public static function collectionDecision(
        PrincipalContext $principal,
        array $scope,
        ?string $correlationId,
        DecideAccess $access,
    ): AccessDecision {
        return $access->decide(
            self::actor($principal, $scope, $correlationId),
            'audit.event.read',
            new RecordFacts(
                ownerFacilityId: $scope['facility_id'],
                resourceType: 'audit_event_collection',
                classification: AuditEventInput::CLASSIFICATION_PUBLIC,
                organizationUnitId: count($scope['organization_unit_ids']) === 1
                    ? $scope['organization_unit_ids'][0]
                    : null,
                sharedUnitIds: $scope['organization_unit_ids'],
            ),
        );
    }

    /** @param array<string, mixed> $payload */
    public static function response(array $payload, string $correlationId): JsonResponse
    {
        return response()->json($payload)->header('X-Correlation-ID', $correlationId);
    }

    public static function problem(
        int $status,
        string $type,
        string $title,
        string $detail,
        ?string $correlationId = null,
    ): JsonResponse {
        return ProblemEnvelope::make(
            $status,
            $type,
            $title,
            $correlationId,
            ['detail' => $detail],
        );
    }

    /** @return array<string, mixed> */
    public static function serialize(AuditActivityItem $item): array
    {
        return [
            'event_id' => $item->eventId,
            'source_module' => $item->sourceModule,
            'action' => $item->action,
            'event_type' => $item->eventType,
            'actor_type' => $item->actorType,
            'actor_id' => $item->actorId,
            'original_actor_id' => $item->originalActorId,
            'subject_type' => $item->subjectType,
            'subject_id' => $item->subjectId,
            'correlation_id' => $item->correlationId,
            'outcome' => $item->outcome,
            'classification' => $item->classification,
            'context' => $item->context,
            'occurred_at' => $item->occurredAt->format('Y-m-d\TH:i:s.v\Z'),
            'recorded_at' => $item->recordedAt->format('Y-m-d\TH:i:s.v\Z'),
            'access_decision_id' => $item->accessDecisionId,
            'retention_until' => $item->retentionUntil->format('Y-m-d\TH:i:s.v\Z'),
            'integrity_status' => $item->integrityStatus,
            'allowed_actions' => $item->allowedActions,
        ];
    }

    public static function notFound(?string $correlationId): JsonResponse
    {
        return self::problem(
            404,
            'audit-event-not-found',
            'Not Found',
            'The audit event was not found.',
            $correlationId,
        );
    }
}

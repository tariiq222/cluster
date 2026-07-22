<?php

namespace App\Http\Controllers\Documents;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Authorization\Contracts\AccessDecision;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use stdClass;

/**
 * Shared lookup + decision plumbing for the contracted document endpoints.
 * Facts are built from the Documents-owned row only; the central engine
 * decides, and denied resources never leak metadata.
 */
trait DocumentAccessSupport
{
    private function findDocument(string $publicId): ?stdClass
    {
        return DB::table('documents')->where('public_id', $publicId)->first();
    }

    private function decideOnDocument(
        array $principal,
        DecideAccess $access,
        stdClass $document,
        string $capability,
        string $correlationId,
    ): ?JsonResponse {
        return $this->documentDecision($principal, $access, $document, $capability, $correlationId)->isAllowed()
            ? null
            : DocumentsApi::problem(403, 'access-denied', 'Forbidden', 'Access denied.', $correlationId);
    }

    private function documentDecision(
        array $principal,
        DecideAccess $access,
        stdClass $document,
        string $capability,
        string $correlationId,
    ): AccessDecision {
        return $access->decide(
            [
                'user_id' => $principal['user_id'],
                'facility_id' => $principal['facility_id'] ?? null,
                'organization_unit_ids' => array_filter([$principal['facility_id'] ?? null]),
                'correlation_id' => $correlationId,
            ],
            $capability,
            new RecordFacts(
                ownerFacilityId: (string) $document->owner_organization_unit_id,
                resourceType: 'document',
                classification: (string) $document->classification,
                organizationUnitId: (string) $document->owner_organization_unit_id,
                recordId: (string) $document->id,
                lifecycleState: (string) $document->status,
                legalHold: (bool) $document->legal_hold,
                lockVersion: (int) $document->lock_version,
            ),
        );
    }

    /** @return array<string, mixed> */
    private function serializeDocument(stdClass $document, ?array $allowedActions = null): array
    {
        $resource = [
            'id' => $document->public_id,
            'title' => $document->name,
            'description' => $document->description,
            'classification' => $document->classification,
            'status' => $document->status,
            'owner_organization_unit_id' => $document->owner_organization_unit_id,
            'legal_hold' => (bool) $document->legal_hold,
            'legal_hold_reason' => $document->legal_hold_reason,
            'restriction_policy_key' => $document->retention_policy_key,
            'current_version_id' => $document->current_version_id,
            'lock_version' => (int) $document->lock_version,
            'created_at' => $document->created_at,
            'updated_at' => $document->updated_at,
        ];

        if ($allowedActions !== null) {
            $resource['allowed_actions'] = array_values(array_unique($allowedActions));
        }

        return $resource;
    }

    /** @return list<string> */
    private function allowedActionsForDocument(array $principal, stdClass $document, string $correlationId): array
    {
        if (! $this->documentDecision($principal, $this->access, $document, 'documents.read', $correlationId)->isAllowed()) {
            return [];
        }

        $actions = ['read'];
        $decisionCapabilities = [
            'update' => 'documents.update',
            'initiate-upload' => 'documents.initiate-upload',
        ];
        $currentVersionAvailable = $document->current_version_id !== null
            && DB::table('document_versions')->where('id', $document->current_version_id)->where('availability_status', 'available')->exists();
        if ($currentVersionAvailable) {
            $decisionCapabilities += [
                'link' => 'documents.link',
                'download' => 'documents.download',
                'grant' => 'documents.grant',
            ];
        }
        if ($document->status !== 'archived') {
            $decisionCapabilities['archive'] = 'documents.archive';
        }
        if (! (bool) $document->legal_hold) {
            $decisionCapabilities['place-hold'] = 'documents.hold';
        } else {
            $decisionCapabilities['release-hold'] = 'documents.hold';
        }
        foreach ($decisionCapabilities as $action => $capability) {
            if ($this->documentDecision($principal, $this->access, $document, $capability, $correlationId)->isAllowed()) {
                $actions[] = $action;
                if ($action === 'initiate-upload') {
                    $actions[] = 'add-version';
                }
            }
        }

        return $actions;
    }

    private function recordAccessEvent(stdClass $document, string $actorUserId, string $action, string $decision, string $reasonCode): void
    {
        DB::table('document_access_events')->insert([
            'id' => Str::uuid7()->toString(),
            'document_id' => $document->id,
            'document_version_id' => null,
            'actor_user_id' => $actorUserId,
            'acting_organization_unit_id' => $document->owner_organization_unit_id,
            'action' => $action,
            'decision' => $decision,
            'decision_reason_code' => $reasonCode,
            'source_context' => null,
            'ip_address' => null,
            'user_agent_hash' => null,
            'occurred_at' => now(),
            'event_id' => Str::uuid7()->toString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

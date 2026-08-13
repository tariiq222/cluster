<?php

namespace Modules\Documents\Application;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Authorization\Contracts\AccessDecision;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Documents\Contracts\DocumentDownloadGrantIssuer;
use Modules\Documents\Contracts\DocumentSourceReference;
use Modules\Documents\Contracts\LinkedResourceAuthorizationFacts;
use Modules\Documents\Contracts\SensitiveAccessEventRecorder;
use Modules\Documents\Domain\DocumentScanStatus;
use Modules\Documents\Domain\DocumentVersionAvailabilityStatus;
use Modules\Documents\Domain\UuidV7;
use stdClass;

/** Re-authorizes the document and every active linked source on each download. */
final class DocumentDownloadService
{
    public function __construct(
        private readonly DecideAccess $access,
        private readonly LinkedResourceAuthorizationFacts $resourceFacts,
        private readonly DocumentAuthorizationRecordFactsBuilder $documentFacts,
        private readonly DocumentDownloadGrantIssuer $grantIssuer,
        private readonly SensitiveAccessEventRecorder $sensitiveAccess,
    ) {}

    public function download(
        string $documentId,
        string $versionId,
        DocumentAccessRequest $request,
    ): DocumentDownloadGrant {
        UuidV7::assert($documentId, 'Document id');
        UuidV7::assert($versionId, 'Document version id');
        $row = DB::table('documents as d')
            ->join('document_versions as v', 'v.document_id', '=', 'd.id')
            ->where('d.public_id', $documentId)
            ->where('v.public_id', $versionId)
            ->select([
                'd.id as document_id', 'd.public_id as document_public_id', 'd.owner_organization_unit_id', 'd.created_by_user_id', 'd.classification', 'd.status', 'd.legal_hold', 'd.lock_version',
                'v.id as version_id', 'v.public_id as version_public_id', 'v.scan_status', 'v.availability_status',
            ])->first();
        if (! $row instanceof stdClass) {
            throw new DomainException('document_version_not_found');
        }
        if ($row->scan_status !== DocumentScanStatus::Clean->value
            || $row->availability_status !== DocumentVersionAvailabilityStatus::Available->value) {
            throw new DomainException('document_not_available');
        }

        $actor = ['user_id' => $request->principalId, 'facility_id' => $request->facilityId];
        $documentDecision = $this->access->decide(
            $actor,
            'documents.download',
            $this->documentFacts->forDocument((object) [
                'id' => $row->document_id,
                'owner_organization_unit_id' => $row->owner_organization_unit_id,
                'created_by_user_id' => $row->created_by_user_id,
                'classification' => $row->classification,
                'status' => $row->status,
                'legal_hold' => $row->legal_hold,
                'lock_version' => $row->lock_version,
            ]),
        );
        if (! $documentDecision->isAllowed()) {
            $this->recordAccess($row, $request, $documentDecision, 'denied');
            throw new DomainException('document_access_denied');
        }

        $links = DB::table('document_links')
            ->where('document_id', $row->document_id)
            ->where('status', 'active')
            ->get();
        foreach ($links as $link) {
            $facts = $this->resourceFacts->resolve(new DocumentSourceReference(
                (string) $link->source_module,
                (string) $link->source_type,
                (string) $link->source_id,
            ));
            if ($facts === null) {
                $this->recordAccess($row, $request, $documentDecision, 'denied');
                throw new DomainException('linked_resource_facts_unavailable');
            }
            $decision = $this->access->decide($actor, $this->resourceCapability((string) $link->source_type), $facts);
            if (! $decision->isAllowed()) {
                $this->recordAccess($row, $request, $decision, 'denied');
                throw new DomainException('linked_resource_access_denied');
            }
        }

        $this->recordAccess($row, $request, $documentDecision, 'allowed');
        $this->sensitiveAccess->recordDownload(
            (string) $row->document_public_id,
            (string) $row->version_public_id,
            (string) $row->classification,
            $request,
            $documentDecision,
        );

        return $this->grantIssuer->issue(
            (string) $row->document_public_id,
            (string) $row->version_public_id,
            $request->principalId,
        );
    }

    private function recordAccess(stdClass $row, DocumentAccessRequest $request, AccessDecision $decision, string $outcome): void
    {
        if (! Schema::hasTable('document_access_events')) {
            return;
        }
        DB::table('document_access_events')->insert([
            'id' => UuidV7::generate(),
            'document_id' => $row->document_id,
            'document_version_id' => $row->version_id,
            'actor_user_id' => $request->principalId,
            'acting_organization_unit_id' => $request->facilityId,
            'action' => 'download',
            'decision' => $outcome,
            'decision_reason_code' => $decision->reasonCodes[0] ?? 'unknown',
            'source_context' => json_encode(['correlation_id' => $request->correlationId], JSON_THROW_ON_ERROR),
            'ip_address' => $request->sourceIp,
            'user_agent_hash' => $request->deviceFingerprintHash,
            'occurred_at' => now('UTC'),
            'event_id' => UuidV7::generate(),
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
    }

    private function resourceCapability(string $sourceType): string
    {
        return $sourceType === 'task' ? 'tasks.read' : 'documents.download';
    }
}

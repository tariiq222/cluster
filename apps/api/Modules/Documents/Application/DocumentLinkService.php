<?php

namespace Modules\Documents\Application;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Documents\Contracts\DocumentSourceReference;
use Modules\Documents\Contracts\LinkDocument;
use Modules\Documents\Contracts\LinkedResourceAuthorizationFacts;
use Modules\Documents\Domain\DocumentVersionAvailabilityStatus;
use Modules\Documents\Domain\UuidV7;
use stdClass;

/** Coordinates a link without taking ownership of the source record. */
final class DocumentLinkService implements LinkDocument
{
    public function __construct(
        private readonly DecideAccess $access,
        private readonly LinkedResourceAuthorizationFacts $resourceFacts,
        private readonly DocumentAuthorizationRecordFactsBuilder $documentFacts,
    ) {}

    public function link(
        string $documentId,
        DocumentSourceReference $reference,
        string $relationType,
        string $principalId,
        string $facilityId,
        ?string $constraintPolicyKey = null,
    ): string {
        UuidV7::assert($documentId, 'Document id');
        UuidV7::assert($principalId, 'Document link principal id');
        $document = DB::table('documents')->where('public_id', $documentId)->first();
        if (! $document instanceof stdClass) {
            throw new DomainException('document_not_found');
        }
        $version = DB::table('document_versions')
            ->where('document_id', $document->id)
            ->where('availability_status', DocumentVersionAvailabilityStatus::Available->value)
            ->first();
        if (! $version instanceof stdClass) {
            throw new DomainException('document_not_available_for_link');
        }
        $this->assertAllowed(
            ['user_id' => $principalId, 'facility_id' => $facilityId],
            'documents.link',
            $this->documentFacts->forDocument($document),
        );
        $facts = $this->resourceFacts->resolve($reference);
        if ($facts === null) {
            throw new DomainException('linked_resource_facts_unavailable');
        }
        $this->assertAllowed(
            ['user_id' => $principalId, 'facility_id' => $facilityId],
            $this->resourceCapability($reference),
            $facts,
        );

        $normalizedConstraintPolicyKey = $constraintPolicyKey !== null ? trim($constraintPolicyKey) : null;
        $existing = DB::table('document_links')
            ->where('document_id', $document->id)
            ->where('source_module', $reference->sourceModule)
            ->where('source_type', $reference->sourceType)
            ->where('source_id', $reference->sourceId)
            ->where('relation_type', $relationType)
            ->where('status', 'active')
            ->first();
        if ($existing instanceof stdClass) {
            if (($existing->constraint_policy_key ?? null) !== $normalizedConstraintPolicyKey) {
                throw new DomainException('document_link_conflict');
            }

            return (string) $existing->id;
        }

        $now = now('UTC');
        $linkId = UuidV7::generate();
        $inserted = DB::table('document_links')->insertOrIgnore([
            'id' => $linkId,
            'document_id' => $document->id,
            'source_module' => $reference->sourceModule,
            'source_type' => $reference->sourceType,
            'source_id' => $reference->sourceId,
            'relation_type' => $relationType,
            'constraint_policy_key' => $normalizedConstraintPolicyKey,
            'link_classification' => $facts->classification,
            'linked_by_user_id' => $principalId,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($inserted !== 1) {
            $persisted = DB::table('document_links')
                ->where('document_id', $document->id)
                ->where('source_module', $reference->sourceModule)
                ->where('source_type', $reference->sourceType)
                ->where('source_id', $reference->sourceId)
                ->where('relation_type', $relationType)
                ->where('status', 'active')
                ->first();
            if (! $persisted instanceof stdClass || ($persisted->constraint_policy_key ?? null) !== $normalizedConstraintPolicyKey) {
                throw new DomainException('document_link_conflict');
            }

            return (string) $persisted->id;
        }

        return $linkId;
    }

    private function assertAllowed(array $actor, string $capability, RecordFacts $facts): void
    {
        if (! $this->access->decide($actor, $capability, $facts)->isAllowed()) {
            throw new DomainException('document_access_denied');
        }
    }

    private function resourceCapability(DocumentSourceReference $reference): string
    {
        return $reference->sourceType === 'task' ? 'tasks.read' : 'documents.link';
    }
}

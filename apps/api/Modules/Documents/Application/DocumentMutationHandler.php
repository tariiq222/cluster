<?php

declare(strict_types=1);

namespace Modules\Documents\Application;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Audit\Contracts\AuditEventInput;
use Modules\Audit\Contracts\RecordAuditEvent;
use Modules\Documents\Contracts\DocumentSourceReference;
use Modules\Documents\Domain\UuidV7;
use Shared\Contracts\TransactionalOutbox;
use stdClass;

final class DocumentMutationHandler
{
    public function __construct(
        private readonly DocumentLinkService $links,
        private readonly TransactionalOutbox $outbox,
        private readonly RecordAuditEvent $audit,
    ) {}

    /** @param array<string, mixed> $payload */
    public function recordGrant(
        stdClass $document,
        stdClass $version,
        string $principalId,
        string $grantType,
        string $operation,
        string $keyHash,
        string $requestHash,
        array $payload,
        string $correlationId,
    ): void {
        DB::transaction(function () use ($document, $version, $principalId, $grantType, $operation, $keyHash, $requestHash, $payload, $correlationId): void {
            $now = now();
            DB::table('document_idempotency_keys')->insert([
                'id' => Str::uuid7()->toString(),
                'principal_id' => $principalId,
                'operation' => $operation,
                'idempotency_key_hash' => $keyHash,
                'request_hash' => $requestHash,
                'resource_type' => 'document',
                'resource_id' => $document->id,
                'response_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->recordAccess($document, $principalId, $grantType.'-grant', 'document_grant_issued', $now);
            $this->outbox->append(UuidV7::generate(), (string) $document->id, 'com.cluster.documents.grantissued.v1', [
                'document_id' => $document->public_id,
                'version_id' => $version->public_id,
                'grant_type' => $grantType,
                'correlation_id' => $correlationId,
                'actor_user_id' => $principalId,
            ]);
            $this->audit->record(new AuditEventInput(
                eventId: Str::uuid7()->toString(),
                sourceModule: 'documents',
                action: 'documents.grant.issued',
                eventType: 'com.cluster.documents.grantissued.v1',
                actorType: AuditEventInput::ACTOR_USER,
                actorId: $principalId,
                originalActorId: null,
                subjectType: 'document',
                subjectId: (string) $document->public_id,
                correlationId: $correlationId,
                outcome: AuditEventInput::OUTCOME_SUCCEEDED,
                classification: (string) $document->classification,
                context: [
                    'grant_type' => $grantType,
                    'version_id' => (string) $version->public_id,
                    'organization_unit_id' => (string) $document->owner_organization_unit_id,
                ],
                occurredAt: new DateTimeImmutable($now->format('Y-m-d H:i:s.u'), new DateTimeZone('UTC')),
                retentionClass: AuditEventInput::RETENTION_REGULATED,
            ));
        });
    }

    /**
     * @param  array{source_module:string,record_type:string,record_id:string}  $source
     * @return array<string, mixed>
     */
    public function link(
        stdClass $document,
        int $expectedVersion,
        array $principal,
        array $source,
        string $relationType,
        ?string $constraintPolicyKey,
        string $requestHash,
        string $keyHash,
        string $operation,
        string $correlationId,
    ): array {
        $now = now();

        return DB::transaction(function () use ($document, $expectedVersion, $principal, $source, $relationType, $constraintPolicyKey, $requestHash, $keyHash, $operation, $correlationId, $now): array {
            $locked = DB::table('documents')->where('id', $document->id)->lockForUpdate()->first();
            if ($locked === null || (int) $locked->lock_version !== $expectedVersion) {
                throw new DomainException('precondition_failed');
            }
            $linkId = $this->links->link(
                (string) $document->public_id,
                new DocumentSourceReference($source['source_module'], $source['record_type'], $source['record_id']),
                $relationType,
                $principal['user_id'],
                $principal['facility_id'],
                $constraintPolicyKey,
            );
            DB::table('documents')->where('id', $document->id)->update(['lock_version' => $expectedVersion + 1, 'updated_at' => $now]);
            $resource = [
                'id' => $linkId,
                'resource_type' => 'document_link',
                'document_id' => $document->public_id,
                'status' => 'active',
                'source' => $source,
                'relation_type' => $relationType,
                'constraint_policy_key' => $constraintPolicyKey,
                'lock_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            DB::table('document_idempotency_keys')->insert([
                'id' => Str::uuid7()->toString(),
                'principal_id' => $principal['user_id'],
                'operation' => $operation,
                'idempotency_key_hash' => $keyHash,
                'request_hash' => $requestHash,
                'resource_type' => 'document_link',
                'resource_id' => $linkId,
                'response_payload' => json_encode($resource, JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->outbox->append(UuidV7::generate(), (string) $document->id, 'com.cluster.documents.linked.v1', [
                'document_id' => $document->public_id,
                'link_id' => $linkId,
                'relation_type' => $relationType,
                'constraint_policy_key' => $constraintPolicyKey,
                'correlation_id' => $correlationId,
            ]);
            $this->recordAccess($document, $principal['user_id'], 'link', 'document_link_created', $now);

            return $resource;
        });
    }

    /** @param array<string, mixed> $changes @param array<string, mixed> $validated */
    public function update(stdClass $document, int $expectedVersion, array $changes, array $validated, array $principal, string $correlationId): stdClass
    {
        $now = now();

        return DB::transaction(function () use ($document, $expectedVersion, $changes, $validated, $principal, $correlationId, $now): stdClass {
            $updated = DB::table('documents')->where('id', $document->id)->where('lock_version', $expectedVersion)
                ->update([...$changes, 'lock_version' => $expectedVersion + 1, 'updated_at' => $now]);
            if ($updated !== 1) {
                throw new DomainException('precondition_failed');
            }
            $this->outbox->append(UuidV7::generate(), (string) $document->id, 'com.cluster.documents.metadataupdated.v1', [
                'document_id' => $document->public_id,
                'changed_fields' => array_keys($changes),
                'classification_change_reason' => $validated['classification_change_reason'] ?? null,
                'correlation_id' => $correlationId,
                'actor_user_id' => $principal['user_id'],
            ]);
            $this->recordAccess($document, $principal['user_id'], 'metadata_update', 'document_metadata_updated', $now);

            return DB::table('documents')->where('id', $document->id)->first();
        });
    }

    /** @param array<string, mixed> $changes */
    public function transition(
        stdClass $document,
        array $principal,
        string $documentAction,
        array $changes,
        string $operation,
        string $keyHash,
        string $requestHash,
        string $correlationId,
    ): stdClass {
        $now = now();

        return DB::transaction(function () use ($document, $principal, $documentAction, $changes, $operation, $keyHash, $requestHash, $correlationId, $now): stdClass {
            $updated = DB::table('documents')->where('id', $document->id)->where('lock_version', (int) $document->lock_version)
                ->update([...$changes, 'lock_version' => (int) $document->lock_version + 1, 'updated_at' => $now]);
            if ($updated !== 1) {
                throw new DomainException('precondition_failed');
            }
            $fresh = DB::table('documents')->where('id', $document->id)->first();
            DB::table('document_idempotency_keys')->insert([
                'id' => Str::uuid7()->toString(),
                'principal_id' => $principal['user_id'],
                'operation' => $operation,
                'idempotency_key_hash' => $keyHash,
                'request_hash' => $requestHash,
                'resource_type' => 'document',
                'resource_id' => $document->id,
                'response_payload' => json_encode((array) $fresh, JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->recordAccess($document, $principal['user_id'], $documentAction, 'document_transition_allowed', $now);
            $this->outbox->append(UuidV7::generate(), (string) $document->id, 'com.cluster.documents.lifecycletransitioned.v1', [
                'document_id' => $document->public_id,
                'action' => $documentAction,
                'lock_version' => (int) $document->lock_version + 1,
                'correlation_id' => $correlationId,
                'actor_user_id' => $principal['user_id'],
            ]);

            return $fresh;
        });
    }

    public function create(
        string $id,
        string $publicId,
        string $title,
        ?string $description,
        string $classification,
        string $ownerUnitId,
        string $restrictionPolicyKey,
        string $principalId,
        string $keyHash,
        string $requestHash,
        string $correlationId,
    ): stdClass {
        $now = now();

        return DB::transaction(function () use ($id, $publicId, $title, $description, $classification, $ownerUnitId, $restrictionPolicyKey, $principalId, $keyHash, $requestHash, $correlationId, $now): stdClass {
            DB::table('documents')->insert([
                'id' => $id,
                'public_id' => $publicId,
                'owner_organization_unit_id' => $ownerUnitId,
                'created_by_user_id' => $principalId,
                'name' => trim($title),
                'description' => $description,
                'classification' => $classification,
                'status' => 'active',
                'current_version_id' => null,
                'retention_until' => null,
                'retention_policy_key' => null,
                'restriction_policy_key' => trim($restrictionPolicyKey),
                'legal_hold' => false,
                'lock_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $document = DB::table('documents')->where('id', $id)->first();
            DB::table('document_idempotency_keys')->insert([
                'id' => Str::uuid7()->toString(),
                'principal_id' => $principalId,
                'operation' => 'documents.create',
                'idempotency_key_hash' => $keyHash,
                'request_hash' => $requestHash,
                'resource_type' => 'document',
                'resource_id' => $id,
                'response_payload' => json_encode((array) $document, JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->recordAccess($document, $principalId, 'create', 'document_created', $now);
            $this->outbox->append(UuidV7::generate(), $id, 'com.cluster.documents.created.v1', [
                'document_id' => $publicId,
                'classification' => $classification,
                'owner_organization_unit_id' => $ownerUnitId,
                'correlation_id' => $correlationId,
                'actor_user_id' => $principalId,
            ]);

            return $document;
        });
    }

    private function recordAccess(stdClass $document, string $actorUserId, string $action, string $reasonCode, mixed $now): void
    {
        DB::table('document_access_events')->insert([
            'id' => Str::uuid7()->toString(),
            'document_id' => $document->id,
            'document_version_id' => null,
            'actor_user_id' => $actorUserId,
            'acting_organization_unit_id' => $document->owner_organization_unit_id,
            'action' => $action,
            'decision' => 'allow',
            'decision_reason_code' => $reasonCode,
            'source_context' => null,
            'ip_address' => null,
            'user_agent_hash' => null,
            'occurred_at' => $now,
            'event_id' => Str::uuid7()->toString(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}

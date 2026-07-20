<?php

namespace Modules\Authorization\Infrastructure\Persistence;

use Illuminate\Support\Facades\DB;
use Modules\Authorization\Contracts\AccessDecision;
use Modules\Authorization\Contracts\PersistAccessDecision;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Authorization\Domain\ClassificationLevel;
use Modules\Authorization\Domain\UuidV7;
use Throwable;

final class DatabasePersistAccessDecision implements PersistAccessDecision
{
    public function persist(AccessDecision $decision, ?RecordFacts $facts, array $actor): bool
    {
        $userId = $actor['user_id'] ?? null;
        if (! is_string($userId) || trim($userId) === '' || $decision->decisionId === null) {
            return false;
        }

        $now = now()->utc();
        $correlationId = $actor['correlation_id'] ?? null;
        $correlationId = is_string($correlationId) && trim($correlationId) !== '' ? $correlationId : UuidV7::generate();
        $sensitive = $decision->isAllowed()
            && $facts !== null
            && is_string($facts->recordId)
            && trim($facts->recordId) !== ''
            && (ClassificationLevel::tryFrom($facts->classification)?->requiresSensitiveAccessAudit() ?? false);

        try {
            DB::transaction(function () use ($decision, $facts, $actor, $userId, $correlationId, $now, $sensitive): void {
                DB::table('access_decisions')->insert([
                    'id' => $decision->decisionId,
                    'decision' => $decision->decision,
                    'action' => $decision->action,
                    'resource_type' => $decision->resourceType,
                    'resource_id' => $facts?->recordId,
                    'reason_codes' => json_encode(array_values(array_unique($decision->reasonCodes)), JSON_THROW_ON_ERROR),
                    'policy_version' => $decision->policyVersion,
                    'facts_version' => $decision->factsVersion,
                    'authorization_trace_id' => UuidV7::generate(),
                    'evaluated_at' => $now,
                    'correlation_id' => $correlationId,
                    'classification' => $decision->classification,
                    'access_context' => json_encode($this->sanitizedContext($userId, $actor), JSON_THROW_ON_ERROR),
                    'actor_user_id' => $userId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                if ($sensitive && $facts !== null && is_string($facts->recordId)) {
                    $originalUserId = $actor['original_user_id'] ?? null;
                    DB::table('sensitive_access_events')->insert([
                        'id' => UuidV7::generate(),
                        'access_decision_id' => $decision->decisionId,
                        'actor_user_id' => $userId,
                        'original_actor_user_id' => is_string($originalUserId) && trim($originalUserId) !== '' ? $originalUserId : $userId,
                        'resource_type' => $facts->resourceType,
                        'resource_id' => $facts->recordId,
                        'action' => $decision->action,
                        'classification_code' => $facts->classification,
                        'correlation_id' => $correlationId,
                        'idempotency_key_hash' => hash('sha256', $decision->decisionId),
                        'occurred_at' => $now,
                        'recorded_at' => $now,
                    ]);
                }
            });
        } catch (Throwable $exception) {
            logger()->error('authorization.access_decision_persist_failed', [
                'decision_id' => $decision->decisionId,
                'action' => $decision->action,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }

        return true;
    }

    /** @return array<string, string|list<string>> */
    private function sanitizedContext(string $userId, array $actor): array
    {
        $context = ['user_id' => $userId];
        if (is_string($actor['facility_id'] ?? null) && trim($actor['facility_id']) !== '') {
            $context['facility_id'] = $actor['facility_id'];
        }
        if (is_array($actor['organization_unit_ids'] ?? null)) {
            $context['organization_unit_ids'] = array_values(array_filter(
                $actor['organization_unit_ids'],
                static fn (mixed $id): bool => is_string($id) && trim($id) !== '',
            ));
        }

        return $context;
    }
}

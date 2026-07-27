<?php

declare(strict_types=1);

namespace Modules\Audit\Contracts;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class AuditActivityItem
{
    public const INTEGRITY_VERIFIED = 'verified';

    public const INTEGRITY_VIOLATED = 'violated';

    public const INTEGRITY_UNVERIFIED = 'unverified';

    /**
     * @param  array<array-key, mixed>  $context  redacted view, never raw
     * @param  list<string>  $allowedActions
     */
    public function __construct(
        public string $eventId,
        public string $sourceModule,
        public string $action,
        public string $eventType,
        public string $actorType,
        public ?string $actorId,
        public ?string $originalActorId,
        public string $subjectType,
        public ?string $subjectId,
        public string $correlationId,
        public string $outcome,
        public string $classification,
        public array $context,
        public DateTimeImmutable $occurredAt,
        public DateTimeImmutable $recordedAt,
        public ?string $accessDecisionId,
        public DateTimeImmutable $retentionUntil,
        public string $integrityStatus,
        public array $allowedActions,
    ) {
        AuditEventInput::assertUuidV7($eventId, 'eventId');
        AuditEventInput::assertModuleToken($sourceModule, 'sourceModule');
        AuditEventInput::assertCatalogToken($action, 128, 'action');
        AuditEventInput::assertEventType($eventType);

        if (! in_array($actorType, AuditEventInput::ALLOWED_ACTOR_TYPES, true)) {
            throw new InvalidArgumentException('audit_actor_type_invalid');
        }
        AuditEventInput::assertNullableUuidV7($actorId, 'actorId');
        AuditEventInput::assertNullableUuidV7($originalActorId, 'originalActorId');
        AuditEventInput::assertModuleToken($subjectType, 'subjectType');
        AuditEventInput::assertNullableUuidV7($subjectId, 'subjectId');
        AuditEventInput::assertUuidV7($correlationId, 'correlationId');

        if (! in_array($outcome, AuditEventInput::ALLOWED_OUTCOMES, true)) {
            throw new InvalidArgumentException('audit_outcome_invalid');
        }
        if (! in_array($classification, AuditEventInput::ALLOWED_CLASSIFICATIONS, true)) {
            throw new InvalidArgumentException('audit_classification_invalid');
        }

        AuditEventInput::assertContext($context);
        AuditEventInput::assertUtcMilliseconds($occurredAt, 'occurredAt');
        AuditEventInput::assertUtcMilliseconds($recordedAt, 'recordedAt');
        AuditEventInput::assertNullableUuidV7($accessDecisionId, 'accessDecisionId');
        AuditEventInput::assertUtcMilliseconds($retentionUntil, 'retentionUntil');

        if ($recordedAt < $occurredAt || $retentionUntil <= $recordedAt) {
            throw new InvalidArgumentException('audit_activity_timestamp_order_invalid');
        }
        if (! in_array($integrityStatus, [
            self::INTEGRITY_VERIFIED,
            self::INTEGRITY_VIOLATED,
            self::INTEGRITY_UNVERIFIED,
        ], true)) {
            throw new InvalidArgumentException('audit_integrity_status_invalid');
        }
        self::assertAllowedActions($allowedActions);
    }

    /** @param array<array-key, mixed> $allowedActions */
    private static function assertAllowedActions(array $allowedActions): void
    {
        if (! array_is_list($allowedActions)) {
            throw new InvalidArgumentException('audit_allowed_actions_invalid');
        }

        $seen = [];
        foreach ($allowedActions as $allowedAction) {
            if (! is_string($allowedAction)) {
                throw new InvalidArgumentException('audit_allowed_actions_invalid');
            }
            AuditEventInput::assertCatalogToken($allowedAction, 96, 'allowedAction');
            if (isset($seen[$allowedAction])) {
                throw new InvalidArgumentException('audit_allowed_actions_duplicate');
            }
            $seen[$allowedAction] = true;
        }
    }
}

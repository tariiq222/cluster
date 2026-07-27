<?php

declare(strict_types=1);

namespace Modules\Audit\Contracts;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class AuditActivityQuery
{
    public const MAX_LIMIT = 100;

    /**
     * @param  list<string>  $organizationUnitIds
     */
    public function __construct(
        public string $principalId,
        public ?string $facilityId,
        public array $organizationUnitIds,
        public ?string $cursor,
        public ?string $sourceModule,
        public ?string $action,
        public ?string $actorId,
        public ?string $subjectType,
        public ?string $subjectId,
        public ?string $correlationId,
        public ?string $classification,
        public ?DateTimeImmutable $occurredFrom,
        public ?DateTimeImmutable $occurredTo,
        public int $limit,
    ) {
        AuditEventInput::assertUuidV7($principalId, 'principalId');
        AuditEventInput::assertNullableUuidV7($facilityId, 'facilityId');
        self::assertOrganizationUnitIds($organizationUnitIds);

        if ($cursor !== null && ($cursor === '' || strlen($cursor) > 4096)) {
            throw new InvalidArgumentException('audit_cursor_invalid');
        }
        if ($sourceModule !== null) {
            AuditEventInput::assertModuleToken($sourceModule, 'sourceModule');
        }
        if ($action !== null) {
            AuditEventInput::assertCatalogToken($action, 128, 'action');
        }
        AuditEventInput::assertNullableUuidV7($actorId, 'actorId');
        if ($subjectType !== null) {
            AuditEventInput::assertModuleToken($subjectType, 'subjectType');
        }
        AuditEventInput::assertNullableUuidV7($subjectId, 'subjectId');
        AuditEventInput::assertNullableUuidV7($correlationId, 'correlationId');

        if ($subjectId !== null && $subjectType === null) {
            throw new InvalidArgumentException('audit_subject_type_required');
        }
        if ($classification !== null
            && ! in_array($classification, AuditEventInput::ALLOWED_CLASSIFICATIONS, true)) {
            throw new InvalidArgumentException('audit_classification_invalid');
        }
        if ($occurredFrom !== null) {
            AuditEventInput::assertUtcMilliseconds($occurredFrom, 'occurredFrom');
        }
        if ($occurredTo !== null) {
            AuditEventInput::assertUtcMilliseconds($occurredTo, 'occurredTo');
        }
        if ($occurredFrom !== null && $occurredTo !== null && $occurredFrom > $occurredTo) {
            throw new InvalidArgumentException('audit_occurred_range_invalid');
        }
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidArgumentException('audit_query_limit_out_of_range');
        }
    }

    /**
     * @param  array<array-key, mixed>  $organizationUnitIds
     */
    private static function assertOrganizationUnitIds(array $organizationUnitIds): void
    {
        if (! array_is_list($organizationUnitIds)) {
            throw new InvalidArgumentException('audit_organization_unit_ids_invalid');
        }

        $seen = [];
        foreach ($organizationUnitIds as $organizationUnitId) {
            if (! is_string($organizationUnitId)) {
                throw new InvalidArgumentException('audit_organization_unit_ids_invalid');
            }
            AuditEventInput::assertUuidV7($organizationUnitId, 'organizationUnitId');
            if (isset($seen[$organizationUnitId])) {
                throw new InvalidArgumentException('audit_organization_unit_ids_duplicate');
            }
            $seen[$organizationUnitId] = true;
        }
    }
}

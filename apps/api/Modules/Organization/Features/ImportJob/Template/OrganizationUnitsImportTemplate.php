<?php

namespace Modules\Organization\Features\ImportJob\Template;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Organization\Features\OrganizationUnit\Handler\OrganizationUnitHandler;
use stdClass;

final readonly class OrganizationUnitsImportTemplate implements GovernedImportTemplate
{
    use ValidatesImportRows;

    public function __construct(private OrganizationUnitHandler $units) {}

    public function validate(array $payload): array
    {
        $errors = $this->missingRequired(['cluster_id', 'type_code', 'code', 'name'], $payload);
        if ($errors !== []) {
            return $errors;
        }
        $parentId = $payload['parent_id'] ?? null;
        if ($parentId !== null && ! is_string($parentId)) {
            $errors[] = ['code' => 'invalid_parent', 'severity' => 'error', 'field' => 'parent_id'];
        }
        if (! $this->isUuidV7($payload['cluster_id'])) {
            $errors[] = ['code' => 'invalid_cluster', 'severity' => 'error', 'field' => 'cluster_id'];
        }
        if ($parentId !== null && ! $this->isUuidV7($parentId)) {
            $errors[] = ['code' => 'invalid_parent', 'severity' => 'error', 'field' => 'parent_id'];
        }
        $parent = $this->isUuidV7($payload['cluster_id']) && ($parentId === null || $this->isUuidV7($parentId))
            ? $this->resolveUnitParent($payload['cluster_id'], $parentId)
            : null;
        if ($parent === null) {
            $errors[] = ['code' => 'invalid_parent', 'severity' => 'error', 'field' => 'parent_id'];
        } elseif (strlen($parent['path'].'/'.Str::uuid7()->toString()) > 512) {
            $errors[] = ['code' => 'invalid_parent', 'severity' => 'error', 'field' => 'parent_id'];
        }
        $type = $this->isTypeCode($payload['type_code'])
            ? DB::table('unit_types')->where('code', $payload['type_code'])->where('is_active', true)->first()
            : null;
        if (! $type instanceof stdClass) {
            $errors[] = ['code' => 'invalid_type', 'severity' => 'error', 'field' => 'type_code'];
        }
        if (! $this->isCreateCode($payload['code'])) {
            $errors[] = ['code' => 'invalid_code', 'severity' => 'error', 'field' => 'code'];
        }
        if (! $this->isName($payload['name'])) {
            $errors[] = ['code' => 'invalid_name', 'severity' => 'error', 'field' => 'name'];
        }
        if (array_key_exists('name_en', $payload) && ! $this->isOptionalName($payload['name_en'])) {
            $errors[] = ['code' => 'invalid_name', 'severity' => 'error', 'field' => 'name_en'];
        }
        if ($parent !== null && $this->isCreateCode($payload['code'])
            && DB::table('organization_units')
                ->where('parent_type', $parent['type'])
                ->where('parent_id', $parent['id'])
                ->where('code', $payload['code'])
                ->exists()) {
            $errors[] = ['code' => 'organization_unit_already_exists', 'severity' => 'error', 'field' => 'code'];
        }

        return $errors;
    }

    public function validateBatch(array $payload, ImportBatchContext $context, int $rowNumber): array
    {
        return $context->hasDuplicateUnit($payload['cluster_id'], $payload['parent_id'] ?? null, $payload['code'])
            ? [['code' => 'duplicate_organization_unit_code_in_import', 'severity' => 'critical', 'field' => 'code']]
            : [];
    }

    public function apply(string $rowId, array $payload, string $principalId, Closure $eventFactory, Closure $idempotencyFactory): string
    {
        $result = $this->units->create(
            Str::uuid7()->toString(),
            [
                'cluster_id' => $payload['cluster_id'],
                'parent_id' => $payload['parent_id'] ?? null,
                'type_code' => $payload['type_code'],
                'code' => $payload['code'],
                'name' => $payload['name'],
                'name_en' => $payload['name_en'] ?? null,
            ],
            $idempotencyFactory('importOrganizationUnit:'.$rowId, $payload),
            fn (array $unit): array => $eventFactory(
                'com.cluster.organization.organizationunitcreated.v1',
                '/organization/units/'.$unit['id'],
                ['organization_unit' => $unit],
                'internal',
            ),
        );

        return $result['unit']['id'];
    }
}

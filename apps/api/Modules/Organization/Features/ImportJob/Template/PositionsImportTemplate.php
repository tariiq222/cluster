<?php

namespace Modules\Organization\Features\ImportJob\Template;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Organization\Features\Position\Handler\PositionHandler;
use stdClass;

final readonly class PositionsImportTemplate implements GovernedImportTemplate
{
    use ValidatesImportRows;

    public function __construct(private PositionHandler $positions) {}

    public function validate(array $payload): array
    {
        $errors = $this->missingRequired(['organization_unit_id', 'code', 'title'], $payload);
        if ($errors !== []) {
            return $errors;
        }
        $unit = $this->isUuidV7($payload['organization_unit_id'])
            ? DB::table('organization_units')->where('id', $payload['organization_unit_id'])->where('status', 'active')->first()
            : null;
        if (! $unit instanceof stdClass) {
            $errors[] = ['code' => 'invalid_organization_unit', 'severity' => 'error', 'field' => 'organization_unit_id'];
        }
        if (! $this->isCreateCode($payload['code'])) {
            $errors[] = ['code' => 'invalid_code', 'severity' => 'error', 'field' => 'code'];
        }
        if (! $this->isName($payload['title'])) {
            $errors[] = ['code' => 'invalid_title', 'severity' => 'error', 'field' => 'title'];
        }
        $managerId = $payload['manager_position_id'] ?? null;
        if ($managerId !== null && (! is_string($managerId) || ! $this->isUuidV7($managerId))) {
            $errors[] = ['code' => 'invalid_manager_position', 'severity' => 'error', 'field' => 'manager_position_id'];
        } elseif (is_string($managerId) && ! $this->managerIsActive($managerId)) {
            $errors[] = ['code' => 'invalid_manager_position', 'severity' => 'error', 'field' => 'manager_position_id'];
        }
        if ($this->isUuidV7($payload['organization_unit_id']) && $this->isCreateCode($payload['code'])
            && DB::table('positions')
                ->where('organization_unit_id', $payload['organization_unit_id'])
                ->where('code', $payload['code'])
                ->exists()) {
            $errors[] = ['code' => 'position_already_exists', 'severity' => 'error', 'field' => 'code'];
        }

        return $errors;
    }

    public function validateBatch(array $payload, ImportBatchContext $context, int $rowNumber): array
    {
        return $context->hasDuplicatePosition($payload['organization_unit_id'], $payload['code'])
            ? [['code' => 'duplicate_position_code_in_import', 'severity' => 'critical', 'field' => 'code']]
            : [];
    }

    public function apply(string $rowId, array $payload, string $principalId, Closure $eventFactory, Closure $idempotencyFactory): string
    {
        $result = $this->positions->create(
            Str::uuid7()->toString(),
            [
                'organization_unit_id' => $payload['organization_unit_id'],
                'code' => $payload['code'],
                'title' => $payload['title'],
                'manager_position_id' => $payload['manager_position_id'] ?? null,
            ],
            $idempotencyFactory('importPosition:'.$rowId, $payload),
            fn (array $position, string $_clusterId): array => $eventFactory(
                'com.cluster.organization.positioncreated.v1',
                '/organization/positions/'.$position['id'],
                ['position' => $position],
                'internal',
            ),
        );

        return $result['position']['id'];
    }

    private function managerIsActive(string $managerId): bool
    {
        $candidate = DB::table('positions')->where('id', $managerId)->where('is_active', true)->first();
        $visited = [];
        while ($candidate instanceof stdClass && $candidate->manager_position_id !== null) {
            $nextId = (string) $candidate->manager_position_id;
            if (isset($visited[$nextId])) {
                return false;
            }
            $visited[$nextId] = true;
            $candidate = DB::table('positions')->where('id', $nextId)->where('is_active', true)->first();
        }

        return $candidate instanceof stdClass;
    }
}

<?php

namespace Modules\Organization\Features\ImportJob\Template;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Organization\Domain\Facility;
use Modules\Organization\Features\CreateFacility\Handler\CreateFacilityHandler;
use stdClass;

final readonly class FacilitiesImportTemplate implements GovernedImportTemplate
{
    use ValidatesImportRows;

    public function __construct(private CreateFacilityHandler $facilities) {}

    public function validate(array $payload): array
    {
        $errors = $this->missingRequired(['cluster_id', 'type_code', 'code', 'name'], $payload);
        if ($errors !== []) {
            return $errors;
        }
        if (! $this->isUuidV7($payload['cluster_id']) || ! $this->activeClusterExists($payload['cluster_id'])) {
            $errors[] = ['code' => 'invalid_cluster', 'severity' => 'error', 'field' => 'cluster_id'];
        }
        $type = $this->isTypeCode($payload['type_code'])
            ? DB::table('facility_types')->where('code', $payload['type_code'])->where('is_active', true)->first()
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
        if ($this->isUuidV7($payload['cluster_id']) && $this->isCreateCode($payload['code'])
            && DB::table('facilities')->where('cluster_id', $payload['cluster_id'])->where('code', $payload['code'])->exists()) {
            $errors[] = ['code' => 'facility_already_exists', 'severity' => 'error', 'field' => 'code'];
        }

        return $errors;
    }

    public function validateBatch(array $payload, ImportBatchContext $context, int $rowNumber): array
    {
        return $context->hasDuplicateFacility($payload['cluster_id'], $payload['code'])
            ? [['code' => 'duplicate_facility_code_in_import', 'severity' => 'critical', 'field' => 'code']]
            : [];
    }

    public function apply(string $rowId, array $payload, string $principalId, Closure $eventFactory, Closure $idempotencyFactory): string
    {
        $facility = Facility::create(
            Str::uuid7()->toString(),
            $payload['cluster_id'],
            $payload['type_code'],
            $payload['code'],
            $payload['name'],
            $payload['name_en'] ?? null,
        );
        $data = $facility->toArray();
        $result = $this->facilities->persist(
            $facility,
            $eventFactory(
                'com.cluster.organization.facilitycreated.v1',
                '/organization/facilities/'.$facility->id,
                ['facility' => $data],
                'internal',
            ),
            $idempotencyFactory('importFacility:'.$rowId, $payload),
        );

        return $result['facility']['id'];
    }
}

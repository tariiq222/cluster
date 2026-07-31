<?php

namespace Modules\Organization\Infrastructure\Persistence;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Organization\Contracts\ListOrganizationScopeTargets;

final class DatabaseListOrganizationScopeTargets implements ListOrganizationScopeTargets
{
    /**
     * @param  'cluster'|'facility'|'unit'  $scopeType
     * @param  list<array{scope_type: 'cluster'|'facility'|'unit', scope_id: string}>  $candidates
     * @return array<int, array{scope_type: 'cluster'|'facility'|'unit', scope_id: string, label_ar: string, label_en: string, code?: string|null}>
     */
    public function labelCandidates(string $scopeType, array $candidates, ?string $search): array
    {
        $byTable = $this->bucketByTable($candidates);
        $results = [];

        if ($byTable['cluster'] !== []) {
            foreach ($this->fetchRows('clusters', $byTable['cluster'], $search) as $row) {
                $payload = [
                    'scope_type' => 'cluster',
                    'scope_id' => (string) $row['id'],
                    'label_ar' => $this->labelAr($row),
                    'label_en' => $this->labelEn($row),
                    'code' => (string) $row['code'],
                ];
                $results[$row['__index']] = $payload;
            }
        }

        if ($byTable['facility'] !== []) {
            foreach ($this->fetchRows('facilities', $byTable['facility'], $search) as $row) {
                $payload = [
                    'scope_type' => 'facility',
                    'scope_id' => (string) $row['id'],
                    'label_ar' => $this->labelAr($row),
                    'label_en' => $this->labelEn($row),
                    'code' => (string) $row['code'],
                ];
                $results[$row['__index']] = $payload;
            }
        }

        if ($byTable['unit'] !== []) {
            foreach ($this->fetchRows('organization_units', $byTable['unit'], $search) as $row) {
                $payload = [
                    'scope_type' => 'unit',
                    'scope_id' => (string) $row['id'],
                    'label_ar' => $this->labelAr($row),
                    'label_en' => $this->labelEn($row),
                    'code' => (string) $row['code'],
                ];
                $results[$row['__index']] = $payload;
            }
        }

        ksort($results);

        return $results;
    }

    /**
     * @param  list<array{scope_type: 'cluster'|'facility'|'unit', scope_id: string}>  $candidates
     * @return array{cluster: list<array{index: int, id: string}>, facility: list<array{index: int, id: string}>, unit: list<array{index: int, id: string}>}
     */
    private function bucketByTable(array $candidates): array
    {
        $bucket = [
            'cluster' => [],
            'facility' => [],
            'unit' => [],
        ];

        foreach ($candidates as $index => $candidate) {
            $rowType = $candidate['scope_type'];
            $bucket[$rowType][] = [
                'index' => $index,
                'id' => (string) $candidate['scope_id'],
            ];
        }

        return $bucket;
    }

    /**
     * Apply the OpenAPI AssignmentScopeTarget `minLength: 1` constraint on
     * `label_ar` and `label_en`. Prefer non-blank `name_ar`/`name_en`; fall
     * back across the bilingual pair; only fall back to the stable `code`
     * when both labels are blank. This guarantees the wire shape always
     * satisfies the contract even when a single-language catalog row is
     * missing one side.
     *
     * @param  array{id: string, name_ar: string, name_en: ?string, code: string, __index: int}  $row
     */
    private function labelAr(array $row): string
    {
        $nameAr = trim((string) $row['name_ar']);
        if ($nameAr !== '') {
            return $nameAr;
        }
        $nameEn = trim((string) ($row['name_en'] ?? ''));
        if ($nameEn !== '') {
            return $nameEn;
        }

        return (string) $row['code'];
    }

    /**
     * @param  array{id: string, name_ar: string, name_en: ?string, code: string, __index: int}  $row
     */
    private function labelEn(array $row): string
    {
        $nameEn = trim((string) ($row['name_en'] ?? ''));
        if ($nameEn !== '') {
            return $nameEn;
        }
        $nameAr = trim((string) $row['name_ar']);
        if ($nameAr !== '') {
            return $nameAr;
        }

        return (string) $row['code'];
    }

    /**
     * @param  list<array{index: int, id: string}>  $entries
     * @return list<array{id: string, name_ar: string, name_en: ?string, code: string, __index: int}>
     */
    private function fetchRows(string $table, array $entries, ?string $search): array
    {
        $ids = array_map(static fn (array $entry): string => $entry['id'], $entries);
        $indexById = [];
        foreach ($entries as $entry) {
            $indexById[$entry['id']] = $entry['index'];
        }

        /** @var Builder $query */
        $query = DB::table($table)
            ->select(['id', 'name_ar', 'name_en', 'code'])
            ->whereIn('id', $ids);

        $trimmedSearch = $search === null ? null : trim($search);
        if ($trimmedSearch !== null && $trimmedSearch !== '') {
            $like = '%'.$trimmedSearch.'%';
            $query->where(function (Builder $inner) use ($like): void {
                $inner->where('name_ar', 'like', $like)
                    ->orWhere('name_en', 'like', $like)
                    ->orWhere('code', 'like', $like);
            });
        }

        $rows = $query->get();
        $materialized = [];
        foreach ($rows as $row) {
            $rowArray = (array) $row;
            $id = (string) $rowArray['id'];
            $rowArray['__index'] = $indexById[$id];
            $materialized[] = $rowArray;
        }

        return $materialized;
    }
}

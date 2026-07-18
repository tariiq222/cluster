<?php

namespace Modules\Organization\Features\ImportJob\Template;

use Illuminate\Support\Facades\DB;
use stdClass;

trait ValidatesImportRows
{
    /**
     * @param  list<string>  $required
     * @param  array<string, mixed>  $payload
     * @return list<array{code: string, severity: string, field?: string}>
     */
    private function missingRequired(array $required, array $payload): array
    {
        $errors = [];
        foreach ($required as $field) {
            if (! isset($payload[$field]) || ! is_string($payload[$field]) || trim($payload[$field]) === '') {
                $errors[] = ['code' => 'missing_required_field', 'severity' => 'critical', 'field' => $field];
            }
        }

        return $errors;
    }

    private function isUuidV7(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $value) === 1;
    }

    private function isUtc(string $value): bool
    {
        return preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z\z/', $value) === 1
            && strtotime($value) !== false;
    }

    private function isCreateCode(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[A-Z0-9_-]{2,64}\z/', $value) === 1;
    }

    private function isTypeCode(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[a-z][a-z0-9_]{1,63}\z/', $value) === 1;
    }

    private function isName(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '' && mb_strlen($value) <= 255;
    }

    private function isOptionalName(mixed $value): bool
    {
        return $value === null || (is_string($value) && mb_strlen($value) <= 255);
    }

    private function activeClusterExists(string $clusterId): bool
    {
        return DB::table('clusters')->where('id', $clusterId)->exists();
    }

    /** @return array{id: string, type: string, path: string}|null */
    private function resolveUnitParent(string $clusterId, ?string $parentId): ?array
    {
        if ($parentId === null || $parentId === $clusterId) {
            return $this->activeClusterExists($clusterId) ? ['id' => $clusterId, 'type' => 'cluster', 'path' => '/'.$clusterId] : null;
        }

        $facility = DB::table('facilities')
            ->where('id', $parentId)
            ->where('cluster_id', $clusterId)
            ->where('status', '!=', 'archived')
            ->first();
        if ($facility instanceof stdClass) {
            return ['id' => $parentId, 'type' => 'facility', 'path' => '/'.$clusterId.'/'.$parentId];
        }

        $unit = DB::table('organization_units')
            ->where('id', $parentId)
            ->where('cluster_id', $clusterId)
            ->where('status', '!=', 'archived')
            ->first();

        return $unit instanceof stdClass
            ? ['id' => $parentId, 'type' => 'unit', 'path' => (string) $unit->path_cache]
            : null;
    }
}

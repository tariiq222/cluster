<?php

namespace App\Http\Controllers\Identity;

use Illuminate\Support\Facades\DB;
use Modules\Identity\Contracts\PrincipalContext;

/**
 * Builds the contracted ScopeSelection payload from a trusted PrincipalContext.
 * Labels come from Organization-owned tables through the app layer; selection
 * never expands the resolved scope, only re-presents it.
 */
trait ResolvesScopeSelection
{
    /** @return array{available_scopes: list<array{scope_type: string, scope_id: string, label: string}>, effective_scope: ?array{scope_type: string, scope_id: string, label: string}} */
    private function scopeSelectionFor(PrincipalContext $context): array
    {
        $available = [];
        foreach ($context->clusterIds as $id) {
            $available[] = ['scope_type' => 'cluster', 'scope_id' => $id, 'label' => $this->scopeLabel('clusters', $id)];
        }
        foreach ($context->facilityIds as $id) {
            $available[] = ['scope_type' => 'facility', 'scope_id' => $id, 'label' => $this->scopeLabel('facilities', $id)];
        }
        foreach ($context->organizationUnitIds as $id) {
            $available[] = ['scope_type' => 'unit', 'scope_id' => $id, 'label' => $this->scopeLabel('organization_units', $id)];
        }

        $effective = null;
        $selected = $context->selectedScope;
        if (is_array($selected)) {
            foreach ($available as $option) {
                if ($option['scope_type'] === $selected['scope_type'] && hash_equals($option['scope_id'], (string) $selected['scope_id'])) {
                    $effective = $option;
                    break;
                }
            }
        }
        if ($effective === null && $context->primaryOrganizationUnitId !== null) {
            foreach ($available as $option) {
                if ($option['scope_type'] === 'unit' && hash_equals($option['scope_id'], $context->primaryOrganizationUnitId)) {
                    $effective = $option;
                    break;
                }
            }
        }
        $effective ??= $available[0] ?? null;

        return ['available_scopes' => $available, 'effective_scope' => $effective];
    }

    private function scopeLabel(string $table, string $id): string
    {
        $row = DB::table($table)->where('id', $id)->first(['name_ar', 'name_en', 'name', 'code']);
        if ($row === null) {
            return $id;
        }

        foreach (['name_ar', 'name_en', 'name', 'code'] as $column) {
            $value = $row->{$column} ?? null;
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return $id;
    }

    private function holdsScope(PrincipalContext $context, string $scopeType, string $scopeId): bool
    {
        $held = match ($scopeType) {
            'cluster' => $context->clusterIds,
            'facility' => $context->facilityIds,
            'unit' => $context->organizationUnitIds,
            default => [],
        };

        foreach ($held as $id) {
            if (hash_equals($id, $scopeId)) {
                return true;
            }
        }

        return false;
    }
}

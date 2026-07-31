<?php

declare(strict_types=1);

namespace Modules\Authorization\Infrastructure\Persistence;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Authorization\Features\Administration\Contracts\ListAssignmentScopeTargets;
use Modules\Organization\Contracts\ListOrganizationScopeTargets;
use Modules\Organization\Contracts\ResolveScopeDescendants;
use Shared\Http\AuthenticatedCursorCodec;

/**
 * Task 1B — sole Authorization implementation of
 * {@see ListAssignmentScopeTargets}.
 *
 * The class derives the actor's active authorization.assignment.manage
 * roots directly from Authorization-owned tables (role_assignments joined
 * with role_capabilities and capabilities). It never imports
 * {@see \Modules\Organization\Models\*} or
 * {@see \Modules\Organization\Persistence\*} symbols; the only Organization
 * coupling is through the {@see ListOrganizationScopeTargets} contract and
 * the existing {@see ResolveScopeDescendants} contract.
 *
 * Pagination is keyed on the lexically sorted (scope_type, scope_id) tuple
 * and cursors are bound to principal+filters+limit via
 * {@see AuthenticatedCursorCodec} so a tampered cursor is rejected.
 */
final class DatabaseListAssignmentScopeTargets implements ListAssignmentScopeTargets
{
    private const CURSOR_RESOURCE_KEY = 'authorization.assignment_scope_targets';

    /** @var list<string> */
    private const SCOPE_TYPES = ['cluster', 'facility', 'unit'];

    private const DEFAULT_LIMIT = 25;

    private const MAX_LIMIT = 100;

    public function __construct(
        private readonly ListOrganizationScopeTargets $labeler,
        private readonly ResolveScopeDescendants $descendants,
        private readonly AuthenticatedCursorCodec $cursorCodec,
    ) {}

    /**
     * @return array{items: list<array{scope_type: 'cluster'|'facility'|'unit', scope_id: string, label_ar: string, label_en: string, code?: string|null}>, next_cursor: string|null}
     */
    public function targets(
        string $actorUserId,
        string $scopeType,
        ?string $parentScopeType,
        ?string $parentScopeId,
        ?string $search,
        ?string $cursor,
        int $limit,
    ): array {
        if ($scopeType === 'record_set') {
            throw new InvalidArgumentException('authorization_scope_type_not_catalogued');
        }
        if (! in_array($scopeType, self::SCOPE_TYPES, true)) {
            throw new InvalidArgumentException('invalid_scope_query');
        }
        // A cluster has no parent; a cluster cannot be requested beneath a facility
        // or another cluster. A cluster beneath a unit is also nonsensical and the
        // spec does not require support for it, so we reject anything other than
        // scope_type ∈ {cluster,facility,unit} with parent_scope_type ∈ {null,cluster,facility}.
        if ($scopeType === 'cluster' && $parentScopeType !== null) {
            throw new InvalidArgumentException('invalid_scope_query');
        }
        if ($parentScopeType !== null && ! in_array($parentScopeType, ['cluster', 'facility'], true)) {
            throw new InvalidArgumentException('invalid_scope_query');
        }

        $clampedLimit = $this->clampLimit($limit);
        $manageRoots = $this->manageRoots($actorUserId);
        if ($manageRoots === []) {
            return ['items' => [], 'next_cursor' => null];
        }

        $candidates = $this->candidateRoots($scopeType, $parentScopeType, $parentScopeId, $manageRoots);
        if ($candidates === []) {
            return ['items' => [], 'next_cursor' => null];
        }

        $binding = [
            'principal' => $actorUserId,
            'scope_type' => $scopeType,
            'parent_scope_type' => $parentScopeType,
            'parent_scope_id' => $parentScopeId,
            'search' => $search,
            'limit' => $clampedLimit,
        ];
        $startAfter = null;
        if ($cursor !== null) {
            $sortTuple = $this->cursorCodec->decode($cursor, self::CURSOR_RESOURCE_KEY, $binding);
            $startAfter = $this->normalizeSortTuple($sortTuple);
        }

        $labeled = $this->labeler->labelCandidates($scopeType, $candidates, $search);
        $rows = [];
        foreach ($labeled as $entry) {
            // The labeler is generic over scope_type and may return mixed buckets;
            // the catalog only ever surfaces rows whose scope_type matches the
            // request, so any cross-type leak is dropped here.
            if ($entry['scope_type'] !== $scopeType) {
                continue;
            }
            $scopeId = (string) $entry['scope_id'];
            $sortKey = $scopeType.':'.$scopeId;
            if ($startAfter !== null && $sortKey <= $startAfter) {
                continue;
            }
            $rows[] = [
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'label_ar' => (string) $entry['label_ar'],
                'label_en' => (string) $entry['label_en'],
                'code' => $entry['code'] ?? null,
                '__sort_key' => $sortKey,
            ];
        }

        usort(
            $rows,
            static fn (array $a, array $b): int => strcmp((string) $a['__sort_key'], (string) $b['__sort_key']),
        );

        $page = array_slice($rows, 0, $clampedLimit);
        $nextCursor = null;
        if (count($page) === $clampedLimit && count($rows) > $clampedLimit) {
            $last = $page[count($page) - 1];
            $nextCursor = $this->cursorCodec->encode(
                self::CURSOR_RESOURCE_KEY,
                [(string) $last['__sort_key']],
                $binding,
            );
        }

        $items = array_map(
            static function (array $row): array {
                unset($row['__sort_key']);

                return $row;
            },
            $page,
        );

        return ['items' => $items, 'next_cursor' => $nextCursor];
    }

    private function clampLimit(int $limit): int
    {
        if ($limit <= 0) {
            return self::DEFAULT_LIMIT;
        }

        return min($limit, self::MAX_LIMIT);
    }

    /**
     * @return list<array{scope_type: 'cluster'|'facility'|'unit', scope_id: string}>
     */
    private function manageRoots(string $actorUserId): array
    {
        $now = now()->utc();

        $rows = DB::table('role_assignments')
            ->join('roles', 'roles.id', '=', 'role_assignments.role_id')
            ->join('role_capabilities', 'role_capabilities.role_id', '=', 'roles.id')
            ->join('capabilities', 'capabilities.id', '=', 'role_capabilities.capability_id')
            ->where('role_assignments.user_id', $actorUserId)
            ->where('role_assignments.status', 'active')
            ->where('roles.status', 'active')
            ->where('role_capabilities.effect', 'allow')
            ->where('capabilities.status', 'active')
            ->where('capabilities.capability_code', 'authorization.assignment.manage')
            ->where('role_assignments.start_at', '<=', $now)
            ->where(fn (Builder $query) => $query->whereNull('role_assignments.end_at')->orWhere('role_assignments.end_at', '>', $now))
            ->select('role_assignments.scope_type', 'role_assignments.scope_id')
            ->get();

        $seen = [];
        $roots = [];
        foreach ($rows as $row) {
            $type = (string) $row->scope_type;
            $id = (string) $row->scope_id;
            if (! in_array($type, self::SCOPE_TYPES, true) || $id === '') {
                continue;
            }
            $key = $type.':'.$id;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $roots[] = ['scope_type' => $type, 'scope_id' => $id];
        }

        return $roots;
    }

    /**
     * @param  list<array{scope_type: 'cluster'|'facility'|'unit', scope_id: string}>  $manageRoots
     * @return list<array{scope_type: 'cluster'|'facility'|'unit', scope_id: string}>
     */
    private function candidateRoots(string $scopeType, ?string $parentScopeType, ?string $parentScopeId, array $manageRoots): array
    {
        if ($parentScopeType === null || $parentScopeId === null) {
            // No parent filter: surface exactly those manageable roots whose
            // scope_type matches the requested catalog level so the labeler
            // never has to reject a cross-type candidate.
            return array_values(array_filter(
                $manageRoots,
                static fn (array $root): bool => $root['scope_type'] === $scopeType,
            ));
        }

        // With a parent filter only a manageable root that *exactly* matches
        // the requested parent scope grants authority to list its descendants;
        // a facility-scope manager cannot enumerate a cluster, even by handing
        // the cluster id as parent_scope_id.
        $parentRoot = null;
        foreach ($manageRoots as $root) {
            if ($root['scope_type'] === $parentScopeType && $root['scope_id'] === $parentScopeId) {
                $parentRoot = $root;
                break;
            }
        }
        if ($parentRoot === null) {
            return [];
        }

        // Expand the matching parent into its descendants through the existing
        // ResolveScopeDescendants contract, narrowed to the requested scope
        // level. The parent itself is included when its own scope_type
        // happens to equal the requested one (e.g. facility list scoped to
        // that facility — the act of scoping makes the facility itself an
        // assignable target for the picker).
        $results = [];
        $descendants = $this->descendants->descendants($parentScopeType, $parentScopeId);
        foreach ($descendants as $descendant) {
            if ($descendant['scope_type'] === $scopeType) {
                $results[] = $descendant;
            }
        }
        if ($parentRoot['scope_type'] === $scopeType) {
            $results[] = $parentRoot;
        }

        return $results;
    }

    private function normalizeSortTuple(array $sortTuple): ?string
    {
        if ($sortTuple === []) {
            return null;
        }
        $first = $sortTuple[0] ?? null;

        return is_string($first) && $first !== '' ? $first : null;
    }
}

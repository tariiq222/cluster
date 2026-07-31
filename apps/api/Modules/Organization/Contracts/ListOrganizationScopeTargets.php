<?php

namespace Modules\Organization\Contracts;

/**
 * Resolves display labels for a heterogeneous batch of organization scope
 * candidates (cluster / facility / unit). Used by Authorization to enrich an
 * actor's effective scope set with Arabic/English labels for the UI; the
 * contract never grants authority.
 */
interface ListOrganizationScopeTargets
{
    /**
     * @param  'cluster'|'facility'|'unit'  $scopeType
     * @param  list<array{scope_type: 'cluster'|'facility'|'unit', scope_id: string}>  $candidates
     * @return array<int, array{scope_type: 'cluster'|'facility'|'unit', scope_id: string, label_ar: string, label_en: string, code?: string|null}>
     *
     * The returned array is keyed by the original candidate list index so the
     * caller can pair each result back with the row it came from. Candidates
     * whose row is missing from the database, or whose row does not match the
     * search filter, are omitted from the result.
     */
    public function labelCandidates(string $scopeType, array $candidates, ?string $search): array;
}

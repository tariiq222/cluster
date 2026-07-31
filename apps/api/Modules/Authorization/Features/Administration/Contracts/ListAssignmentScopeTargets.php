<?php

declare(strict_types=1);

namespace Modules\Authorization\Features\Administration\Contracts;

/**
 * Task 1B — Authorization-owned port that resolves the catalog of
 * assignment scope targets the calling actor is allowed to manage.
 *
 * The port takes the actor's user_id directly so the sole Authorization
 * implementation can derive the actor's active assignment.manage roots
 * from Authorization-owned tables itself (the actor may or may not be the
 * authenticated principal — the controller is responsible for the
 * authorization.assignment.manage capability check; this port assumes the
 * caller has been authorised and only filters the catalog by the actor's
 * manageable scope).
 *
 * The port never imports a Modules\Organization\Models\* or
 * Modules\Organization\Persistence\* symbol. The Organization coupling is
 * confined to the implementation file (which injects
 * Modules\Organization\Contracts\ListOrganizationScopeTargets through the
 * contract seam).
 */
interface ListAssignmentScopeTargets
{
    /**
     * @param  string  $actorUserId  The actor whose manageable scope roots bound the catalog.
     * @param  string  $scopeType  One of 'cluster', 'facility', 'unit'.
     * @param  string|null  $parentScopeType  Optional parent scope type used to expand descendants.
     * @param  string|null  $parentScopeId  Optional parent scope id used to expand descendants.
     * @param  string|null  $search  Optional free-text filter applied to label_ar/label_en/code.
     * @param  string|null  $cursor  Opaque authenticated cursor; null means first page.
     * @param  int  $limit  Page size; clamped to [1, 100] with default 25.
     * @return array{items: list<array{scope_type: 'cluster'|'facility'|'unit', scope_id: string, label_ar: string, label_en: string, code?: string|null}>, next_cursor: string|null}
     *
     * Rejection contract:
     *   - scope_type=record_set           → InvalidArgumentException('authorization_scope_type_not_catalogued')
     *   - scope_type=cluster & parent_scope_type=facility → InvalidArgumentException('invalid_scope_query')
     *   - actor with no manageable scope  → {items: [], next_cursor: null} (no exception, HTTP 200)
     *   - tampered cursor                 → InvalidArgumentException propagated from AuthenticatedCursorCodec
     */
    public function targets(
        string $actorUserId,
        string $scopeType,
        ?string $parentScopeType,
        ?string $parentScopeId,
        ?string $search,
        ?string $cursor,
        int $limit,
    ): array;
}

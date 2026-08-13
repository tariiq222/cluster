import type { QueryClient } from '@tanstack/react-query'

/*
 * Scope-bound query keys (api/hooks.ts) embed the principal's scopeEpoch as
 * their LAST element (['tasks', filters, epoch]) so a scope change re-keys
 * every cached read. PurgeScopeBoundRows removes rows that still carry the
 * previous epoch BEFORE the new scope's results render — serving them would
 * display data the user may no longer be entitled to.
 */
const SCOPE_BOUND_PREFIXES = [
  'tasks',
  'documents',
  'audit-events',
  'search',
  'temporary-assignments',
  'supervisory-relationships',
] as const

export function isScopeBoundKey(key: readonly unknown[]): boolean {
  return typeof key[0] === 'string' && (SCOPE_BOUND_PREFIXES as readonly string[]).includes(key[0])
}

/** Removes cached rows of scope-bound queries that still carry the old epoch. */
export function purgeScopeBoundRows(queryClient: QueryClient, previousEpoch: number): void {
  queryClient.removeQueries({
    predicate: (query) => {
      const key = query.queryKey
      if (!Array.isArray(key) || !isScopeBoundKey(key) || key.length === 0) return false
      // Scope-bound keys place the epoch LAST; match it in that exact position
      // so a coincidental `includes` hit (e.g. an epoch-shaped filter value)
      // never prunes a row that is still valid for the current scope.
      return key[key.length - 1] === previousEpoch
    },
  })
}

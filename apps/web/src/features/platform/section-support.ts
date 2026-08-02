import { stateFromError, type ResourceState } from '../../api/http'

/**
 * Shared platform-section state derivation.
 *
 * Every section computes a single `ResourceState` from its react-query
 * result and renders it through `@/components/states` `ResourceBoundary`,
 * so the seven shared resource states (loading / ready / empty /
 * forbidden / not-found / conflict / stale / error) are used uniformly and
 * 403/404 share one non-disclosing copy. No section reclassifies errors
 * itself.
 */

export interface QueryLike<T> {
  isPending: boolean
  error: unknown
  data: T | undefined | null
}

/** Action availability is server-driven through `allowed_actions`. */
export function actionAllowed(allowedActions: readonly string[] | undefined, action: string): boolean {
  return allowedActions?.includes(action) === true
}

export function isEmptyCollection(items: readonly unknown[] | null | undefined): boolean {
  return items === undefined || items === null || items.length === 0
}

/** Derives the shared resource state for a react-query result. */
export function queryResourceState<T>(query: QueryLike<T>, isEmpty: (data: T) => boolean): ResourceState {
  if (query.isPending) return 'loading'
  if (query.error !== null && query.error !== undefined) return stateFromError(query.error)
  if (query.data === undefined || query.data === null || isEmpty(query.data)) return 'empty'
  return 'ready'
}

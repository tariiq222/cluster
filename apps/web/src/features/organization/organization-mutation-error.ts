import { ApiError } from '../../api'

export type OrganizationMutationFailure =
  | { kind: 'stale'; message: null }
  | { kind: 'conflict'; message: string }
  | { kind: 'save'; message: null }

export function classifyOrganizationMutationFailure(error: unknown): OrganizationMutationFailure {
  if (!(error instanceof ApiError)) return { kind: 'save', message: null }
  if (error.status === 412) return { kind: 'stale', message: null }
  if (error.status === 409) {
    return { kind: 'conflict', message: error.problem.detail ?? error.problem.title }
  }
  return { kind: 'save', message: null }
}

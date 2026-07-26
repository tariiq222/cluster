// @vitest-environment node
import { describe, expect, it } from 'vitest'

import { ApiError } from '../../api'
import {
  classifyOrganizationMutationFailure,
  type OrganizationMutationFailure,
} from './organization-mutation-error'

function problem(status: number, detail?: string): ApiError {
  return new ApiError(status, {
    type: 'about:blank',
    title: status === 409 ? 'Conflict' : status === 412 ? 'Precondition Failed' : 'Request failed',
    status,
    ...(detail ? { detail } : {}),
  })
}

function expectEquals<T>(actual: T, expected: T): void {
  expect(actual).toEqual(expected)
}

describe('classifyOrganizationMutationFailure', () => {
  it('preserves server detail when a 409 conflict carries one', () => {
    const failure: OrganizationMutationFailure = classifyOrganizationMutationFailure(
      problem(409, 'Employee number is already assigned.'),
    )
    expectEquals(failure, { kind: 'conflict', message: 'Employee number is already assigned.' })
  })

  it('falls back to problem.title when a 409 conflict has no detail', () => {
    const failure: OrganizationMutationFailure = classifyOrganizationMutationFailure(problem(409))
    expectEquals(failure, { kind: 'conflict', message: 'Conflict' })
  })

  it('maps a 412 precondition failure to stale with no message', () => {
    const failure: OrganizationMutationFailure = classifyOrganizationMutationFailure(
      problem(412, 'If-Match was outdated'),
    )
    expectEquals(failure, { kind: 'stale', message: null })
  })

  it('maps any other ApiError status to save with no message', () => {
    const failure: OrganizationMutationFailure = classifyOrganizationMutationFailure(problem(500))
    expectEquals(failure, { kind: 'save', message: null })
  })

  it('maps non-ApiError failures (including network errors) to save with no message', () => {
    const failure: OrganizationMutationFailure = classifyOrganizationMutationFailure(
      new Error('network down'),
    )
    expectEquals(failure, { kind: 'save', message: null })
  })
})

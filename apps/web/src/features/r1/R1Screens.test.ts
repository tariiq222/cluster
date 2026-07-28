import { describe, expect, it } from 'vitest'
import { ApiError } from '../../api'
import { __test } from './R1Screens'

const problem = (status: number, title: string) =>
  new ApiError(status, { type: 'about:blank', title, status })

describe('R1Screens stateFrom helper', () => {
  it('maps 403 to forbidden', () => {
    expect(__test.stateFrom(problem(403, 'Forbidden'))).toBe('forbidden')
  })

  it('maps 409 and 412 to stale so the UI can refresh and retry', () => {
    expect(__test.stateFrom(problem(409, 'Conflict'))).toBe('stale')
    expect(__test.stateFrom(problem(412, 'Precondition failed'))).toBe('stale')
  })

  it('folds not-found into error because these screens have no distinct copy', () => {
    expect(__test.stateFrom(problem(404, 'Not found'))).toBe('error')
  })

  it('maps 500, 401, and unknown errors to error', () => {
    expect(__test.stateFrom(problem(500, 'Server error'))).toBe('error')
    expect(__test.stateFrom(problem(401, 'Unauthorized'))).toBe('error')
    expect(__test.stateFrom(new Error('network'))).toBe('error')
  })
})

describe('R1Screens common translations', () => {
  it('provides matching Arabic and English label keys', () => {
    const arKeys = Object.keys(__test.common.ar).sort()
    const enKeys = Object.keys(__test.common.en).sort()
    expect(arKeys).toEqual(enKeys)
  })

  it('uses Arabic-default copy for headings', () => {
    expect(__test.common.ar.loading).toBe('جارٍ التحميل…')
    expect(__test.common.en.loading).toBe('Loading…')
    expect(__test.common.ar.empty).toBeTruthy()
    expect(__test.common.en.empty).toBeTruthy()
  })
})
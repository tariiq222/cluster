import { describe, expect, it, vi } from 'vitest'
import { ApiError } from '../../api'
import { __test } from './R1Screens'

describe('R1Screens stateFrom helper', () => {
  it('maps 401 to error and triggers session expiry', () => {
    const expired = vi.fn()
    const state = __test.stateFrom(new ApiError(401, { type: 'about:blank', title: 'Unauthorized', status: 401 }), expired)
    expect(state).toBe('error')
    expect(expired).toHaveBeenCalledOnce()
  })

  it('maps 403 to forbidden without expiring the session', () => {
    const expired = vi.fn()
    const state = __test.stateFrom(new ApiError(403, { type: 'about:blank', title: 'Forbidden', status: 403 }), expired)
    expect(state).toBe('forbidden')
    expect(expired).not.toHaveBeenCalled()
  })

  it('maps 409 and 412 to stale so the UI can refresh and retry', () => {
    const expired = vi.fn()
    expect(__test.stateFrom(new ApiError(409, { type: 'about:blank', title: 'Conflict', status: 409 }), expired)).toBe('stale')
    expect(__test.stateFrom(new ApiError(412, { type: 'about:blank', title: 'Precondition failed', status: 412 }), expired)).toBe('stale')
    expect(expired).not.toHaveBeenCalled()
  })

  it('maps 500 and unknown errors to error', () => {
    const expired = vi.fn()
    expect(__test.stateFrom(new ApiError(500, { type: 'about:blank', title: 'Server error', status: 500 }), expired)).toBe('error')
    expect(__test.stateFrom(new Error('network'), expired)).toBe('error')
  })
})

describe('R1Screens common translations', () => {
  it('provides matching Arabic and English label keys', () => {
    const arKeys = Object.keys(__test.common.ar).sort()
    const enKeys = Object.keys(__test.common.en).sort()
    expect(arKeys).toEqual(enKeys)
  })

  it('does not leave empty values for the new filter labels', () => {
    expect(__test.common.ar.filterOpen).toBeTruthy()
    expect(__test.common.ar.filterDone).toBeTruthy()
    expect(__test.common.ar.filterAll).toBeTruthy()
    expect(__test.common.en.filterOpen).toBeTruthy()
    expect(__test.common.en.filterDone).toBeTruthy()
    expect(__test.common.en.filterAll).toBeTruthy()
  })

  it('exposes confirm and refresh labels in both locales', () => {
    expect(__test.common.ar.confirmComplete).toMatch(/[?؟]$/)
    expect(__test.common.en.confirmComplete).toMatch(/[?؟]$/)
    expect(__test.common.ar.confirmReturn).toMatch(/[?؟]$/)
    expect(__test.common.en.confirmReturn).toMatch(/[?؟]$/)
    expect(__test.common.ar.refresh).toBeTruthy()
    expect(__test.common.en.refresh).toBeTruthy()
  })

  it('uses Arabic-default copy for headings', () => {
    expect(__test.common.ar.loading).toBe('جارٍ التحميل…')
    expect(__test.common.en.loading).toBe('Loading…')
    expect(__test.common.ar.empty).toBeTruthy()
    expect(__test.common.en.empty).toBeTruthy()
  })
})
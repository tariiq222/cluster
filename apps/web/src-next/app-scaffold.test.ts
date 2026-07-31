import { describe, expect, it } from 'vitest'
import { ApiError, stateFromError, parseStrongEtag, uuidV7 } from './api/http'
import { statusLabel } from './i18n'

describe('http', () => {
  it('maps error statuses to resource states', () => {
    expect(stateFromError(new ApiError(403, { type: 'x', title: 'x', status: 403 }))).toBe('forbidden')
    expect(stateFromError(new ApiError(404, { type: 'x', title: 'x', status: 404 }))).toBe('not-found')
    expect(stateFromError(new ApiError(409, { type: 'x', title: 'x', status: 409 }))).toBe('conflict')
    expect(stateFromError(new ApiError(412, { type: 'x', title: 'x', status: 412 }))).toBe('stale')
    expect(stateFromError(new Error('boom'))).toBe('error')
  })

  it('parses strong ETags only', () => {
    expect(parseStrongEtag('"3"')).toBe(3)
    expect(parseStrongEtag('W/"3"')).toBeNull()
    expect(parseStrongEtag('"0"')).toBeNull()
    expect(parseStrongEtag(null)).toBeNull()
  })

  it('generates UUIDv7-shaped ids', () => {
    const value = uuidV7()
    expect(value).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/)
  })
})

describe('i18n', () => {
  it('labels statuses in both locales', () => {
    expect(statusLabel('submitted', 'ar')).toBe('مقدَّم')
    expect(statusLabel('submitted', 'en')).toBe('Submitted')
    expect(statusLabel('unknown-state', 'ar')).toBe('unknown-state')
  })
})

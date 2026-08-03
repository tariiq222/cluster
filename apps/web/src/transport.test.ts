import { describe, expect, it } from 'vitest'
import { requestInit } from './api/http'

const UUID_V7 = /^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/

describe('transport correlation ids', () => {
  it('generates UUIDv7-shaped correlation ids (server rejects v4)', () => {
    const headers = requestInit('csrf', { command: true }).headers as Record<string, string>
    expect(headers['X-Correlation-ID']).toMatch(UUID_V7)
    expect(headers['Idempotency-Key']).toMatch(UUID_V7)
  })

  it('uses an explicit full idempotency key verbatim for commands', () => {
    const headers = requestInit('csrf', {
      command: true,
      idempotency: 'import-submit',
      idempotencyKey: 'import-submit-logical-key',
    }).headers as Record<string, string>

    expect(headers['Idempotency-Key']).toBe('import-submit-logical-key')
  })

  it('ignores an explicit full idempotency key for non-command requests', () => {
    const headers = requestInit('csrf', {
      idempotencyKey: 'must-not-be-sent',
    }).headers as Record<string, string>

    expect(headers['Idempotency-Key']).toBeUndefined()
  })

  it('includes CSRF only on mutations', () => {
    const read = requestInit('csrf').headers as Record<string, string>
    expect(read['X-CSRF-Token']).toBeUndefined()
    const mutation = requestInit('csrf', { mutation: true }).headers as Record<string, string>
    expect(mutation['X-CSRF-Token']).toBe('csrf')
    const command = requestInit('csrf', { command: true }).headers as Record<string, string>
    expect(command['X-CSRF-Token']).toBe('csrf')
  })
})

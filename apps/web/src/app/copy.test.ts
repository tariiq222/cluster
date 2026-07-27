import { describe, expect, it } from 'vitest'
import { initialLocale, LOCALE_KEY, recordStatusLabel, text } from './copy'

describe('app/copy text parity', () => {
  it('exposes matching Arabic and English keys', () => {
    const arKeys = Object.keys(text.ar).sort()
    const enKeys = Object.keys(text.en).sort()
    expect(arKeys).toEqual(enKeys)
  })

  it('uses the current platform name in both locales', () => {
    expect(text.ar.platform).toBe('بوابة العمل المؤسسي')
    expect(text.en.platform).toBe('Institutional Work Portal')
    expect(LOCALE_KEY).toBe('cluster.presentation-locale')
  })

  it('keeps production-facing localization free of development credentials', () => {
    const copy = JSON.stringify(text)
    for (const credential of [
      'w13-e2e-account-a',
      'w13-e2e-account-b',
      'North!River7Quartz2026',
      'Cedar!Orbit8Harbor2026',
    ]) {
      expect(copy).not.toContain(credential)
    }
  })

  it('falls back to Arabic when localStorage is unavailable', () => {
    const originalWindow = (globalThis as { window?: unknown }).window
    Object.defineProperty(globalThis, 'window', {
      value: {
        localStorage: {
          getItem: () => {
            throw new Error('blocked')
          },
        },
      },
      configurable: true,
    })
    try {
      expect(initialLocale()).toBe('ar')
    } finally {
      Object.defineProperty(globalThis, 'window', {
        value: originalWindow,
        configurable: true,
      })
    }
  })

  it('reads the persisted English locale on second visit', () => {
    const originalWindow = (globalThis as { window?: unknown }).window
    Object.defineProperty(globalThis, 'window', {
      value: { localStorage: { getItem: () => 'en' } },
      configurable: true,
    })
    try {
      expect(initialLocale()).toBe('en')
    } finally {
      Object.defineProperty(globalThis, 'window', {
        value: originalWindow,
        configurable: true,
      })
    }
  })

  it('labels each known record status in both locales', () => {
    for (const status of [
      'draft',
      'submitted',
      'in_review',
      'returned',
      'approved',
      'rejected',
      'completed',
      'cancelled',
      'archived',
    ]) {
      expect(recordStatusLabel(status, 'ar')).toBeTruthy()
      expect(recordStatusLabel(status, 'en')).toBeTruthy()
    }
    expect(recordStatusLabel('unknown', 'ar')).toBe('unknown')
    expect(recordStatusLabel('unknown', 'en')).toBe('unknown')
  })
})

import { describe, expect, it } from 'vitest'
import { formatDate, formatNumber } from './i18n'

const ARABIC_INDIC_DIGITS = /[٠-٩]/

describe('i18n number formatting', () => {
  it('renders zero with a Latin digit in both locales', () => {
    expect(formatNumber(0, 'ar')).toBe('0')
    expect(formatNumber(0, 'en')).toBe('0')
    expect(ARABIC_INDIC_DIGITS.test(formatNumber(0, 'ar'))).toBe(false)
  })

  it('renders large numbers with Latin digits and stable grouping in both locales', () => {
    expect(formatNumber(1234567, 'ar')).toBe('1,234,567')
    expect(formatNumber(1234567, 'en')).toBe('1,234,567')
    expect(ARABIC_INDIC_DIGITS.test(formatNumber(1234567, 'ar'))).toBe(false)
    expect(formatNumber(1234567, 'ar')).toMatch(/^[0-9,]+$/)
    expect(formatNumber(1234567, 'en')).toMatch(/^[0-9,]+$/)
  })
})

describe('i18n date formatting', () => {
  it('renders Gregorian YYYY-MM-DD HH:mm with Latin digits in both locales', () => {
    // No timezone designator: parsed as local time, so the expected
    // components hold regardless of the host timezone.
    const input = '2026-08-03T10:15:00'
    expect(formatDate(input, 'ar')).toBe('2026-08-03 10:15')
    expect(formatDate(input, 'en')).toBe('2026-08-03 10:15')
    expect(formatDate(input, 'ar')).toMatch(/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/)
    expect(formatDate(input, 'ar')).toMatch(/^[0-9\-: ]+$/)
    expect(ARABIC_INDIC_DIGITS.test(formatDate(input, 'ar'))).toBe(false)
  })

  it('returns invalid input unchanged in both locales', () => {
    expect(formatDate('not-a-date', 'ar')).toBe('not-a-date')
    expect(formatDate('not-a-date', 'en')).toBe('not-a-date')
  })

  it('returns an empty string for null and undefined', () => {
    expect(formatDate(null, 'ar')).toBe('')
    expect(formatDate(undefined, 'ar')).toBe('')
    expect(formatDate(null, 'en')).toBe('')
    expect(formatDate(undefined, 'en')).toBe('')
  })
})

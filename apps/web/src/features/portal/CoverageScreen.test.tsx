// @vitest-environment jsdom
import { afterEach, describe, expect, it } from 'vitest'
import { cleanup, render, screen } from '@testing-library/react'

import { CoverageScreen } from './CoverageScreen'
import { COVERAGE_MODULES, GAP_ITEMS } from './coverage-data'

afterEach(() => {
  cleanup()
})

describe('CoverageScreen', () => {
  it('renders without crashing in both locales', () => {
    expect(() => render(<CoverageScreen locale="ar" />)).not.toThrow()
    cleanup()
    expect(() => render(<CoverageScreen locale="en" />)).not.toThrow()
  })

  it('uses the danger variant for P0 gaps', () => {
    render(<CoverageScreen locale="en" />)

    const p0Badges = screen.getAllByText('P0')
    expect(p0Badges.length).toBeGreaterThan(0)
    for (const badge of p0Badges) {
      expect(badge.className).toMatch(/status-badge--danger/)
    }
  })

  it('uses the warning variant for P1 gaps', () => {
    render(<CoverageScreen locale="en" />)

    const p1Badges = screen.getAllByText('P1')
    expect(p1Badges.length).toBeGreaterThan(0)
    for (const badge of p1Badges) {
      expect(badge.className).toMatch(/status-badge--warning/)
    }
  })

  it('exposes bilingual labels for every coverage module', () => {
    for (const module of COVERAGE_MODULES) {
      expect(typeof module.name.ar).toBe('string')
      expect(typeof module.name.en).toBe('string')
      expect(module.name.ar.length).toBeGreaterThan(0)
      expect(module.name.en.length).toBeGreaterThan(0)

      expect(typeof module.label.ar).toBe('string')
      expect(typeof module.label.en).toBe('string')
      expect(module.label.ar.length).toBeGreaterThan(0)
      expect(module.label.en.length).toBeGreaterThan(0)
    }
  })

  it('exposes bilingual titles and descriptions for every gap', () => {
    for (const gap of GAP_ITEMS) {
      expect(typeof gap.title.ar).toBe('string')
      expect(typeof gap.title.en).toBe('string')
      expect(gap.title.ar.length).toBeGreaterThan(0)
      expect(gap.title.en.length).toBeGreaterThan(0)

      expect(typeof gap.desc.ar).toBe('string')
      expect(typeof gap.desc.en).toBe('string')
      expect(gap.desc.ar.length).toBeGreaterThan(0)
      expect(gap.desc.en.length).toBeGreaterThan(0)

      expect(['P0', 'P1']).toContain(gap.rank)
    }
  })
})

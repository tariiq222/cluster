/// <reference types="node" />
import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'

// Read the raw stylesheet through node fs. Vite's `?raw` import returns an
// empty string for .css under this repo's vitest CSS handling, so a direct
// fs read is the deterministic option for asserting on the source rules.
const css = readFileSync(new URL('./ui.css', import.meta.url), 'utf8')

/**
 * Regression guard for the drawer slide-in direction.
 *
 * Defect: `[dir="rtl"] .ui-drawer` used to set `--ui-drawer-from: -100%`
 * while the drawer layer kept the panel anchored to the RIGHT edge in RTL
 * (`justify-content: flex-start` on a right-to-left flex row). The mismatch
 * made the drawer visibly enter from the LEFT in Arabic. The drawer is
 * anchored right in both writing directions, so the slide origin must stay
 * `translateX(100%)` everywhere.
 */
describe('Drawer slide-in direction', () => {
  it('slides in from the right edge by default', () => {
    expect(css).toContain('--ui-drawer-from: 100%')
  })

  it('has no RTL override that flips the slide origin to the left', () => {
    const rtlDrawerBlocks = css.match(/\[dir="rtl"\]\s*\.ui-drawer\s*\{[^}]*\}/g) ?? []
    for (const block of rtlDrawerBlocks) {
      expect(block).not.toContain('--ui-drawer-from: -100%')
    }
  })
})

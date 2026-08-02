// @vitest-environment jsdom
import { describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { WorkspaceTabs, type WorkspaceTabItem } from './workspace-tabs'

/*
 * WorkspaceTabs is the shared line-tab implementation every tabbed
 * workspace and tabbed detail page adopts. The wrapper composes the
 * generated Radix-backed Tabs primitive (never edits it) and is the
 * single owner of the line variant, the overflow-safe nav scroll
 * container, the 44px mobile touch target, the compact desktop density,
 * and the `pt-6` panel rhythm.
 *
 * These tests pin the documented contract: accessible tablist label,
 * line variant, vertical root stack, width clamps, the overflow owner,
 * the mobile/desktop density split, the `pt-6` panel, optional test
 * ids, controlled activation, and the optional `contentClassName`
 * merge. Callers remain responsible for capability filtering — the
 * wrapper never infers authorization.
 */

const ITEMS: WorkspaceTabItem[] = [
  { value: 'first', label: 'الأول', content: <p>محتوى الأول</p> },
  {
    value: 'second',
    label: 'الثاني',
    content: <p>محتوى الثاني</p>,
    contentClassName: 'extra-content-class',
  },
]

describe('WorkspaceTabs', () => {
  it('exposes the accessible tablist label on the nav element', () => {
    render(
      <WorkspaceTabs
        label="أقسام"
        value="first"
        onValueChange={() => {}}
        items={ITEMS}
      />,
    )
    expect(screen.getByRole('tablist', { name: 'أقسام' })).toBeInTheDocument()
  })

  it('uses the line variant and the documented overflow owner classes', () => {
    render(
      <WorkspaceTabs
        label="أقسام"
        value="first"
        onValueChange={() => {}}
        items={ITEMS}
      />,
    )
    const nav = screen.getByRole('tablist', { name: 'أقسام' }).parentElement
    expect(nav).not.toBeNull()
    expect(nav!.tagName).toBe('NAV')
    expect(nav!.className).toMatch(/\bmax-w-full\b/)
    expect(nav!.className).toMatch(/\boverflow-x-auto\b/)
    expect(nav!.className).toMatch(/\boverscroll-x-contain\b/)
    // The webkit-scrollbar is suppressed (but never clipped) so the tab
    // strip can still scroll on a touch surface.
    expect(nav!.className).toMatch(/scrollbar-width:none|\[scrollbar-width:none\]/)
  })

  it('stacks the tabs root vertically with min/max width clamps', () => {
    const { container } = render(
      <WorkspaceTabs
        label="أقسام"
        value="first"
        onValueChange={() => {}}
        items={ITEMS}
      />,
    )
    const root = container.querySelector('[data-slot="tabs"]')
    expect(root).not.toBeNull()
    expect(root!.className).toMatch(/\bflex-col\b/)
    expect(root!.className).toMatch(/\bmin-w-0\b/)
    expect(root!.className).toMatch(/\bmax-w-full\b/)
  })

  it('renders the line-variant tablist with a full-width bottom border and no pill background', () => {
    render(
      <WorkspaceTabs
        label="أقسام"
        value="first"
        onValueChange={() => {}}
        items={ITEMS}
      />,
    )
    const list = screen.getByRole('tablist', { name: 'أقسام' })
    expect(list).toHaveAttribute('data-variant', 'line')
    // The structural bottom border is the only visual separator under the
    // line tab strip — no rounded pill container.
    expect(list.className).toMatch(/\bborder-b\b/)
    expect(list.className).not.toMatch(/\bbg-muted\b/)
    expect(list.className).not.toMatch(/\brounded-lg\b/)
  })

  it('keeps the tab list start-aligned and at least the natural content width', () => {
    render(
      <WorkspaceTabs
        label="أقسام"
        value="first"
        onValueChange={() => {}}
        items={ITEMS}
      />,
    )
    const list = screen.getByRole('tablist', { name: 'أقسام' })
    expect(list.className).toMatch(/\bw-full\b/)
    expect(list.className).toMatch(/\bjustify-start\b/)
  })

  it('keeps every trigger at least 44px tall on mobile with sm compact override', () => {
    const { container } = render(
      <WorkspaceTabs
        label="أقسام"
        value="first"
        onValueChange={() => {}}
        items={ITEMS}
      />,
    )
    const triggers = container.querySelectorAll('[data-slot="tabs-trigger"]')
    expect(triggers.length).toBeGreaterThan(0)
    for (const trigger of triggers) {
      expect(trigger.className).toMatch(/\bmin-h-11\b/)
      expect(trigger.className).toMatch(/\bsm:min-h-0\b/)
      // Each trigger is flex-none so it never shrinks below its own
      // intrinsic content width — the parent `<nav>` is the only
      // overflow owner.
      expect(trigger.className).toMatch(/\bflex-none\b/)
    }
  })

  it('aligns the active-tab underline exactly to the structural bottom border', () => {
    const { container } = render(
      <WorkspaceTabs
        label="أقسام"
        value="first"
        onValueChange={() => {}}
        items={ITEMS}
      />,
    )
    const triggers = container.querySelectorAll('[data-slot="tabs-trigger"]')
    expect(triggers.length).toBeGreaterThan(0)
    for (const trigger of triggers) {
      // The `after` pseudo-element is the active-tab indicator. The
      // generated trigger defaults to `bottom:-5px` which sits 5px
      // below the structural border; the wrapper rewrites that to
      // sit flush with the border (Organization exemplar geometry).
      expect(trigger.className).toMatch(/\bafter:inset-x-0\b/)
      expect(trigger.className).toMatch(/\bafter:-bottom-px\b/)
      expect(trigger.className).toMatch(/\bafter:h-0\.5\b/)
      expect(trigger.className).toMatch(/\bdata-\[state=active\]:after:opacity-100\b/)
    }
  })

  it('opens a content panel with the pt-6 rhythm when its tab is active', () => {
    render(
      <WorkspaceTabs
        label="أقسام"
        value="second"
        onValueChange={() => {}}
        items={ITEMS}
      />,
    )
    const panel = screen.getByRole('tabpanel', { name: 'الثاني' })
    expect(panel).toHaveClass('pt-6')
  })

  it('makes every panel focus-visible safe by default', () => {
    render(
      <WorkspaceTabs
        label="أقسام"
        value="second"
        onValueChange={() => {}}
        items={ITEMS}
      />,
    )
    const panel = screen.getByRole('tabpanel', { name: 'الثاني' })
    // The panel ring is the wrapper's responsibility: whenever
    // programmatic focus lands on a tabpanel (Radix re-orients focus
    // on activation), the focus indicator must be consistent with the
    // rest of the design system.
    expect(panel).toHaveClass('focus-visible:ring-2')
    expect(panel).toHaveClass('focus-visible:ring-ring/50')
    expect(panel).toHaveClass('focus-visible:outline-none')
    expect(panel).toHaveClass('rounded-md')
  })

  it('exposes optional root and nav test ids', () => {
    const { container } = render(
      <WorkspaceTabs
        label="أقسام"
        value="first"
        onValueChange={() => {}}
        items={ITEMS}
        testId="workspace-tabs"
        navTestId="workspace-tabs-nav"
      />,
    )
    const root = container.querySelector('[data-testid="workspace-tabs"]')
    expect(root).not.toBeNull()
    const nav = screen.getByTestId('workspace-tabs-nav')
    expect(nav).toBeInTheDocument()
  })

  it('forwards the controlled value and activation callback to the primitive', () => {
    const onValueChange = vi.fn()
    render(
      <WorkspaceTabs
        label="أقسام"
        value="first"
        onValueChange={onValueChange}
        items={ITEMS}
      />,
    )
    // Radix activates on pointer-down for the tabs primitive.
    const second = screen.getByRole('tab', { name: 'الثاني' })
    fireEvent.mouseDown(second)
    fireEvent.click(second)
    expect(onValueChange).toHaveBeenCalledWith('second')
  })

  it('merges the optional contentClassName into the active panel', () => {
    render(
      <WorkspaceTabs
        label="أقسام"
        value="second"
        onValueChange={() => {}}
        items={ITEMS}
      />,
    )
    const panel = screen.getByRole('tabpanel', { name: 'الثاني' })
    expect(panel).toHaveClass('extra-content-class')
  })

  it('merges the optional root className without dropping the documented root classes', () => {
    const { container } = render(
      <WorkspaceTabs
        label="أقسام"
        value="first"
        onValueChange={() => {}}
        items={ITEMS}
        className="extra-root"
      />,
    )
    const root = container.querySelector('[data-slot="tabs"]')
    expect(root).not.toBeNull()
    expect(root!.className).toMatch(/\bflex-col\b/)
    expect(root!.className).toMatch(/\bmin-w-0\b/)
    expect(root!.className).toMatch(/\bmax-w-full\b/)
    expect(root!.className).toMatch(/\bextra-root\b/)
  })

  it('keeps horizontal arrow-key keyboard semantics on the tab strip', async () => {
    const onValueChange = vi.fn()
    render(
      <WorkspaceTabs
        label="أقسام"
        value="first"
        onValueChange={onValueChange}
        items={ITEMS}
      />,
    )
    const [first, second] = screen.getAllByRole('tab')
    fireEvent.keyDown(first, { key: 'ArrowRight' })
    await waitFor(() => {
      expect(second).toHaveFocus()
    })
  })
})

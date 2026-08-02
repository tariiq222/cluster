// @vitest-environment jsdom
import { useState, type ReactNode } from 'react'
import { afterEach, describe, expect, it } from 'vitest'
import { render, screen, fireEvent, cleanup } from '@testing-library/react'
import { Sheet, SheetContent } from '@/components/ui/sheet'
import { Dialog } from '@/components/ui/dialog'
import { SessionProvider } from '../../app/session-context'
import {
  AccessDialogSurface,
  AccessSheetCloseButton,
  AccessSheetSurface,
} from './access-overlays'

/*
 * ACC-04-POLISH: the non-generated localized close wrappers.
 *
 * The generated Sheet/Dialog primitives render an auto-close icon
 * button with a hardcoded `Close` sr-only label. The polish spec
 * requires that:
 *
 *  - the wrapper composes SheetContent/DialogContent with
 *    `showCloseButton={false}` so the primitive's auto-X is gone;
 *  - the wrapper renders its own SheetClose/DialogClose icon button
 *    using the locale-resolved `إغلاق`/`Close` accessible name;
 *  - no duplicate close button is rendered from the primitive;
 *  - the close control is ≥44px on mobile for touch and a smaller
 *    icon on desktop to preserve the documented geometry.
 *
 * The harness wraps `AccessSheetSurface` and `AccessDialogSurface` in
 * the same Sheet/Dialog the consuming tabs use, so the Radix context
 * is available to the SheetClose/DialogClose components.
 */

const session = {
  csrfToken: 'x',
  userId: 'u',
  expiresAt: '2026-12-31T00:00:00Z',
  restricted: false,
}

afterEach(() => {
  cleanup()
})

function mount(locale: 'ar' | 'en', node: ReactNode) {
  return render(
    <SessionProvider session={session} locale={locale} setLocale={() => {}}>
      {node}
    </SessionProvider>,
  )
}

function SheetHarness() {
  const [open, setOpen] = useState(true)
  return (
    <Sheet open={open} onOpenChange={setOpen}>
      <AccessSheetSurface>
        <p>content</p>
      </AccessSheetSurface>
    </Sheet>
  )
}

function DialogHarness() {
  const [open, setOpen] = useState(true)
  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <AccessDialogSurface>
        <p>content</p>
      </AccessDialogSurface>
    </Dialog>
  )
}

describe('access localized close wrapper (Sheet)', () => {
  it('renders the localized close button with the ar name in Arabic', async () => {
    mount('ar', <SheetHarness />)
    const close = await screen.findByRole('button', { name: 'إغلاق' })
    expect(close).toBeInTheDocument()
  })

  it('renders the localized close button with the en name in English', async () => {
    mount('en', <SheetHarness />)
    const close = await screen.findByRole('button', { name: 'Close' })
    expect(close).toBeInTheDocument()
  })

  it('disables the primitive auto-X so no English `Close` sr-only is rendered', async () => {
    mount('ar', <SheetHarness />)
    /*
     * The auto-X carries a hardcoded `Close` sr-only in the generated
     * primitive. With showCloseButton={false} on the wrapper that auto
     * node must not exist. The only `Close` accessible name on the
     * page is the locale-resolved button (in en, but our render is ar,
     * so the page has zero English `Close` accessible names).
     */
    await screen.findByRole('button', { name: 'إغلاق' })
    expect(screen.queryByRole('button', { name: 'Close' })).toBeNull()
  })

  it('keeps the close button at a 44px touch target on mobile with token ring', async () => {
    mount('ar', <SheetHarness />)
    const close = await screen.findByRole('button', { name: 'إغلاق' })
    expect(close.className).toMatch(/\bsize-11\b/)
    expect(close.className).toMatch(/\bsm:size-8\b/)
    /*
     * The close button must use the design-system `ring` token for its
     * focus indicator so it stays visually consistent with every other
     * focusable control in the workspace. `text-foreground` is a
     * typography token and would have produced a non-canonical
     * `ring-foreground` rule.
     */
    expect(close.className).toMatch(/\bfocus-visible:ring-ring\/50\b/)
    expect(close.className).not.toMatch(/\bfocus-visible:ring-foreground\b/)
  })

  it('closes the sheet when the localized close button is activated', async () => {
    mount('ar', <SheetHarness />)
    const close = await screen.findByRole('button', { name: 'إغلاق' })
    fireEvent.click(close)
    // After close, the content is gone from the document; the wrapper
    // mounted <p>content</p> which is only present while the sheet is
    // open.
    expect(screen.queryByText('content')).toBeNull()
  })
})

describe('access localized close wrapper (Dialog)', () => {
  it('renders the localized close button with the ar name in Arabic', async () => {
    mount('ar', <DialogHarness />)
    const close = await screen.findByRole('button', { name: 'إغلاق' })
    expect(close).toBeInTheDocument()
  })

  it('renders the localized close button with the en name in English', async () => {
    mount('en', <DialogHarness />)
    const close = await screen.findByRole('button', { name: 'Close' })
    expect(close).toBeInTheDocument()
  })

  it('disables the primitive auto-X so no English `Close` sr-only is rendered', async () => {
    mount('ar', <DialogHarness />)
    await screen.findByRole('button', { name: 'إغلاق' })
    expect(screen.queryByRole('button', { name: 'Close' })).toBeNull()
  })
})

describe('access close button exported helper', () => {
  /*
   * The wrapper exposes `AccessSheetCloseButton` so future surfaces
   * that need a different composition (e.g. a side sheet without the
   * default overflow-y-auto class) can mount just the close button
   * inside their own `SheetContent`. The helper still reads the locale
   * and renders the localized accessible name.
   *
   * The helper must be mounted inside `SheetContent`: `SheetClose` is
   * a Radix `asChild` Slot that only resolves inside the sheet content
   * context. Rendering it directly under `Sheet` (outside
   * `SheetContent`) is not a production-supported composition, and the
   * aria-label is not propagated there.
   */
  function HelperHarness() {
    const [open, setOpen] = useState(true)
    return (
      <Sheet open={open} onOpenChange={setOpen}>
        <SheetContent showCloseButton={false}>
          <AccessSheetCloseButton />
        </SheetContent>
      </Sheet>
    )
  }

  it('renders the localized ar close accessible name', async () => {
    mount('ar', <HelperHarness />)
    expect(await screen.findByRole('button', { name: 'إغلاق' })).toBeInTheDocument()
  })

  it('renders the localized en close accessible name', async () => {
    mount('en', <HelperHarness />)
    expect(await screen.findByRole('button', { name: 'Close' })).toBeInTheDocument()
  })
})

// @vitest-environment jsdom
import { useState, type ReactNode } from 'react'
import { afterEach, describe, expect, it } from 'vitest'
import { render, screen, fireEvent, cleanup } from '@testing-library/react'
import { Dialog } from '@/components/ui/dialog'
import { SessionProvider } from '../../app/session-context'
import { AccessDialogSurface } from './access-overlays'

/*
 * ACC-04-POLISH: the non-generated localized close wrapper.
 *
 * The generated Dialog primitive renders an auto-close icon button with
 * a hardcoded `Close` sr-only label. The polish spec requires that:
 *
 *  - the wrapper composes DialogContent with `showCloseButton={false}`
 *    so the primitive's auto-X is gone;
 *  - the wrapper renders its own DialogClose icon button using the
 *    locale-resolved `إغلاق`/`Close` accessible name;
 *  - no duplicate close button is rendered from the primitive;
 *  - the close control is ≥44px on mobile for touch and a smaller
 *    icon on desktop to preserve the documented geometry.
 *
 * The harness wraps `AccessDialogSurface` in the same Dialog the
 * consuming screens use, so the Radix context is available to the
 * DialogClose component.
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

  it('keeps the close button at a 44px touch target on mobile with token ring', async () => {
    mount('ar', <DialogHarness />)
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

  it('closes the dialog when the localized close button is activated', async () => {
    mount('ar', <DialogHarness />)
    const close = await screen.findByRole('button', { name: 'إغلاق' })
    fireEvent.click(close)
    expect(screen.queryByText('content')).toBeNull()
  })
})

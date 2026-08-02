import { X } from 'lucide-react'
import { SheetContent, SheetClose } from '@/components/ui/sheet'
import { DialogContent, DialogClose } from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { accountsCopy } from './accounts-copy'
import { useLocale } from '../../app/session-context'

/*
 * Non-generated localized close-button wrapper for the access workspace.
 *
 * The generated Sheet/Dialog primitives render an auto-close icon button
 * with a hardcoded `Close` sr-only label baked into the generated
 * source. That label cannot be localized without re-generating the
 * primitives, so the polish spec pins this composition: disable the
 * primitive's auto close, render a feature-owned icon button with the
 * locale-resolved `إغلاق`/`Close` accessible name, and keep the
 * position/size matching the primitive so geometry is unchanged.
 *
 * The wrapper is intentionally a thin composition — no state, no
 * business logic. It is consumed by `CreateAccountSheet`,
 * `ManageAccountSheet`, `AssignmentSheet`, `RoleSheet`, and the
 * `ActivationDialog`; AlertDialogs in the workspace already use
 * localized cancel/action controls and have no auto-X, so they stay on
 * the raw `AlertDialog` primitive.
 *
 * The close-button size and position mirror the generated primitive's
 * default (`size-icon-sm`, top-3 end-3 for sheets, top-2 end-2 for the
 * dialog) plus a 44px touch target on mobile so the close control never
 * shrinks below WCAG 2.5.5.
 */

/*
 * Close-button focus ring: the design-system `ring` token at 50% alpha
 * is the canonical focus indicator for `Button` (see
 * `components/ui/button.tsx` `focus-visible:ring-ring/50`). Reusing it
 * keeps the close button's focus indicator visually consistent with
 * every other focusable control in the workspace; `text-foreground` is
 * a typography token, not a ring color, and would have produced a
 * non-token `ring-foreground` rule that the design system does not
 * expose.
 */
const SHEET_CLOSE_POSITION =
  'absolute top-3 end-3 size-11 sm:size-8 focus-visible:ring-ring/50'
const DIALOG_CLOSE_POSITION =
  'absolute top-2 end-2 size-11 sm:size-8 focus-visible:ring-ring/50'

/*
 * `AccessSheetSurface` drops inside the consumer's existing `Sheet` so
 * the Radix Sheet context (open/onOpenChange) stays at the call site
 * and the wrapper is purely a content+close pair. The locale is read
 * inside the wrapper; the consumer does not thread it through.
 */
export function AccessSheetSurface({
  children,
  side,
  className,
}: {
  children: React.ReactNode
  side?: 'top' | 'right' | 'bottom' | 'left'
  className?: string
}) {
  return (
    <SheetContent
      side={side}
      showCloseButton={false}
      className={className ?? 'overflow-y-auto'}
    >
      <AccessSheetCloseButton />
      {children}
    </SheetContent>
  )
}

export function AccessSheetCloseButton() {
  const locale = useLocale()
  const label = accountsCopy[locale].close
  /*
   * The aria-label sits on the rendered <Button>, not on `SheetClose
   * asChild`. The Slot merge gives the Button all of SheetClose's
   * data-* attributes, but the rendered element identity is the
   * Button's <button>, so the accessible name must travel with that
   * element to be picked up by the accessibility tree.
   */
  return (
    <SheetClose asChild>
      <Button
        variant="ghost"
        size="icon"
        aria-label={label}
        className={SHEET_CLOSE_POSITION}
      >
        <X aria-hidden="true" />
      </Button>
    </SheetClose>
  )
}

export function AccessDialogSurface({
  children,
  className,
}: {
  children: React.ReactNode
  className?: string
}) {
  return (
    <DialogContent showCloseButton={false} className={className}>
      <AccessDialogCloseButton />
      {children}
    </DialogContent>
  )
}

export function AccessDialogCloseButton() {
  const locale = useLocale()
  const label = accountsCopy[locale].close
  return (
    <DialogClose asChild>
      <Button
        variant="ghost"
        size="icon"
        aria-label={label}
        className={DIALOG_CLOSE_POSITION}
      >
        <X aria-hidden="true" />
      </Button>
    </DialogClose>
  )
}

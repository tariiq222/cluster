import { X } from 'lucide-react'
import { DialogContent, DialogClose } from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { accountsCopy } from './accounts-copy'
import { useLocale } from '../../app/session-context'

/*
 * Non-generated localized close-button wrapper for the access workspace
 * dialogs (activation confirmation).
 *
 * The generated Dialog primitive renders an auto-close icon button with
 * a hardcoded `Close` sr-only label baked into the generated source.
 * That label cannot be localized without re-generating the primitives,
 * so this composition disables the primitive's auto close and renders a
 * feature-owned icon button with the locale-resolved `إغلاق`/`Close`
 * accessible name.
 *
 * The wrapper is intentionally a thin composition — no state, no
 * business logic. AlertDialogs in the workspace already use localized
 * cancel/action controls and have no auto-X, so they stay on the raw
 * `AlertDialog` primitive.
 *
 * The close-button size and position mirror the generated primitive's
 * default (`size-icon-sm`, top-2 end-2 for the dialog) plus a 44px touch
 * target on mobile so the close control never shrinks below WCAG 2.5.5.
 */

const DIALOG_CLOSE_POSITION =
  'absolute top-2 end-2 size-11 sm:size-8 focus-visible:ring-ring/50'

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

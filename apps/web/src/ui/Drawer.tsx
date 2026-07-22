import { useEffect, useRef, type ReactNode } from 'react'
import { X } from 'lucide-react'
import { cx } from './cx'

/**
 * Unified sliding panel that overlays a single surface (organizational
 * drawers, filters, inspectors). Always anchored to the right edge and
 * slides in from the right (translateX(100%) → 0) in both LTR and RTL —
 * that is the product standard for this platform. Closes on Escape and on
 * backdrop click unless the caller disables it (`dismissable={false}`) —
 * typically when the form is dirty. Focus is moved to the heading on open
 * and restored to the previously focused element on close.
 */
export function Drawer({
  open,
  onClose,
  title,
  children,
  dismissable = true,
  ariaLabelClose = 'إغلاق',
  className,
}: {
  open: boolean
  onClose: () => void
  title: ReactNode
  children: ReactNode
  dismissable?: boolean
  ariaLabelClose?: ReactNode
  className?: string
}) {
  const panelRef = useRef<HTMLElement>(null)
  const lastFocusedRef = useRef<HTMLElement | null>(null)

  useEffect(() => {
    if (!open) return

    const previous = document.activeElement
    lastFocusedRef.current = previous instanceof HTMLElement ? previous : null

    const handleKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape' && dismissable) {
        event.preventDefault()
        onClose()
      }
    }
    document.addEventListener('keydown', handleKey)

    const focusTimer = window.requestAnimationFrame(() => {
      const heading = panelRef.current?.querySelector<HTMLElement>('[data-drawer-heading]')
      heading?.focus()
    })

    return () => {
      document.removeEventListener('keydown', handleKey)
      window.cancelAnimationFrame(focusTimer)
      lastFocusedRef.current?.focus()
    }
  }, [open, dismissable, onClose])

  if (!open) return null

  return (
    <div className="ui-drawer-layer" role="presentation" onMouseDown={(event) => {
      if (!dismissable) return
      if (event.target === event.currentTarget) onClose()
    }}>
      <aside
        ref={panelRef}
        role="dialog"
        aria-modal="true"
        aria-label={typeof title === 'string' ? title : undefined}
        className={cx('ui-drawer', className)}
      >
        <header className="ui-drawer-header">
          <h2 className="ui-drawer-title" tabIndex={-1} data-drawer-heading>{title}</h2>
          <button
            type="button"
            className="ui-drawer-close"
            aria-label={String(ariaLabelClose)}
            onClick={onClose}
          >
            <X aria-hidden="true" />
          </button>
        </header>
        <div className="ui-drawer-body">{children}</div>
      </aside>
    </div>
  )
}

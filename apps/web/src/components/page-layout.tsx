import type { HTMLAttributes, ReactNode } from 'react'
import { cn } from '@/lib/utils'

/*
 * PageLayout is the shared centered max-width page shell every workspace
 * and detail page adopts. The shell already owns the content landmark, so
 * the wrapper itself is a non-semantic `div` — the section/screen that
 * mounts the layout decides what semantic structure surrounds it.
 *
 * The vertical rhythm is the documented six-unit gap (`space-y-6`) that
 * the binding design rules require; width is clamped to the main column
 * with `mx-auto w-full max-w-6xl min-w-0` so a wide table or long
 * identifier can never push the document wider than the viewport. Caller
 * `className` is merged with `cn()` so add-ons (test ids, debug hooks)
 * stay compatible.
 */

export interface PageLayoutProps extends HTMLAttributes<HTMLDivElement> {
  children: ReactNode
}

export function PageLayout({ className, children, ...props }: PageLayoutProps) {
  return (
    <div
      className={cn('mx-auto w-full max-w-6xl min-w-0 space-y-6', className)}
      {...props}
    >
      {children}
    </div>
  )
}

/*
 * PageHeader renders the single H1 the design rules require per page
 * (`text-2xl font-semibold tracking-tight`) with an optional description
 * (`text-muted-foreground text-sm`), an optional `meta` slot beside the
 * title (e.g. a status badge), and an optional `actions` region on the
 * trailing edge of the header. The header is a responsive flex row that
 * wraps on narrow viewports so the action region drops under the
 * heading on mobile instead of clipping.
 *
 * Heading id is forwarded when provided; the screen that owns the
 * wrapper is responsible for any aria-labelledby wiring.
 */

export interface PageHeaderProps {
  title: ReactNode
  description?: ReactNode
  meta?: ReactNode
  actions?: ReactNode
  headingId?: string
  className?: string
}

export function PageHeader({
  title,
  description,
  meta,
  actions,
  headingId,
  className,
}: PageHeaderProps) {
  return (
    <header
      className={cn('flex flex-wrap items-start justify-between gap-3', className)}
    >
      <div className="min-w-0 space-y-1">
        <div className="flex flex-wrap items-center gap-2">
          <h1
            id={headingId}
            className="text-2xl font-semibold tracking-tight"
          >
            {title}
          </h1>
          {meta ? <div className="flex flex-wrap items-center gap-2">{meta}</div> : null}
        </div>
        {description ? (
          <p className="text-muted-foreground text-sm">{description}</p>
        ) : null}
      </div>
      {actions ? (
        <div className="flex flex-wrap items-center gap-2">{actions}</div>
      ) : null}
    </header>
  )
}

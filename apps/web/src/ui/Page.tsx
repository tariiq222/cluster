import type { HTMLAttributes, ReactNode } from 'react'
import { cx } from './cx'

/** Top-level page container. One per screen. */
export function Page({ className, children, ...rest }: HTMLAttributes<HTMLDivElement> & { children: ReactNode }) {
  return <div className={cx('ui-page', className)} {...rest}>{children}</div>
}

/** Unified page header: title, optional description, optional actions. */
export function PageHeader({
  id,
  title,
  description,
  actions,
}: {
  id: string
  title: ReactNode
  description?: ReactNode
  actions?: ReactNode
}) {
  return (
    <header className="ui-page-header">
      <div>
        <h1 id={id}>{title}</h1>
        {description ? <p>{description}</p> : null}
      </div>
      {actions ? <div className="ui-page-header-actions">{actions}</div> : null}
    </header>
  )
}

/** Section with the unified accent-underlined heading. */
export function PageSection({
  id,
  title,
  actions,
  className,
  children,
}: {
  id: string
  title: ReactNode
  actions?: ReactNode
  className?: string
  children: ReactNode
}) {
  return (
    <section aria-labelledby={id} className={className}>
      <div className="ui-section-heading">
        <h2 id={id}>{title}</h2>
        {actions}
      </div>
      {children}
    </section>
  )
}

/** Two-column panel grid that collapses on narrow viewports. */
export function PanelGrid({ className, children }: { className?: string; children: ReactNode }) {
  return <div className={cx('ui-panel-grid', className)}>{children}</div>
}

/** Surface card with a heading and an optional heading-level action. */
export function Panel({
  id,
  title,
  level = 3,
  actions,
  className,
  children,
}: {
  id: string
  title: ReactNode
  level?: 2 | 3
  actions?: ReactNode
  className?: string
  children: ReactNode
}) {
  const Heading = level === 2 ? 'h2' : 'h3'
  return (
    <article className={cx('ui-panel', className)} aria-labelledby={id}>
      <div className="ui-panel-heading">
        <Heading id={id}>{title}</Heading>
        {actions}
      </div>
      {children}
    </article>
  )
}

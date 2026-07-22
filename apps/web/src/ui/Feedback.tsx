import type { ReactNode } from 'react'
import { cx } from './cx'

/** Unified empty state: icon, title, optional body and action. */
export function EmptyState({
  icon,
  title,
  body,
  action,
  className,
}: {
  icon: ReactNode
  title: ReactNode
  body?: ReactNode
  action?: ReactNode
  className?: string
}) {
  return (
    <div className={cx('ui-empty-state', className)}>
      <span className="ui-empty-icon" aria-hidden="true">{icon}</span>
      <strong>{title}</strong>
      {body ? <p>{body}</p> : null}
      {action ? <div className="ui-empty-action">{action}</div> : null}
    </div>
  )
}

/** Unified inline error with an optional retry action. */
export function InlineError({
  message,
  retryLabel,
  onRetry,
}: {
  message: ReactNode
  retryLabel?: ReactNode
  onRetry?: () => void
}) {
  return (
    <div className="ui-inline-error" role="alert">
      <p>{message}</p>
      {onRetry ? (
        <button type="button" className="secondary-button" onClick={onRetry}>{retryLabel}</button>
      ) : null}
    </div>
  )
}

/** Unified loading placeholder list. */
export function SkeletonList({ label, rows = 3 }: { label: string; rows?: number }) {
  return (
    <div className="skeleton-list" aria-label={label}>
      {Array.from({ length: rows }, (_, index) => (
        <div className="skeleton-row" aria-hidden="true" key={index} />
      ))}
    </div>
  )
}

/** Status pill shared by every list and detail surface. */
export function StatusBadge({ children, className }: { children: ReactNode; className?: string }) {
  return <span className={cx('status-badge', className)}>{children}</span>
}

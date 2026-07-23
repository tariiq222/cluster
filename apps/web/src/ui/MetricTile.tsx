import type { ReactNode } from 'react'
import { cx } from './cx'

export type MetricTileVariant =
  | 'ready'
  | 'empty'
  | 'unavailable'
  | 'stale'
  | 'error'

/**
 * Unified indicator tile. Renders label, value, optional unit, period, last-updated
 * time, and source. Variant drives semantic color so zero, empty, and unavailable
 * never share the same visual treatment.
 *
 * Per DESIGN.md §5 "Dashboard Indicators":
 * - zero is a real value
 * - empty means absence of a record
 * - unavailable means missing capability or source
 */
export function MetricTile({
  label,
  value,
  unit,
  period,
  updatedAt,
  source,
  variant = 'ready',
  className,
  action,
}: {
  label: ReactNode
  value: ReactNode
  unit?: ReactNode
  period?: ReactNode
  updatedAt?: ReactNode
  source?: ReactNode
  variant?: MetricTileVariant
  className?: string
  action?: ReactNode
}) {
  return (
    <article
      className={cx('ui-metric-tile', `ui-metric-tile--${variant}`, className)}
      data-variant={variant}
    >
      <header className="ui-metric-tile-head">
        <span className="ui-metric-tile-label">{label}</span>
        {action ? <span className="ui-metric-tile-action">{action}</span> : null}
      </header>
      <p className="ui-metric-tile-value" aria-live="polite">
        <span className="ui-metric-tile-number">{value}</span>
        {unit ? <span className="ui-metric-tile-unit">{unit}</span> : null}
      </p>
      <footer className="ui-metric-tile-foot">
        {period ? <span className="ui-metric-tile-period">{period}</span> : null}
        {updatedAt ? (
          <time className="ui-metric-tile-updated" dateTime={typeof updatedAt === 'string' ? updatedAt : undefined}>
            {updatedAt}
          </time>
        ) : null}
      </footer>
      {source ? <p className="ui-metric-tile-source">{source}</p> : null}
    </article>
  )
}

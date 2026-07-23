import type { ReactNode } from 'react'
import { cx } from './cx'

export type DataFreshnessState = 'fresh' | 'stale' | 'unknown'

/**
 * Renders the freshness metadata of a dashboard indicator: last updated time,
 * the period it covers, and an explicit stale warning when the snapshot is older
 * than the user's expectation.
 *
 * DESIGN.md §5 requires that every indicator carries a freshness/period/source
 * distinction; this primitive centralizes that contract.
 */
export function DataFreshness({
  updatedAt,
  period,
  state = 'unknown',
  className,
  staleAfterMinutes,
}: {
  updatedAt?: ReactNode
  period?: ReactNode
  state?: DataFreshnessState
  className?: string
  staleAfterMinutes?: number
}) {
  return (
    <p
      className={cx('ui-data-freshness', `ui-data-freshness--${state}`, className)}
      aria-label={state === 'stale' ? 'Stale data' : undefined}
    >
      {updatedAt ? (
        <span className="ui-data-freshness-updated">{updatedAt}</span>
      ) : null}
      {period ? <span className="ui-data-freshness-period">{period}</span> : null}
      {state === 'stale' ? (
        <span className="ui-data-freshness-warning">
          {staleAfterMinutes
            ? `Updated more than ${staleAfterMinutes} minutes ago — refresh`
            : 'Stale — refresh'}
        </span>
      ) : null}
    </p>
  )
}

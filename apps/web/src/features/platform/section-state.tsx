import type { ReactNode } from 'react'
import { CircleAlert } from 'lucide-react'
import type { Locale } from '../../i18n'
import type { ResourceState } from '../../api/http'
import { ResourceBoundary } from '@/components/states'
import { Alert, AlertTitle } from '@/components/ui/alert'

/**
 * Renders the shared seven resource states for a platform section payload.
 * `state === 'ready'` renders `children`; every other state renders the
 * shared boundary (loading skeleton, non-disclosing denied, conflict/stale
 * alerts, or the retryable error state).
 */
export function SectionBoundary({
  state,
  empty,
  onRetry,
  onRefresh,
  locale,
  children,
  rows = 4,
}: {
  state: ResourceState
  empty?: ReactNode
  onRetry?: () => void
  onRefresh?: () => void
  locale: Locale
  children?: ReactNode
  rows?: number
}) {
  return (
    <ResourceBoundary
      state={state}
      locale={locale}
      onRetry={onRetry}
      onRefresh={onRefresh}
      empty={empty}
      rows={rows}
    >
      {children ?? null}
    </ResourceBoundary>
  )
}

/** Success / informational feedback after a completed action. */
export function ActionNotice({ message }: { message: string }) {
  return (
    <Alert role="status">
      <CircleAlert className="size-4" aria-hidden="true" />
      <AlertTitle>{message}</AlertTitle>
    </Alert>
  )
}

/** Mutation-level error feedback. */
export function ActionError({ message }: { message: string }) {
  return (
    <Alert variant="destructive" role="alert">
      <CircleAlert className="size-4" aria-hidden="true" />
      <AlertTitle>{message}</AlertTitle>
    </Alert>
  )
}

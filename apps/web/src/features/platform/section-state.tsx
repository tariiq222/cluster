import { useLocale } from '../../app/session-context'
import { EmptyState, InlineError, SkeletonList } from '../../ui'
import { platformCopy, t } from './platform-copy'
import type { SectionState } from './section-support'

/**
 * Shared loading / forbidden / error / empty rendering for a platform
 * management section. `ready` renders nothing; the section renders its data.
 */
export function SectionStateView({
  state,
  emptyTitle,
  onRetry,
}: {
  state: SectionState
  emptyTitle?: string
  onRetry?: () => void
}) {
  const locale = useLocale()
  if (state === 'loading') return <SkeletonList rows={4} />
  if (state === 'forbidden') {
    return (
      <EmptyState
        title={t(platformCopy.unavailable, locale)}
        body={t(platformCopy.unavailableBody, locale)}
      />
    )
  }
  if (state === 'empty') {
    return <EmptyState title={emptyTitle ?? t(platformCopy.empty, locale)} />
  }
  return (
    <InlineError
      message={t(platformCopy.error, locale)}
      retryLabel={t(platformCopy.retry, locale)}
      onRetry={onRetry}
    />
  )
}

export function ActionNotice({ message }: { message: string }) {
  return (
    <p className="status-message status-message--success" role="status">
      {message}
    </p>
  )
}

export function ActionError({ message, onRetry }: { message: string; onRetry?: () => void }) {
  const locale = useLocale()
  return (
    <InlineError
      message={message}
      retryLabel={onRetry ? t(platformCopy.retry, locale) : undefined}
      onRetry={onRetry}
    />
  )
}

import type { ReactNode } from 'react'
import { CircleAlert, RefreshCw, RotateCcw } from 'lucide-react'
import type { ResourceState } from '@/api/http'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import type { Locale } from '@/i18n'

/*
 * The seven resource states (DESIGN-RULES §5) as shared components.
 *
 * 403 and 404 resolve to the SAME copy constant on purpose: the difference
 * between "forbidden" and "missing" is a resource-existence leak. One string
 * cannot drift apart.
 */
const DENIED: Record<Locale, string> = {
  ar: 'لا يمكن الوصول إلى هذا المحتوى.',
  en: 'This content cannot be accessed.',
}

const CONFLICT: Record<Locale, string> = {
  ar: 'تعذّر الحفظ: حُدّث المحتوى من جهة أخرى أثناء عملك.',
  en: 'Could not save: the content was updated elsewhere while you were working.',
}

const STALE: Record<Locale, string> = {
  ar: 'تغيّر السجل منذ آخر قراءة.',
  en: 'The record changed since it was last read.',
}

const ERROR_TITLE: Record<Locale, string> = {
  ar: 'حدث خطأ أثناء تحميل البيانات.',
  en: 'An error occurred while loading the data.',
}

const TRY_AGAIN: Record<Locale, string> = {
  ar: 'أعد المحاولة',
  en: 'Try again',
}

const REFRESH: Record<Locale, string> = {
  ar: 'حدّث لعرض النسخة الأحدث',
  en: 'Refresh to see the latest version',
}

/*
 * Shared loading label: matches the announcement RouteFallback uses, so
 * callers that delegate to RouteFallback (e.g. via Suspense) never
 * double-announce while direct LoadingState users do. Centralized so the
 * ar/en strings stay in lockstep with shellCopy.
 */
const LOADING: Record<Locale, string> = {
  ar: 'جارٍ التحميل…',
  en: 'Loading…',
}

export function LoadingState({
  rows = 4,
  announce,
}: {
  rows?: number
  /*
   * Optional, caller-supplied localized announcement. When supplied,
   * LoadingState renders an sr-only `role="status" aria-live="polite"`
   * node carrying the string; when omitted (the legacy RouteFallback
   * path), no announcement is emitted, so nesting LoadingState inside an
   * already-announcing ancestor never duplicates the message.
   */
  announce?: string
}) {
  return (
    <div className="space-y-3" data-testid="loading-state">
      {announce ? (
        <span role="status" aria-live="polite" className="sr-only">
          {announce}
        </span>
      ) : null}
      {Array.from({ length: rows }, (_, i) => (
        <Skeleton key={i} className="h-10 w-full" />
      ))}
    </div>
  )
}

export function EmptyState({
  icon,
  title,
  body,
  action,
}: {
  icon?: ReactNode
  title: string
  body?: string
  action?: ReactNode
}) {
  return (
    <div
      data-testid="empty-state"
      className="mx-auto flex max-w-sm flex-col items-center gap-2 py-10 text-center"
    >
      {icon ? (
        <div className="text-muted-foreground mb-1 flex size-11 items-center justify-center rounded-full bg-muted [&>svg]:size-5" aria-hidden="true">
          {icon}
        </div>
      ) : null}
      <p className="text-foreground font-medium">{title}</p>
      {body ? <p className="text-muted-foreground text-sm">{body}</p> : null}
      {action ? <div className="mt-1">{action}</div> : null}
    </div>
  )
}

export function DeniedState({ locale }: { locale: Locale }) {
  return <EmptyState title={DENIED[locale]} />
}

export function ConflictState({ onRetry, locale }: { onRetry?: () => void; locale: Locale }) {
  return (
    <Alert variant="destructive">
      <CircleAlert className="size-4" aria-hidden="true" />
      <AlertTitle>{CONFLICT[locale]}</AlertTitle>
      <AlertDescription>
        {onRetry ? (
          <Button variant="outline" size="sm" onClick={onRetry} className="mt-2">
            <RefreshCw aria-hidden="true" />
            {TRY_AGAIN[locale]}
          </Button>
        ) : null}
      </AlertDescription>
    </Alert>
  )
}

export function StaleState({ onRefresh, locale }: { onRefresh?: () => void; locale: Locale }) {
  return (
    <Alert>
      <RefreshCw className="size-4" aria-hidden="true" />
      <AlertTitle>{STALE[locale]}</AlertTitle>
      <AlertDescription>
        {onRefresh ? (
          <Button variant="outline" size="sm" onClick={onRefresh} className="mt-2">
            {REFRESH[locale]}
          </Button>
        ) : null}
      </AlertDescription>
    </Alert>
  )
}

export function ErrorState({
  onRetry,
  correlationId,
  locale,
}: {
  onRetry?: () => void
  correlationId?: string | null
  locale: Locale
}) {
  return (
    <Alert variant="destructive">
      <CircleAlert className="size-4" aria-hidden="true" />
      <AlertTitle>{ERROR_TITLE[locale]}</AlertTitle>
      <AlertDescription>
        {correlationId ? (
          <p className="text-muted-foreground font-mono text-xs" dir="ltr">
            {correlationId}
          </p>
        ) : null}
        {onRetry ? (
          <Button variant="outline" size="sm" onClick={onRetry} className="mt-2">
            <RotateCcw aria-hidden="true" />
            {TRY_AGAIN[locale]}
          </Button>
        ) : null}
      </AlertDescription>
    </Alert>
  )
}

export function ResourceBoundary({
  state,
  locale,
  onRetry,
  onRefresh,
  correlationId,
  empty,
  rows = 4,
  children,
}: {
  state: ResourceState
  locale: Locale
  onRetry?: () => void
  onRefresh?: () => void
  correlationId?: string | null
  empty?: ReactNode
  rows?: number
  children: ReactNode
}) {
  switch (state) {
    case 'loading':
      return <LoadingState rows={rows} announce={LOADING[locale]} />
    case 'ready':
      return children
    case 'empty':
      return empty
    case 'forbidden':
    case 'not-found':
      return <DeniedState locale={locale} />
    case 'conflict':
      return <ConflictState onRetry={onRetry} locale={locale} />
    case 'stale':
      return <StaleState onRefresh={onRefresh} locale={locale} />
    case 'error':
      return <ErrorState onRetry={onRetry} correlationId={correlationId} locale={locale} />
  }
}

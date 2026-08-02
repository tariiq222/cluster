import { useQuery } from '@tanstack/react-query'
import { ArrowLeft, ArrowRight } from 'lucide-react'
import * as generated from '../../api/generated/cluster'
import type { AuditEvent } from '../../api/generated/cluster'
import { ApiError, requestInit, stateFromError, unwrap } from '../../api/http'
import { useNavigate } from '../../app/navigation-context'
import { usePrincipal } from '../../app/principal-context'
import { useLocale, useSessionToken } from '../../app/session-context'
import { formatDate } from '../../i18n'
import { PageHeader, PageLayout } from '@/components/page-layout'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { DeniedState, ErrorState, LoadingState } from '@/components/states'
import { auditCopy } from './audit-copy'
import { redactedContextEntries } from './audit-utils'

/*
 * Full-page audit event detail (route `/reports/audit/events/:eventId`),
 * the successor of the former detail Sheet. The event is fetched by id
 * directly; 403 and 404 collapse into the same non-disclosing DeniedState.
 * The redaction helpers from audit-utils keep hash / key / fingerprint /
 * secret material out of the DOM at every nesting depth.
 */
export function AuditEventDetailScreen({ eventId }: { eventId: string }) {
  const locale = useLocale()
  const csrfToken = useSessionToken()
  const principal = usePrincipal()
  const navigate = useNavigate()
  const t = auditCopy[locale]
  const canRead = principal.capabilities?.includes('audit.event.read') ?? false

  const eventQuery = useQuery({
    queryKey: ['audit-event', eventId] as const,
    queryFn: async () => unwrap<AuditEvent>(await generated.getAuditEvent(eventId, requestInit(csrfToken))),
    enabled: canRead,
  })

  const derived = eventQuery.isError ? stateFromError(eventQuery.error) : null

  if (!canRead) {
    // The capability gate mirrors the ledger: no fetch, and the shared
    // non-disclosing copy. The server is the only guard.
    return (
      <PageLayout data-testid="audit-event-detail-screen">
        <DeniedState locale={locale} />
      </PageLayout>
    )
  }

  if (eventQuery.isLoading) {
    return (
      <PageLayout data-testid="audit-event-detail-screen">
        <LoadingState rows={4} announce={t.loading} />
      </PageLayout>
    )
  }

  if (eventQuery.isError && (derived === 'forbidden' || derived === 'not-found')) {
    // 403 and 404 collapse into the same shared, non-disclosing copy.
    return (
      <PageLayout data-testid="audit-event-detail-screen">
        <DeniedState locale={locale} />
      </PageLayout>
    )
  }

  if (eventQuery.isError || !eventQuery.data) {
    return (
      <PageLayout data-testid="audit-event-detail-screen">
        <ErrorState
          locale={locale}
          onRetry={() => void eventQuery.refetch()}
          correlationId={
            eventQuery.error instanceof ApiError ? eventQuery.error.correlationId : null
          }
        />
      </PageLayout>
    )
  }

  return (
    <PageLayout data-testid="audit-event-detail-screen">
      <div>
        <Button
          variant="ghost"
          size="sm"
          onClick={() => navigate('/reports?tab=audit')}
          className="-ms-2"
        >
          {locale === 'ar' ? (
            <ArrowRight aria-hidden="true" />
          ) : (
            <ArrowLeft aria-hidden="true" />
          )}
          {t.backToLedger}
        </Button>
      </div>

      <PageHeader title={t.eventDetail} description={t.redacted} />

      <div className="mx-auto w-full max-w-2xl" data-testid="audit-event-detail">
        <AuditEventDetail event={eventQuery.data} locale={locale} />
      </div>
    </PageLayout>
  )
}

export function AuditEventDetail({ event, locale }: { event: AuditEvent; locale: 'ar' | 'en' }) {
  const t = auditCopy[locale]
  const facts: Array<[string, string]> = [
    [t.eventId, event.event_id],
    [t.correlationId, event.correlation_id],
    [t.eventType, event.event_type],
    [t.occurred, formatDate(event.occurred_at, locale)],
    [t.recorded, formatDate(event.recorded_at, locale)],
    [t.retention, formatDate(event.retention_until, locale)],
    [t.accessDecision, event.access_decision_id ?? t.notAvailable],
    [t.actor, `${event.actor_type}${event.actor_id ? ` · ${event.actor_id}` : ` · ${t.system}`}`],
    [t.subject, `${event.subject_type}${event.subject_id ? ` · ${event.subject_id}` : ` · ${t.notAvailable}`}`],
  ]
  const contextEntries = redactedContextEntries(event.context)
  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-2">
        <Badge variant="outline">{event.outcome}</Badge>
        <Badge variant="outline">{event.integrity_status}</Badge>
        <Badge variant="outline">{event.classification}</Badge>
      </div>
      <dl className="grid gap-3 sm:grid-cols-2">
        {facts.map(([label, value]) => (
          <div key={label} className="grid gap-0.5">
            <dt className="text-muted-foreground text-xs">{label}</dt>
            <dd className="break-words">{value}</dd>
          </div>
        ))}
      </dl>
      <section aria-labelledby="audit-context-title">
        <h3 className="text-base font-semibold" id="audit-context-title">
          {t.context}
        </h3>
        <dl className="grid gap-3 sm:grid-cols-2">
          {contextEntries.map(([key, value]) => (
            <div key={key} className="grid gap-0.5">
              <dt className="text-muted-foreground text-xs">{key}</dt>
              <dd className="break-words">{typeof value === 'string' ? value : JSON.stringify(value)}</dd>
            </div>
          ))}
        </dl>
      </section>
    </div>
  )
}

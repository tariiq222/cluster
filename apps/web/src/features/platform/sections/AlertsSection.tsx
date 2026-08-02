import { useCallback, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { BellRing, Pencil } from 'lucide-react'
import { useLocale, useSessionToken } from '../../../app/session-context'
import { useNavigate } from '../../../app/navigation-context'
import { ApiError } from '../../../api/http'
import { listPlatformAlertPolicies, updatePlatformAlertPolicy } from '../platform-api'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Switch } from '@/components/ui/switch'
import { EmptyState } from '@/components/states'
import { actionAllowed, isEmptyCollection, queryResourceState } from '../section-support'
import { SectionBoundary, ActionNotice, ActionError } from '../section-state'
import { alertsCopy, logsCopy, platformCopy, t } from '../platform-copy'
import type { PlatformAlertPolicy, PlatformAlertPolicyList } from '../platform-types'

function isAlertsEmpty(payload: PlatformAlertPolicyList): boolean {
  return isEmptyCollection(payload.items)
}

function severityLabel(severity: string, locale: 'ar' | 'en'): string {
  const labels = logsCopy.severities as Record<string, { ar: string; en: string }>
  const entry = labels[severity]
  return entry !== undefined ? t(entry, locale) : severity
}

export function AlertsSection() {
  const locale = useLocale()
  const csrfToken = useSessionToken()
  const queryClient = useQueryClient()
  const navigate = useNavigate()
  const [actionError, setActionError] = useState<string | null>(null)
  const [actionNotice, setActionNotice] = useState<string | null>(null)

  const policiesQuery = useQuery({
    queryKey: ['platform-alert-policies'],
    queryFn: () => listPlatformAlertPolicies(csrfToken),
  })

  const data: PlatformAlertPolicyList | null = policiesQuery.data ?? null
  const state = queryResourceState(
    { isPending: policiesQuery.isPending, error: policiesQuery.error, data },
    isAlertsEmpty,
  )

  const reload = useCallback(() => {
    void policiesQuery.refetch()
  }, [policiesQuery])

  const fail = useCallback((error: unknown) => {
    if (error instanceof ApiError && error.status === 412) {
      setActionNotice(t(alertsCopy.stale, locale))
      reload()
      return
    }
    setActionError(error instanceof ApiError ? error.problem.title : t(platformCopy.error, locale))
  }, [locale, reload])

  const updateMutation = useMutation({
    mutationFn: (input: { policy: PlatformAlertPolicy; body: { status: string; severity: string; channel: string } }) =>
      updatePlatformAlertPolicy(csrfToken, input.policy.id, input.body, input.policy.lock_version),
    onMutate: () => {
      setActionError(null)
      setActionNotice(null)
    },
    onSuccess: () => {
      setActionNotice(t(platformCopy.refreshed, locale))
      void queryClient.invalidateQueries({ queryKey: ['platform-alert-policies'] })
    },
    onError: fail,
  })

  const togglePolicy = useCallback(
    (policy: PlatformAlertPolicy) => {
      const nextStatus = policy.status === 'enabled' ? 'disabled' : 'enabled'
      updateMutation.mutate({
        policy,
        body: { status: nextStatus, severity: policy.severity, channel: policy.channel },
      })
    },
    [updateMutation],
  )

  if (state !== 'ready' || data === null) {
    return (
      <SectionBoundary
        state={state}
        locale={locale}
        onRetry={reload}
        empty={
          <EmptyState
            icon={<BellRing />}
            title={t(alertsCopy.empty, locale)}
            body={t(alertsCopy.emptyBody, locale)}
          />
        }
      />
    )
  }

  return (
    <section aria-labelledby="platform-alerts-title" className="space-y-4">
      <div>
        <h2 id="platform-alerts-title" className="text-xl font-semibold tracking-tight">
          {t(alertsCopy.title, locale)}
        </h2>
        <p className="text-muted-foreground text-sm">{t(alertsCopy.description, locale)}</p>
      </div>

      <Card>
        <CardHeader className="flex flex-row items-center gap-2">
          <BellRing className="size-4 text-muted-foreground" aria-hidden="true" />
          <CardTitle>{t(alertsCopy.title, locale)}</CardTitle>
        </CardHeader>
        <CardContent>
          <ul className="divide-y">
            {data.items.map((policy) => (
              <li key={policy.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
                <div className="min-w-0">
                  <p className="text-sm font-medium">{policy.code}</p>
                  <p className="text-muted-foreground text-xs">
                    {t(alertsCopy.severity, locale)}: {severityLabel(policy.severity, locale)} ·{' '}
                    {t(alertsCopy.channel, locale)}: {policy.channel}
                  </p>
                </div>
                <div className="flex items-center gap-3">
                  <Badge variant="outline" className="gap-1">
                    {policy.status === 'enabled'
                      ? t(alertsCopy.enabled, locale)
                      : t(alertsCopy.disabled, locale)}
                  </Badge>
                  {actionAllowed(policy.allowed_actions, 'platform_operations.alerts.manage') && (
                    <>
                      <Switch
                        checked={policy.status === 'enabled'}
                        disabled={updateMutation.isPending}
                        onCheckedChange={() => togglePolicy(policy)}
                        aria-label={`${policy.code} ${t(alertsCopy.enabled, locale)}`}
                      />
                      <Button
                        variant="outline"
                        size="icon-sm"
                        disabled={updateMutation.isPending}
                        onClick={() => navigate(`/platform/alerts/${policy.id}/edit`)}
                        aria-label={`${t(alertsCopy.edit, locale)} ${policy.code}`}
                      >
                        <Pencil className="size-3.5" aria-hidden="true" />
                      </Button>
                    </>
                  )}
                </div>
              </li>
            ))}
          </ul>
        </CardContent>
      </Card>

      {actionNotice && <ActionNotice message={actionNotice} />}
      {actionError && <ActionError message={actionError} />}
    </section>
  )
}

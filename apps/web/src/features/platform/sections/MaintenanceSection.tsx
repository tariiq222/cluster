import { useCallback, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CalendarX2, Wrench } from 'lucide-react'
import { useLocale, useSessionToken } from '../../../app/session-context'
import { useNavigate } from '../../../app/navigation-context'
import { formatDate } from '../../../i18n'
import { ApiError } from '../../../api/http'
import { cancelPlatformMaintenanceWindow, listPlatformMaintenanceWindows } from '../platform-api'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog'
import { EmptyState } from '@/components/states'
import { actionAllowed, isEmptyCollection, queryResourceState } from '../section-support'
import { SectionBoundary, ActionNotice, ActionError } from '../section-state'
import { maintenanceCopy, platformCopy, t } from '../platform-copy'
import type { PlatformMaintenanceWindow, PlatformMaintenanceWindowList } from '../platform-types'

function isMaintenanceEmpty(payload: PlatformMaintenanceWindowList): boolean {
  return isEmptyCollection(payload.items)
}

function statusText(status: string, locale: 'ar' | 'en'): string {
  switch (status) {
    case 'scheduled': return t(maintenanceCopy.scheduled, locale)
    case 'active': return t(maintenanceCopy.active, locale)
    case 'cancelled': return t(maintenanceCopy.cancelled, locale)
    case 'ended': return t(maintenanceCopy.ended, locale)
    default: return status
  }
}

export function MaintenanceSection() {
  const locale = useLocale()
  const csrfToken = useSessionToken()
  const queryClient = useQueryClient()
  const navigate = useNavigate()
  const [actionError, setActionError] = useState<string | null>(null)
  const [actionNotice, setActionNotice] = useState<string | null>(null)
  const [cancelling, setCancelling] = useState<PlatformMaintenanceWindow | null>(null)

  const windowsQuery = useQuery({
    queryKey: ['platform-maintenance'],
    queryFn: () => listPlatformMaintenanceWindows(csrfToken),
  })

  const data: PlatformMaintenanceWindowList | null = windowsQuery.data ?? null
  const state = queryResourceState(
    { isPending: windowsQuery.isPending, error: windowsQuery.error, data },
    isMaintenanceEmpty,
  )

  const reload = useCallback(() => {
    void windowsQuery.refetch()
  }, [windowsQuery])

  const canManage = data !== null && data.items.some((window) =>
    actionAllowed(window.allowed_actions, 'platform_operations.maintenance.manage'),
  )

  const fail = useCallback((error: unknown) => {
    setActionError(error instanceof ApiError ? error.problem.title : t(platformCopy.error, locale))
  }, [locale])

  const cancelMutation = useMutation({
    mutationFn: (window: PlatformMaintenanceWindow) => cancelPlatformMaintenanceWindow(csrfToken, window.id, window.lock_version),
    onMutate: () => {
      setActionError(null)
      setActionNotice(null)
    },
    onSuccess: () => {
      setCancelling(null)
      setActionNotice(t(maintenanceCopy.cancelled, locale))
      void queryClient.invalidateQueries({ queryKey: ['platform-maintenance'] })
    },
    onError: (error) => {
      setCancelling(null)
      fail(error)
    },
  })

  const actionBusy = cancelMutation.isPending

  if (state !== 'ready' || data === null) {
    return (
      <SectionBoundary
        state={state}
        locale={locale}
        onRetry={reload}
        empty={<EmptyState title={t(maintenanceCopy.empty, locale)} />}
      />
    )
  }

  return (
    <section aria-labelledby="platform-maintenance-title" className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h2 id="platform-maintenance-title" className="text-xl font-semibold tracking-tight">
          {t(maintenanceCopy.title, locale)}
        </h2>
        {canManage && (
          <Button variant="outline" size="sm" disabled={actionBusy} onClick={() => navigate('/platform/maintenance/new')}>
            <Wrench className="size-4" aria-hidden="true" />
            {t(maintenanceCopy.schedule, locale)}
          </Button>
        )}
      </div>

      <Card>
        <CardHeader className="flex flex-row items-center gap-2">
          <CalendarX2 className="size-4 text-muted-foreground" aria-hidden="true" />
          <CardTitle>{t(maintenanceCopy.title, locale)}</CardTitle>
        </CardHeader>
        <CardContent>
          {data.items.length === 0 ? (
            <p className="text-sm text-muted-foreground">{t(maintenanceCopy.empty, locale)}</p>
          ) : (
            <ul className="divide-y">
              {data.items.map((window) => (
                <li key={window.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
                  <div className="min-w-0">
                    <p className="text-sm font-medium">{locale === 'ar' ? window.message_ar : window.message_en}</p>
                    <p className="text-muted-foreground text-xs" dir="ltr">
                      {formatDate(window.starts_at, locale)}
                      {window.ends_at ? ` — ${formatDate(window.ends_at, locale)}` : ''}
                    </p>
                  </div>
                  <div className="flex items-center gap-2">
                    <Badge variant={window.status === 'active' ? 'secondary' : 'outline'}>
                      {statusText(window.status, locale)}
                    </Badge>
                    {(window.status === 'scheduled' || window.status === 'active') &&
                      actionAllowed(window.allowed_actions, 'platform_operations.maintenance.cancel') && (
                        <Button
                          variant="outline"
                          size="sm"
                          disabled={actionBusy}
                          onClick={() => setCancelling(window)}
                        >
                          {t(maintenanceCopy.cancel, locale)}
                        </Button>
                      )}
                  </div>
                </li>
              ))}
            </ul>
          )}
        </CardContent>
      </Card>

      {actionNotice && <ActionNotice message={actionNotice} />}
      {actionError && <ActionError message={actionError} />}

      <AlertDialog open={cancelling !== null} onOpenChange={(open) => { if (!open) setCancelling(null) }}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t(maintenanceCopy.cancelConfirmTitle, locale)}</AlertDialogTitle>
            <AlertDialogDescription>{t(maintenanceCopy.cancelConfirmBody, locale)}</AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={actionBusy}>{t(platformCopy.cancel, locale)}</AlertDialogCancel>
            <AlertDialogAction
              variant="destructive"
              disabled={actionBusy}
              onClick={(event) => {
                event.preventDefault()
                if (cancelling !== null) cancelMutation.mutate(cancelling)
              }}
            >
              {t(maintenanceCopy.cancel, locale)}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </section>
  )
}

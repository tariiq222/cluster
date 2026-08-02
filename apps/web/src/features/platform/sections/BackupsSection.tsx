import { useCallback, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Database, Play } from 'lucide-react'
import { useLocale, useSessionToken } from '../../../app/session-context'
import { formatDate } from '../../../i18n'
import { ApiError } from '../../../api/http'
import { dispatchPlatformBackup, getPlatformBackups } from '../platform-api'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
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
import { actionAllowed, queryResourceState } from '../section-support'
import { SectionBoundary, ActionNotice, ActionError } from '../section-state'
import { backupsCopy, platformCopy, t } from '../platform-copy'
import type { PlatformBackupReport } from '../platform-types'

export function BackupsSection() {
  const locale = useLocale()
  const csrfToken = useSessionToken()
  const queryClient = useQueryClient()
  const [actionError, setActionError] = useState<string | null>(null)
  const [actionNotice, setActionNotice] = useState<string | null>(null)
  const [confirmOpen, setConfirmOpen] = useState(false)

  const backupsQuery = useQuery({
    queryKey: ['platform-backups'],
    queryFn: () => getPlatformBackups(csrfToken),
  })

  const data: PlatformBackupReport | null = backupsQuery.data ?? null
  const state = queryResourceState(
    { isPending: backupsQuery.isPending, error: backupsQuery.error, data },
    () => data === null,
  )

  const reload = useCallback(() => {
    void backupsQuery.refetch()
  }, [backupsQuery])

  const canRunBackup = actionAllowed(data?.allowed_actions, 'platform_operations.backup.run')

  const fail = useCallback((error: unknown) => {
    setActionError(error instanceof ApiError ? error.problem.title : t(platformCopy.error, locale))
  }, [locale])

  const runBackupMutation = useMutation({
    mutationFn: () => dispatchPlatformBackup(csrfToken),
    onMutate: () => {
      setActionError(null)
      setActionNotice(null)
    },
    onSuccess: () => {
      setConfirmOpen(false)
      setActionNotice(t(backupsCopy.requested, locale))
      void queryClient.invalidateQueries({ queryKey: ['platform-backups'] })
      void queryClient.invalidateQueries({ queryKey: ['platform-operations-overview'] })
    },
    onError: (error) => {
      setConfirmOpen(false)
      fail(error)
    },
  })

  if (state !== 'ready' || data === null) {
    return (
      <SectionBoundary
        state={state}
        locale={locale}
        onRetry={reload}
        empty={<EmptyState title={t(backupsCopy.empty, locale)} />}
      />
    )
  }

  return (
    <section aria-labelledby="platform-backups-title" className="space-y-4">
      <div className="flex items-center justify-between gap-2">
        <h2 id="platform-backups-title" className="text-xl font-semibold tracking-tight">
          {t(backupsCopy.status, locale)}
        </h2>
        {canRunBackup && (
          <Button variant="outline" size="sm" disabled={runBackupMutation.isPending} onClick={() => setConfirmOpen(true)}>
            <Play className="size-4" aria-hidden="true" />
            {t(backupsCopy.runBackup, locale)}
          </Button>
        )}
      </div>

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 gap-2">
            <CardTitle className="text-sm font-medium">{t(backupsCopy.status, locale)}</CardTitle>
            <Database className="size-4 text-muted-foreground" aria-hidden="true" />
          </CardHeader>
          <CardContent>
            <p className="text-2xl font-semibold">{data.status}</p>
          </CardContent>
        </Card>
        <Card>
          <CardHeader>
            <CardTitle className="text-sm font-medium">{t(backupsCopy.lastSuccessful, locale)}</CardTitle>
          </CardHeader>
          <CardContent>
            <p className="text-sm">{formatDate(data.last_successful_at, locale) || '—'}</p>
          </CardContent>
        </Card>
        <Card>
          <CardHeader>
            <CardTitle className="text-sm font-medium">{t(backupsCopy.lastFailed, locale)}</CardTitle>
          </CardHeader>
          <CardContent>
            <p className="text-sm">{formatDate(data.last_failed_at, locale) || '—'}</p>
          </CardContent>
        </Card>
        <Card>
          <CardHeader>
            <CardTitle className="text-sm font-medium">{t(backupsCopy.lastValidation, locale)}</CardTitle>
          </CardHeader>
          <CardContent>
            <p className="text-sm">{formatDate(data.last_validation_at, locale) || '—'}</p>
          </CardContent>
        </Card>
      </div>

      {actionNotice && <ActionNotice message={actionNotice} />}
      {actionError && <ActionError message={actionError} />}

      <AlertDialog open={confirmOpen} onOpenChange={setConfirmOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t(backupsCopy.runBackup, locale)}</AlertDialogTitle>
            <AlertDialogDescription>
              {t(backupsCopy.requested, locale)}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={runBackupMutation.isPending}>{t(platformCopy.cancel, locale)}</AlertDialogCancel>
            <AlertDialogAction variant="default" disabled={runBackupMutation.isPending} onClick={() => runBackupMutation.mutate()}>
              {t(backupsCopy.runBackup, locale)}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </section>
  )
}

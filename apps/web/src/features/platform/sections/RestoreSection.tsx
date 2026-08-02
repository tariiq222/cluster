import { useCallback, useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { Database, ShieldAlert } from 'lucide-react'
import { useLocale, useSessionToken } from '../../../app/session-context'
import { ApiError } from '../../../api/http'
import { confirmPlatformRestore, requestPlatformRestore } from '../platform-api'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
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
import { ActionNotice, ActionError } from '../section-state'
import { platformCopy, restoreCopy, t } from '../platform-copy'

/*
 * The most dangerous action in the product. Two mandatory steps:
 *  1. Request: the operator supplies the backup identifier and a reason.
 *  2. Confirm: a separate AlertDialog that requires TYPING the exact backup
 *     name — the confirm control stays disabled until the typed value
 *     equals the requested backup identifier. CSRF, Idempotency-Key and the
 *     two-actor rule flow through the existing `requestPlatformRestore` /
 *     `confirmPlatformRestore` API layers.
 *
 * Contract gap: the API exposes no endpoint that lists backups with their
 * identifiers (`GET /platform-operations/backups` returns a status report
 * only), so the section cannot render a real backup picker. The operator
 * enters the backup identifier manually; no list data is invented.
 */
export function RestoreSection() {
  const locale = useLocale()
  const csrfToken = useSessionToken()
  const [backupName, setBackupName] = useState('')
  const [reason, setReason] = useState('')
  const [typedName, setTypedName] = useState('')
  const [pendingRequestId, setPendingRequestId] = useState<string | null>(null)
  const [confirmOpen, setConfirmOpen] = useState(false)
  const [actionError, setActionError] = useState<string | null>(null)
  const [actionNotice, setActionNotice] = useState<string | null>(null)

  const fail = useCallback((error: unknown) => {
    setActionError(error instanceof ApiError ? error.problem.title : t(platformCopy.error, locale))
  }, [locale])

  const requestMutation = useMutation({
    mutationFn: () =>
      requestPlatformRestore(csrfToken, { backup_id: backupName.trim(), reason: reason.trim() }),
    onMutate: () => {
      setActionError(null)
      setActionNotice(null)
    },
    onSuccess: (result) => {
      setPendingRequestId(result.operation_id)
      setTypedName('')
      setConfirmOpen(true)
      setActionNotice(t(restoreCopy.requested, locale))
    },
    onError: fail,
  })

  const confirmMutation = useMutation({
    mutationFn: () => {
      if (pendingRequestId === null) throw new Error('No pending restore request')
      return confirmPlatformRestore(csrfToken, pendingRequestId)
    },
    onMutate: () => {
      setActionError(null)
      setActionNotice(null)
    },
    onSuccess: () => {
      setConfirmOpen(false)
      setPendingRequestId(null)
      setActionNotice(t(restoreCopy.confirmed, locale))
    },
    onError: (error) => {
      setConfirmOpen(false)
      fail(error)
    },
  })

  const requestValid = backupName.trim() !== '' && reason.trim().length >= 10
  const exactNameTyped = typedName.trim() === backupName.trim()
  const busy = requestMutation.isPending || confirmMutation.isPending

  const submitRequest = useCallback(() => {
    requestMutation.mutate()
  }, [requestMutation])

  const confirmRestore = useCallback(() => {
    confirmMutation.mutate()
  }, [confirmMutation])

  return (
    <section aria-labelledby="platform-restore-title" className="space-y-4">
      <div>
        <h2 id="platform-restore-title" className="text-xl font-semibold tracking-tight">
          {t(restoreCopy.title, locale)}
        </h2>
        <p className="text-muted-foreground text-sm">{t(restoreCopy.description, locale)}</p>
      </div>

      <Card className="max-w-2xl">
        <CardHeader className="flex flex-row items-center gap-2">
          <ShieldAlert className="size-4 text-muted-foreground" aria-hidden="true" />
          <CardTitle>{t(restoreCopy.request, locale)}</CardTitle>
        </CardHeader>
        <CardContent className="grid gap-4">
          <div className="grid gap-2">
            <Label htmlFor="platform-restore-backup-name">{t(restoreCopy.backupName, locale)}</Label>
            <Input
              id="platform-restore-backup-name"
              value={backupName}
              disabled={busy}
              onChange={(event) => setBackupName(event.currentTarget.value)}
            />
            <CardDescription>{t(restoreCopy.backupNameHelp, locale)}</CardDescription>
          </div>
          <div className="grid gap-2">
            <Label htmlFor="platform-restore-reason">{t(restoreCopy.reason, locale)}</Label>
            <Textarea
              id="platform-restore-reason"
              rows={4}
              value={reason}
              disabled={busy}
              onChange={(event) => setReason(event.currentTarget.value)}
            />
            <CardDescription>{t(restoreCopy.reasonHelp, locale)}</CardDescription>
          </div>
          <div className="flex justify-end">
            <Button type="button" disabled={!requestValid || busy} onClick={() => void submitRequest()}>
              <Database className="size-4" aria-hidden="true" />
              {t(restoreCopy.request, locale)}
            </Button>
          </div>
        </CardContent>
      </Card>

      {actionNotice && <ActionNotice message={actionNotice} />}
      {actionError && <ActionError message={actionError} />}

      <AlertDialog open={confirmOpen} onOpenChange={(open) => { setConfirmOpen(open); if (!open) setPendingRequestId(null) }}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t(restoreCopy.confirmTitle, locale)}</AlertDialogTitle>
            <AlertDialogDescription>
              {t(restoreCopy.confirmDescription, locale)}
              <span className="mt-2 block">
                <ShieldAlert className="me-1 inline size-4" aria-hidden="true" />
                {t(restoreCopy.twoActor, locale)}
              </span>
            </AlertDialogDescription>
          </AlertDialogHeader>
          <div className="grid gap-2">
            <Label htmlFor="platform-restore-confirm-name">{t(restoreCopy.confirmName, locale)}</Label>
            <Input
              id="platform-restore-confirm-name"
              value={typedName}
              disabled={busy}
              onChange={(event) => setTypedName(event.currentTarget.value)}
            />
            <CardDescription>{t(restoreCopy.confirmNameHelp, locale)}</CardDescription>
          </div>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={busy}>{t(restoreCopy.cancel, locale)}</AlertDialogCancel>
            <AlertDialogAction
              variant="destructive"
              disabled={!exactNameTyped || busy}
              onClick={(event) => {
                event.preventDefault()
                void confirmRestore()
              }}
            >
              {t(restoreCopy.confirm, locale)}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </section>
  )
}

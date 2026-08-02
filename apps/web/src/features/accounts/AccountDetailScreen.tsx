import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, ArrowRight } from 'lucide-react'
import { useLocale, useSessionToken } from '../../app/session-context'
import { usePrincipal } from '../../app/principal-context'
import { useNavigate } from '../../app/navigation-context'
import { ApiError, stateFromError } from '../../api/http'
import * as generated from '../../api/generated/cluster'
import * as access from '../../api/access'
import { formatDate } from '../../i18n'
import { PageHeader, PageLayout } from '@/components/page-layout'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
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
import {
  Dialog,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { DeniedState, ErrorState, LoadingState } from '@/components/states'
import { accountCopy, accountsCopy } from './accounts-copy'
import { AccessDialogSurface } from './access-overlays'

type AccountAction =
  | 'activate'
  | 'unlock'
  | 'disable'
  | 'archive'
  | 'revoke-sessions'
  | 'force-password-change'

const ACTIONS_BY_STATUS: Record<
  string,
  Array<{
    action: AccountAction
    label: keyof typeof accountCopy.ar
    hint: keyof typeof accountCopy.ar
  }>
> = {
  pending: [
    { action: 'activate', label: 'activate', hint: 'activateHint' },
    { action: 'archive', label: 'archive', hint: 'archiveHint' },
  ],
  active: [
    { action: 'revoke-sessions', label: 'revokeSessions', hint: 'revokeSessionsHint' },
    { action: 'force-password-change', label: 'forcePasswordChange', hint: 'forcePasswordChangeHint' },
    { action: 'disable', label: 'disable', hint: 'disableHint' },
    { action: 'archive', label: 'archive', hint: 'archiveHint' },
  ],
  locked: [
    { action: 'unlock', label: 'unlock', hint: 'unlockHint' },
    { action: 'disable', label: 'disable', hint: 'disableHint' },
    { action: 'archive', label: 'archive', hint: 'archiveHint' },
  ],
  disabled: [
    { action: 'activate', label: 'activate', hint: 'activateHint' },
    { action: 'archive', label: 'archive', hint: 'archiveHint' },
  ],
  archived: [],
}

function accountStatusLabel(status: string, locale: 'ar' | 'en'): string {
  const key = status as keyof typeof accountCopy.ar
  return accountCopy[locale][key] ?? status
}

function personName(account: generated.UserAccount, locale: 'ar' | 'en'): string {
  return locale === 'en' && account.display_name_en
    ? account.display_name_en
    : account.display_name_ar
}

/*
 * Full-page replacement for the former manage-account Sheet
 * (route `/access/accounts/:accountId`). The page fetches the account by
 * id directly and renders the available lifecycle actions with their
 * AlertDialog confirmations; activation keeps the no-secret confirmation
 * Dialog.
 */
export function AccountDetailScreen({ accountId }: { accountId: string }) {
  const locale = useLocale()
  const csrfToken = useSessionToken()
  const principal = usePrincipal()
  const navigate = useNavigate()
  const text = accountCopy[locale]
  const queryClient = useQueryClient()
  const [reason, setReason] = useState('')
  const [confirming, setConfirming] = useState<AccountAction | null>(null)
  const [error, setError] = useState<'save' | 'stale' | null>(null)
  const [done, setDone] = useState(false)
  const [activation, setActivation] = useState<{
    account: generated.UserAccount
    issued: access.ActivationIssued
  } | null>(null)
  const [activationError, setActivationError] = useState<string | null>(null)

  const canManage = (principal.capabilities ?? []).includes('identity.account.manage')

  const accountQuery = useQuery({
    queryKey: ['account-detail', accountId] as const,
    queryFn: () => access.getAccount(accountId),
  })

  const transitionMutation = useMutation({
    mutationFn: async ({
      action,
      nextReason,
    }: {
      action: AccountAction
      nextReason?: string
    }) => {
      const fresh = await access.getAccount(accountId)
      return access.transitionAccount(
        accountId,
        action,
        nextReason || undefined,
        fresh.lock_version ?? 0,
        csrfToken,
      )
    },
    onSuccess: () => {
      setConfirming(null)
      setReason('')
      setError(null)
      setDone(true)
      void accountQuery.refetch()
      void queryClient.invalidateQueries({ queryKey: ['user-accounts'] })
    },
    onError: async (caught) => {
      setConfirming(null)
      if (caught instanceof ApiError && caught.status === 412) {
        setError('stale')
        await accountQuery.refetch()
      } else {
        setError('save')
      }
    },
  })

  const activateMutation = useMutation({
    mutationFn: async (account: generated.UserAccount) => {
      const issued = await access.issueAccountActivation(account.id, csrfToken)
      return { account, issued }
    },
    onSuccess: (result) => {
      setActivationError(null)
      setActivation(result)
    },
    onError: (caught) => {
      setActivationError(
        caught instanceof ApiError && caught.problem.title
          ? caught.problem.title
          : text.activationFailed,
      )
    },
  })

  const derived = accountQuery.isError ? stateFromError(accountQuery.error) : null
  const busy = transitionMutation.isPending || activateMutation.isPending

  if (accountQuery.isLoading) {
    return (
      <PageLayout data-testid="account-detail-screen">
        <LoadingState rows={3} announce={accountsCopy[locale].loading} />
      </PageLayout>
    )
  }

  if (
    accountQuery.isError &&
    (derived === 'forbidden' || derived === 'not-found')
  ) {
    return (
      <PageLayout data-testid="account-detail-screen">
        <DeniedState locale={locale} />
      </PageLayout>
    )
  }

  if (accountQuery.isError || !accountQuery.data) {
    return (
      <PageLayout data-testid="account-detail-screen">
        <ErrorState
          locale={locale}
          onRetry={() => void accountQuery.refetch()}
          correlationId={
            accountQuery.error instanceof ApiError
              ? accountQuery.error.correlationId
              : null
          }
        />
      </PageLayout>
    )
  }

  const account = accountQuery.data
  const available = ACTIONS_BY_STATUS[account.status] ?? []
  const confirmedAction =
    confirming !== null
      ? available.find((item) => item.action === confirming) ?? null
      : null

  const back = () => navigate('/access?tab=accounts')

  return (
    <PageLayout data-testid="account-detail-screen">
      <div>
        <Button variant="ghost" size="sm" onClick={back} className="-ms-2">
          {locale === 'ar' ? (
            <ArrowRight aria-hidden="true" />
          ) : (
            <ArrowLeft aria-hidden="true" />
          )}
          {text.backToAccounts}
        </Button>
      </div>

      <PageHeader
        title={text.manageAccount}
        description={personName(account, locale)}
        meta={<Badge variant="outline">{accountStatusLabel(account.status, locale)}</Badge>}
      />

      <div className="mx-auto w-full max-w-2xl space-y-6">
        <dl className="grid gap-2 text-sm">
          <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
            <dt className="text-muted-foreground">{text.username}</dt>
            <dd className="min-w-0 max-w-full break-all font-mono" dir="ltr">
              {account.username}
            </dd>
          </div>
          <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
            <dt className="text-muted-foreground">{text.status}</dt>
            <dd className="min-w-0 max-w-full">
              <Badge variant="outline">{accountStatusLabel(account.status, locale)}</Badge>
            </dd>
          </div>
        </dl>

        {account.must_change_password ? (
          <p className="text-muted-foreground text-sm" role="status">
            {text.mustChangePassword}
          </p>
        ) : null}

        {error === 'stale' ? (
          <Alert role="alert">
            <AlertTitle>{accountsCopy[locale].stale}</AlertTitle>
            <AlertDescription>
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="mt-2"
                disabled={busy}
                onClick={() => {
                  setError(null)
                  void accountQuery.refetch()
                }}
              >
                {accountsCopy[locale].retry}
              </Button>
            </AlertDescription>
          </Alert>
        ) : null}
        {error === 'save' ? (
          <p className="text-destructive text-sm" role="alert">{text.saveError}</p>
        ) : null}
        {activationError ? (
          <p className="text-destructive text-sm" role="alert">{activationError}</p>
        ) : null}
        {done ? (
          <p className="text-muted-foreground text-sm" role="status">
            {accountsCopy[locale].done}
          </p>
        ) : null}

        <section aria-labelledby="account-actions-heading" className="rounded-lg border p-4">
          <h2 id="account-actions-heading" className="text-base font-semibold">
            {text.actions}
          </h2>
          {!canManage ? (
            <p className="text-muted-foreground text-sm" role="status">
              {text.noActions}
            </p>
          ) : available.length === 0 ? (
            <p className="mt-2 text-muted-foreground text-sm" role="status">
              {text.noActions}
            </p>
          ) : (
            <div className="mt-3 grid gap-2">
              <label htmlFor="account-reason" className="text-sm font-medium">
                {text.reason}
              </label>
              <Input
                id="account-reason"
                value={reason}
                disabled={busy}
                onChange={(event) => setReason(event.target.value)}
                aria-describedby="account-reason-hint"
              />
              <p id="account-reason-hint" className="text-muted-foreground text-xs">
                {text.reasonHint}
              </p>
              <div className="mt-1 flex flex-wrap gap-2">
                {available.map((item) => (
                  <Button
                    key={item.action}
                    type="button"
                    variant="outline"
                    disabled={busy}
                    onClick={() => {
                      setError(null)
                      if (item.action === 'activate') {
                        activateMutation.mutate(account)
                      } else {
                        setConfirming(item.action)
                      }
                    }}
                  >
                    {text[item.label]}
                  </Button>
                ))}
              </div>
            </div>
          )}
        </section>

        <AlertDialog
          open={confirming !== null}
          onOpenChange={(next) => { if (!next && !busy) setConfirming(null) }}
        >
          <AlertDialogContent>
            <AlertDialogHeader>
              <AlertDialogTitle>
                {confirmedAction ? text[confirmedAction.label] : ''}
              </AlertDialogTitle>
              <AlertDialogDescription>
                {confirmedAction ? text[confirmedAction.hint] : ''}
              </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
              <AlertDialogCancel disabled={busy}>
                {accountsCopy[locale].cancel}
              </AlertDialogCancel>
              <AlertDialogAction
                disabled={busy}
                onClick={() => {
                  if (!confirming) return
                  transitionMutation.mutate({
                    action: confirming,
                    nextReason: reason,
                  })
                }}
              >
                {busy ? text.saving : confirmedAction ? text[confirmedAction.label] : ''}
              </AlertDialogAction>
            </AlertDialogFooter>
          </AlertDialogContent>
        </AlertDialog>

        <ActivationDialog
          activation={activation}
          onClose={() => setActivation(null)}
        />
      </div>
    </PageLayout>
  )
}

/*
 * The activation secret is delivered through the controlled channel and is
 * never exposed by this UI. Only the narrow `ActivationIssued` confirmation
 * (account label, controlled delivery, expiry) is rendered — an unexpected
 * `token` property is structurally inaccessible and ignored.
 */
function ActivationDialog({
  activation,
  onClose,
}: {
  activation: { account: generated.UserAccount; issued: access.ActivationIssued } | null
  onClose: () => void
}) {
  const locale = useLocale()
  const text = accountCopy[locale]
  if (!activation) return null
  const { account, issued } = activation

  return (
    <Dialog open onOpenChange={(next) => { if (!next) onClose() }}>
      <AccessDialogSurface>
        <DialogHeader>
          <DialogTitle>{text.activationIssued}</DialogTitle>
          <DialogDescription>{personName(account, locale)}</DialogDescription>
        </DialogHeader>
        <p className="text-sm">{text.activationControlledDelivery}</p>
        <p className="text-sm">
          {text.activationExpiryLabel}{' '}
          <span dir="ltr">{formatDate(issued.expires_at, locale)}</span>
        </p>
        <DialogFooter>
          <Button type="button" onClick={onClose}>
            {text.activationDismiss}
          </Button>
        </DialogFooter>
      </AccessDialogSurface>
    </Dialog>
  )
}

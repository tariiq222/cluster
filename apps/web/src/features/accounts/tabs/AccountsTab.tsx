import { useMemo, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import type { ColumnDef } from '@tanstack/react-table'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useLocale, useSessionToken } from '../../../app/session-context'
import { usePrincipal } from '../../../app/principal-context'
import { usePeople, useUserAccounts } from '../../../api/hooks'
import { ApiError, stateFromError, type ResourceState } from '../../../api/http'
import * as generated from '../../../api/generated/cluster'
import * as access from '../../../api/access'
import { formatDate } from '../../../i18n'
import { DataTable } from '@/components/data-table'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
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
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
} from '@/components/ui/sheet'
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/components/ui/form'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { accountCopy, accountsCopy } from '../accounts-copy'

/* ------------------------------------------------------------------ */
/* Accounts tab                                                        */
/* ------------------------------------------------------------------ */

const USERNAME_PATTERN = /^[a-zA-Z0-9._-]{3,128}$/

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
  pending: [{ action: 'archive', label: 'archive', hint: 'archiveHint' }],
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

export function AccountsTab() {
  const locale = useLocale()
  const csrfToken = useSessionToken()
  const principal = usePrincipal()
  const text = accountCopy[locale]
  const queryClient = useQueryClient()
  const [history, setHistory] = useState<string[]>([])
  const [addOpen, setAddOpen] = useState(false)
  const [managedId, setManagedId] = useState<string | null>(null)
  const [activation, setActivation] = useState<{
    account: generated.UserAccount
    issued: access.ActivationIssued
  } | null>(null)
  const [activationError, setActivationError] = useState<string | null>(null)

  const canManage = (principal.capabilities ?? []).includes('identity.account.manage')
  const cursor = history.length > 0 ? history[history.length - 1] : undefined
  const accountsQuery = useUserAccounts(cursor)
  const accounts =
    (accountsQuery.data as generated.UserAccountCollection | undefined)?.items ?? []
  const nextCursor =
    (accountsQuery.data as generated.UserAccountCollection | undefined)?.next_cursor ?? null
  const managed = accounts.find((account) => account.id === managedId) ?? null

  const state: ResourceState = accountsQuery.isLoading
    ? 'loading'
    : accountsQuery.isError
      ? stateFromError(accountsQuery.error)
      : accounts.length === 0
        ? 'empty'
        : 'ready'

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

  const columns: ColumnDef<generated.UserAccount>[] = [
    {
      accessorKey: 'display_name_ar',
      header: text.employee,
      cell: ({ row }) => (
        <span className="font-medium">{personName(row.original, locale)}</span>
      ),
    },
    {
      accessorKey: 'username',
      header: text.username,
      cell: ({ row }) => (
        <span className="font-mono text-sm" dir="ltr">{row.original.username}</span>
      ),
    },
    {
      accessorKey: 'status',
      header: text.status,
      cell: ({ row }) => (
        <Badge variant="outline">{accountStatusLabel(row.original.status, locale)}</Badge>
      ),
    },
    {
      accessorKey: 'id',
      header: text.actions,
      cell: ({ row }) => {
        const account = row.original
        return (
          <div className="flex items-center gap-2">
            {canManage && account.status === 'pending' ? (
              <Button
                size="sm"
                variant="outline"
                type="button"
                disabled={activateMutation.isPending}
                onClick={() => {
                  setActivationError(null)
                  activateMutation.mutate(account)
                }}
              >
                {text.activate}
              </Button>
            ) : null}
            {canManage ? (
              <Button
                size="sm"
                variant="ghost"
                type="button"
                onClick={() => setManagedId(account.id)}
              >
                {text.manage}
              </Button>
            ) : null}
          </div>
        )
      },
    },
  ]

  return (
    <div className="space-y-4">
      <h2 className="text-xl font-semibold tracking-tight">{text.accounts}</h2>
      <div className="flex justify-end">
        {canManage ? (
          <Button size="sm" onClick={() => setAddOpen(true)}>
            {text.addAccount}
          </Button>
        ) : null}
      </div>

      {activationError ? (
        <p className="text-destructive text-sm" role="alert">
          {activationError}
        </p>
      ) : null}

      <DataTable
        columns={columns}
        data={accounts}
        state={state}
        nextCursor={nextCursor}
        onNext={() => {
          if (nextCursor) setHistory((current) => [...current, nextCursor])
        }}
        onPrev={() => setHistory((current) => current.slice(0, -1))}
        canPrev={history.length > 0}
        locale={locale}
        empty={
          <div className="py-12 text-center">
            <p className="text-foreground font-medium">{text.noAccounts}</p>
            <p className="text-muted-foreground text-sm">{text.noAccountsBody}</p>
          </div>
        }
      />

      <CreateAccountSheet
        open={addOpen}
        onClose={() => setAddOpen(false)}
        onCreated={() => {
          setAddOpen(false)
          void queryClient.invalidateQueries({ queryKey: ['user-accounts'] })
        }}
      />

      <ManageAccountSheet
        account={managed}
        onClose={() => setManagedId(null)}
        onChanged={() => {
          void queryClient.invalidateQueries({ queryKey: ['user-accounts'] })
        }}
        onConflict={() => accountsQuery.refetch().then(() => undefined)}
      />

      <ActivationDialog
        activation={activation}
        onClose={() => setActivation(null)}
      />
    </div>
  )
}

/* ------------------------------------------------------------------ */
/* Create account (Sheet + react-hook-form + zod)                     */
/* ------------------------------------------------------------------ */

function CreateAccountSheet({
  open,
  onClose,
  onCreated,
}: {
  open: boolean
  onClose: () => void
  onCreated: () => void
}) {
  const locale = useLocale()
  const csrfToken = useSessionToken()
  const text = accountCopy[locale]
  const peopleQuery = usePeople()
  const people =
    (peopleQuery.data as { items: generated.Person[] } | undefined)?.items ?? []
  const [saveError, setSaveError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  const schema = useMemo(
    () =>
      z.object({
        personId: z.string().min(1, text.validation),
        username: z.string().regex(USERNAME_PATTERN, text.validation),
      }),
    [text],
  )

  const form = useForm<{ personId: string; username: string }>({
    resolver: zodResolver(schema),
    defaultValues: { personId: '', username: '' },
  })

  if (!open) return null

  return (
    <Sheet open onOpenChange={(next) => { if (!next && !submitting) onClose() }}>
      <SheetContent>
        <SheetHeader>
          <SheetTitle>{text.addAccountTitle}</SheetTitle>
          <SheetDescription>{text.addAccountIntro}</SheetDescription>
        </SheetHeader>
        {peopleQuery.isError ? (
          <p className="text-destructive text-sm" role="alert">{text.peopleError}</p>
        ) : people.length === 0 ? (
          <p className="text-muted-foreground text-sm">{text.peopleLoading}</p>
        ) : (
          <Form {...form}>
            <form
              className="grid gap-4"
              onSubmit={(event) => {
                event.preventDefault()
                setSaveError(null)
                void form.handleSubmit(async (values) => {
                  const person = people.find((item) => item.id === values.personId)
                  if (!person) return
                  setSubmitting(true)
                  try {
                    await access.createAccount(
                      {
                        person_id: person.id,
                        person_version: person.person_version,
                        username: values.username.trim(),
                      },
                      csrfToken,
                    )
                    form.reset()
                    onCreated()
                  } catch (cause) {
                    setSaveError(
                      cause instanceof ApiError && cause.status === 412
                        ? accountsCopy[locale].stale
                        : text.saveError,
                    )
                  } finally {
                    setSubmitting(false)
                  }
                })()
              }}
              noValidate
            >
              <FormField
                control={form.control}
                name="personId"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel htmlFor="account-person">{text.employee}</FormLabel>
                    <Select value={field.value} onValueChange={field.onChange}>
                      <FormControl>
                        <SelectTrigger id="account-person">
                          <SelectValue />
                        </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                        {people.map((person) => (
                          <SelectItem key={person.id} value={person.id}>
                            {locale === 'en' && person.display_name_en
                              ? person.display_name_en
                              : person.display_name_ar}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                    <FormMessage role="alert" />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="username"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel htmlFor="account-username">{text.username}</FormLabel>
                    <FormControl>
                      <Input id="account-username" dir="ltr" {...field} />
                    </FormControl>
                    <p className="text-muted-foreground text-xs">{text.usernameHint}</p>
                    <FormMessage role="alert" />
                  </FormItem>
                )}
              />
              {saveError ? (
                <p className="text-destructive text-sm" role="alert">{saveError}</p>
              ) : null}
              <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" onClick={onClose} disabled={submitting}>
                  {accountsCopy[locale].cancel}
                </Button>
                <Button type="submit" disabled={submitting}>
                  {submitting ? text.saving : text.create}
                </Button>
              </div>
            </form>
          </Form>
        )}
      </SheetContent>
    </Sheet>
  )
}

/* ------------------------------------------------------------------ */
/* Manage account (Sheet + AlertDialog-confirmed transitions)         */
/* ------------------------------------------------------------------ */

function ManageAccountSheet({
  account,
  onClose,
  onChanged,
  onConflict,
}: {
  account: generated.UserAccount | null
  onClose: () => void
  onChanged: () => void
  onConflict: () => Promise<void>
}) {
  const locale = useLocale()
  const csrfToken = useSessionToken()
  const text = accountCopy[locale]
  const [reason, setReason] = useState('')
  const [confirming, setConfirming] = useState<AccountAction | null>(null)
  const [error, setError] = useState<'save' | 'stale' | null>(null)
  const [done, setDone] = useState(false)

  const mutation = useMutation({
    mutationFn: async ({
      action,
      nextReason,
    }: {
      action: AccountAction
      nextReason?: string
    }) => {
      if (!account) throw new Error('Account is not available')
      const fresh = await access.getAccount(account.id)
      return access.transitionAccount(
        account.id,
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
      onChanged()
    },
    onError: async (caught) => {
      setConfirming(null)
      if (caught instanceof ApiError && caught.status === 412) {
        setError('stale')
        await onConflict()
      } else {
        setError('save')
      }
    },
  })

  if (!account) return null

  const available = ACTIONS_BY_STATUS[account.status] ?? []
  const busy = mutation.isPending
  const confirmedAction =
    confirming !== null
      ? available.find((item) => item.action === confirming) ?? null
      : null

  return (
    <Sheet open onOpenChange={(next) => { if (!next && !busy) onClose() }}>
      <SheetContent>
        <SheetHeader>
          <SheetTitle>{text.manageAccount}</SheetTitle>
          <SheetDescription>{personName(account, locale)}</SheetDescription>
        </SheetHeader>
        <dl className="grid gap-2 text-sm">
          <div className="flex justify-between gap-4">
            <dt className="text-muted-foreground">{text.username}</dt>
            <dd className="font-mono" dir="ltr">{account.username}</dd>
          </div>
          <div className="flex justify-between gap-4">
            <dt className="text-muted-foreground">{text.status}</dt>
            <dd>
              <Badge variant="outline">{accountStatusLabel(account.status, locale)}</Badge>
            </dd>
          </div>
        </dl>
        {account.must_change_password ? (
          <p className="text-muted-foreground text-sm" role="status">
            {text.mustChangePassword}
          </p>
        ) : null}
        {error ? (
          <p className="text-destructive text-sm" role="alert">
            {error === 'stale' ? accountsCopy[locale].stale : text.saveError}
          </p>
        ) : null}
        {done ? (
          <p className="text-muted-foreground text-sm" role="status">
            {accountsCopy[locale].done}
          </p>
        ) : null}
        {available.length === 0 ? (
          <p className="text-muted-foreground text-sm" role="status">{text.noActions}</p>
        ) : (
          <div className="grid gap-2">
            <label htmlFor="account-reason" className="text-sm font-medium">
              {text.reason}
            </label>
            <Input
              id="account-reason"
              value={reason}
              disabled={busy}
              onChange={(event) => setReason(event.target.value)}
            />
            <p className="text-muted-foreground text-xs">{text.reasonHint}</p>
            {available.map((item) => (
              <Button
                key={item.action}
                type="button"
                variant="outline"
                disabled={busy}
                onClick={() => setConfirming(item.action)}
              >
                {text[item.label]}
              </Button>
            ))}
          </div>
        )}

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
              <AlertDialogCancel disabled={busy}>{accountsCopy[locale].cancel}</AlertDialogCancel>
              <AlertDialogAction
                disabled={busy}
                onClick={() => {
                  if (!confirming) return
                  mutation.mutate({ action: confirming, nextReason: reason })
                }}
              >
                {busy ? text.saving : confirmedAction ? text[confirmedAction.label] : ''}
              </AlertDialogAction>
            </AlertDialogFooter>
          </AlertDialogContent>
        </AlertDialog>
      </SheetContent>
    </Sheet>
  )
}

/* ------------------------------------------------------------------ */
/* Activation confirmation (no-secret)                                */
/* ------------------------------------------------------------------ */

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
      <DialogContent>
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
      </DialogContent>
    </Dialog>
  )
}

import { useEffect, useRef, useState, type FormEvent } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import * as generated from '../../../api/generated/cluster'
import { ApiError, requestInit, stateFromError, unwrap } from '../../../api/http'
import { useLocale, useSessionToken } from '../../../app/session-context'
import { usePeople, useUserAccounts } from '../../../api/hooks'
import {
  Button,
  Drawer,
  EmptyState,
  Field,
  InlineError,
  Panel,
  Select,
  SkeletonList,
  StatusBadge,
} from '../../../ui'
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
    danger?: boolean
  }>
> = {
  pending: [
    { action: 'archive', label: 'archive', hint: 'archiveHint', danger: true },
  ],
  active: [
    {
      action: 'revoke-sessions',
      label: 'revokeSessions',
      hint: 'revokeSessionsHint',
    },
    {
      action: 'force-password-change',
      label: 'forcePasswordChange',
      hint: 'forcePasswordChangeHint',
    },
    { action: 'disable', label: 'disable', hint: 'disableHint' },
    { action: 'archive', label: 'archive', hint: 'archiveHint', danger: true },
  ],
  locked: [
    { action: 'unlock', label: 'unlock', hint: 'unlockHint' },
    { action: 'disable', label: 'disable', hint: 'disableHint' },
    { action: 'archive', label: 'archive', hint: 'archiveHint', danger: true },
  ],
  disabled: [
    { action: 'activate', label: 'activate', hint: 'activateHint' },
    { action: 'archive', label: 'archive', hint: 'archiveHint', danger: true },
  ],
  archived: [],
}

function accountStatusLabel(status: string, locale: 'ar' | 'en'): string {
  const key = status as keyof typeof accountCopy.ar
  return accountCopy[locale][key] ?? status
}

function personName(
  account: generated.UserAccount,
  locale: 'ar' | 'en',
): string {
  return locale === 'en' && account.display_name_en
    ? account.display_name_en
    : account.display_name_ar
}

export function AccountsTab() {
  const locale = useLocale()
  const text = accountCopy[locale]
  const accountsQuery = useUserAccounts()
  const [addOpen, setAddOpen] = useState(false)
  const [managedId, setManagedId] = useState<string | null>(null)

  const accounts =
    (accountsQuery.data as generated.UserAccountCollection | undefined)
      ?.items ?? []
  const loadState: 'loading' | 'ready' | 'forbidden' | 'error' =
    accountsQuery.isLoading
      ? 'loading'
      : accountsQuery.error
        ? stateFromError(accountsQuery.error) === 'forbidden'
          ? 'forbidden'
          : 'error'
        : 'ready'

  const managed = accounts.find((account) => account.id === managedId) ?? null

  if (loadState === 'loading') return <SkeletonList rows={4} />
  if (loadState === 'forbidden')
    return (
      <EmptyState
        title={accountsCopy[locale].unavailable}
        body={accountsCopy[locale].unavailableBody}
      />
    )
  if (loadState === 'error')
    return (
      <InlineError
        message={text.accountsError}
        retryLabel={accountsCopy[locale].retry}
        onRetry={() => void accountsQuery.refetch()}
      />
    )

  return (
    <Panel
      id="accounts-tab-panel"
      title={text.accounts}
      level={2}
      actions={
        <Button type="button" onClick={() => setAddOpen(true)}>
          {text.addAccount}
        </Button>
      }
    >
      {accounts.length === 0 ? (
        <EmptyState title={text.noAccounts} body={text.noAccountsBody} />
      ) : (
        <ul className="screen-list">
          {accounts.map((account) => (
            <li key={account.id} className="screen-list__row">
              <span className="screen-list__row-title">
                {personName(account, locale)}
              </span>
              <span className="screen-list__row-meta" dir="ltr">
                {account.username}
              </span>
              <span className="screen-list__row-meta">
                <StatusBadge
                  variant={
                    account.status === 'active'
                      ? 'success'
                      : account.status === 'archived'
                        ? 'neutral'
                        : 'warning'
                  }
                >
                  {accountStatusLabel(account.status, locale)}
                </StatusBadge>
              </span>
              <span className="screen-list__row-actions">
                <Button
                  variant="quiet"
                  type="button"
                  onClick={() => setManagedId(account.id)}
                >
                  {text.manage}
                </Button>
              </span>
            </li>
          ))}
        </ul>
      )}

      <CreateAccountDrawer
        open={addOpen}
        onClose={() => setAddOpen(false)}
        onCreated={() => setAddOpen(false)}
      />

      <ManageAccountDrawer
        account={managed}
        onClose={() => setManagedId(null)}
        onChanged={() => undefined}
        onConflict={() => accountsQuery.refetch().then(() => undefined)}
      />
    </Panel>
  )
}

function CreateAccountDrawer({
  open,
  onClose,
  onCreated,
}: {
  open: boolean
  onClose: () => void
  onCreated: (account: generated.UserAccount) => void
}) {
  const locale = useLocale()
  const csrfToken = useSessionToken()
  const text = accountCopy[locale]
  const queryClient = useQueryClient()
  const peopleQuery = usePeople()
  const people =
    (peopleQuery.data as { items: generated.Person[] } | undefined)?.items ??
    null
  const [personId, setPersonId] = useState('')
  const [username, setUsername] = useState('')
  const [error, setError] = useState(false)
  const errorRef = useRef<HTMLParagraphElement>(null)

  useEffect(() => {
    if (!open) return
    setUsername('')
    setError(false)
    void peopleQuery.refetch()
  }, [open, peopleQuery])

  useEffect(() => {
    if (!open || !people) return
    setPersonId(people[0]?.id ?? '')
  }, [open, people])

  const mutation = useMutation({
    mutationFn: async ({
      nextPersonId,
      nextUsername,
    }: {
      nextPersonId: string
      nextUsername: string
    }) => {
      const person = people?.find((item) => item.id === nextPersonId)
      if (!person) throw new Error('Person is not available')
      return unwrap<generated.UserAccount>(
        await generated.createUserAccount(
          {
            person_id: person.id,
            person_version: person.person_version,
            username: nextUsername,
          },
          requestInit(csrfToken, { command: true }),
        ),
      )
    },
    onSuccess: (account) => {
      void queryClient.invalidateQueries({ queryKey: ['user-accounts'] })
      onCreated(account)
    },
    onError: () => {
      setError(true)
      window.requestAnimationFrame(() => errorRef.current?.focus())
    },
  })
  const submitting = mutation.isPending

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const person = people?.find((item) => item.id === personId)
    if (!person || !USERNAME_PATTERN.test(username)) {
      setError(true)
      window.requestAnimationFrame(() => errorRef.current?.focus())
      return
    }
    setError(false)
    mutation.mutate({ nextPersonId: personId, nextUsername: username })
  }

  return (
    <Drawer open={open} onClose={onClose} title={text.addAccountTitle}>
      <p className="field__help">{text.addAccountIntro}</p>
      {error && (
        <p className="error-summary" role="alert" tabIndex={-1} ref={errorRef}>
          {text.validation}
        </p>
      )}
      <form
        className="resource-form"
        onSubmit={(event) => void submit(event)}
        noValidate
      >
        {peopleQuery.isError ? (
          <p className="error-summary" role="alert">
            {text.peopleError}
          </p>
        ) : peopleQuery.isLoading ? (
          <p className="field__help" role="status">
            {text.peopleLoading}
          </p>
        ) : people ? (
          <>
            <Field id="account-person" label={text.employee} required>
              <Select
                id="account-person"
                value={personId}
                onChange={setPersonId}
                options={people.map((person) => ({
                  value: person.id,
                  label:
                    locale === 'en' && person.display_name_en
                      ? person.display_name_en
                      : person.display_name_ar,
                }))}
              />
            </Field>
            <Field
              id="account-username"
              label={text.username}
              required
              help={text.usernameHint}
              error={
                error && !USERNAME_PATTERN.test(username)
                  ? text.validation
                  : null
              }
            >
              <input
                id="account-username"
                value={username}
                required
                aria-required="true"
                aria-invalid={error && !USERNAME_PATTERN.test(username)}
                onChange={(event) => setUsername(event.target.value)}
              />
            </Field>
            <div className="form-actions">
              <Button type="submit" disabled={submitting}>
                {submitting ? text.saving : text.create}
              </Button>
              <Button
                variant="secondary"
                type="button"
                onClick={onClose}
                disabled={submitting}
              >
                {accountsCopy[locale].cancel}
              </Button>
            </div>
          </>
        ) : null}
      </form>
    </Drawer>
  )
}

function ManageAccountDrawer({
  account,
  onClose,
  onChanged,
  onConflict,
}: {
  account: generated.UserAccount | null
  onClose: () => void
  onChanged: (account: generated.UserAccount) => void
  onConflict: () => Promise<void>
}) {
  const locale = useLocale()
  const csrfToken = useSessionToken()
  const text = accountCopy[locale]
  const queryClient = useQueryClient()
  const [reason, setReason] = useState('')
  const [pending, setPending] = useState<AccountAction | null>(null)
  const [error, setError] = useState<'save' | 'stale' | null>(null)
  const [done, setDone] = useState(false)
  const errorRef = useRef<HTMLParagraphElement>(null)
  const accountId = account?.id ?? null

  useEffect(() => {
    setReason('')
    setPending(null)
    setError(null)
    setDone(false)
  }, [accountId])

  const mutation = useMutation({
    mutationFn: async ({
      action,
      nextReason,
    }: {
      action: AccountAction
      nextReason?: string
    }) => {
      if (!account) throw new Error('Account is not available')
      const fresh = unwrap<generated.UserAccount & { lock_version?: number }>(
        await generated.getUserAccount(account.id, requestInit(csrfToken)),
      )
      return unwrap<generated.UserAccount>(
        await generated.transitionUserAccount(
          account.id,
          action,
          nextReason ? { reason: nextReason } : undefined,
          requestInit(csrfToken, {
            command: true,
            lockVersion: fresh.lock_version,
          }),
        ),
      )
    },
    onSuccess: (updated) => {
      onChanged(updated)
      setReason('')
      setDone(true)
      setPending(null)
      void queryClient.invalidateQueries({ queryKey: ['user-accounts'] })
    },
    onError: async (failure) => {
      if (failure instanceof ApiError && failure.status === 412) {
        setPending(null)
        await onConflict()
        setError('stale')
      } else {
        setError('save')
      }
      window.requestAnimationFrame(() => errorRef.current?.focus())
    },
  })

  if (!account) return null

  const currentAccount = account
  const available = ACTIONS_BY_STATUS[currentAccount.status] ?? []
  const busy = pending !== null

  function run(action: AccountAction) {
    setPending(action)
    setError(null)
    setDone(false)
    mutation.mutate({ action, nextReason: reason.trim() || undefined })
  }

  return (
    <Drawer open onClose={onClose} title={text.manageAccount}>
      <dl className="detail-list">
        <div>
          <dt>{text.employee}</dt>
          <dd>{personName(account, locale)}</dd>
        </div>
        <div>
          <dt>{text.username}</dt>
          <dd dir="ltr">{account.username}</dd>
        </div>
        <div>
          <dt>{text.status}</dt>
          <dd>
            <StatusBadge
              variant={
                account.status === 'active'
                  ? 'success'
                  : account.status === 'archived'
                    ? 'neutral'
                    : 'warning'
              }
            >
              {accountStatusLabel(account.status, locale)}
            </StatusBadge>
          </dd>
        </div>
      </dl>
      {account.must_change_password && (
        <p className="status-message" role="status">
          {text.mustChangePassword}
        </p>
      )}
      {error && (
        <p className="error-summary" role="alert" tabIndex={-1} ref={errorRef}>
          {error === 'stale' ? accountsCopy[locale].stale : text.saveError}
        </p>
      )}
      {done && (
        <p className="status-message" role="status">
          {accountsCopy[locale].done}
        </p>
      )}
      {available.length === 0 ? (
        <p className="status-message" role="status">
          {text.noActions}
        </p>
      ) : (
        <>
          <Field id="account-reason" label={text.reason} help={text.reasonHint}>
            <input
              id="account-reason"
              value={reason}
              onChange={(event) => setReason(event.target.value)}
            />
          </Field>
          {available.map((item) => (
            <div className="form-actions" key={item.action}>
              <Button
                variant={item.danger ? 'secondary' : 'primary'}
                type="button"
                onClick={() => void run(item.action)}
                disabled={busy}
              >
                {pending === item.action ? text.saving : text[item.label]}
              </Button>
              <p className="field__help">{text[item.hint]}</p>
            </div>
          ))}
        </>
      )}
    </Drawer>
  )
}


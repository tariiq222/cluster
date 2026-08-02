import { useMemo, useState } from 'react'
import type { ColumnDef } from '@tanstack/react-table'
import { useLocale } from '../../../app/session-context'
import { usePrincipal } from '../../../app/principal-context'
import { useNavigate } from '../../../app/navigation-context'
import { useUserAccounts } from '../../../api/hooks'
import { ApiError, stateFromError, type ResourceState } from '../../../api/http'
import * as generated from '../../../api/generated/cluster'
import { DataTable } from '@/components/data-table'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { accountCopy } from '../accounts-copy'

/* ------------------------------------------------------------------ */
/* Accounts tab                                                        */
/* ------------------------------------------------------------------ */

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
 * The account list tab. Creation and management live on full pages
 * (`/access/accounts/new` and `/access/accounts/:accountId`); this tab
 * keeps the cursor-paginated DataTable and the capability-filtered
 * navigation actions.
 */
export function AccountsTab() {
  const locale = useLocale()
  const principal = usePrincipal()
  const navigate = useNavigate()
  const text = accountCopy[locale]
  const [history, setHistory] = useState<string[]>([])

  const canManage = (principal.capabilities ?? []).includes('identity.account.manage')
  const cursor = history.length > 0 ? history[history.length - 1] : undefined
  const accountsQuery = useUserAccounts(cursor)
  const accounts =
    (accountsQuery.data as generated.UserAccountCollection | undefined)?.items ?? []
  const nextCursor =
    (accountsQuery.data as generated.UserAccountCollection | undefined)?.next_cursor ?? null

  const state: ResourceState = accountsQuery.isLoading
    ? 'loading'
    : accountsQuery.isError
      ? stateFromError(accountsQuery.error)
      : accounts.length === 0
        ? 'empty'
        : 'ready'

  const columns: ColumnDef<generated.UserAccount>[] = useMemo(
    () => [
      {
        accessorKey: 'display_name_ar',
        header: text.employee,
        cell: ({ row }) => (
          <span className="font-medium break-words whitespace-normal">{personName(row.original, locale)}</span>
        ),
      },
      {
        accessorKey: 'username',
        header: text.username,
        cell: ({ row }) => (
          <span className="font-mono text-sm break-all whitespace-normal" dir="ltr">{row.original.username}</span>
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
            <div className="flex flex-wrap items-center gap-2">
              {canManage && account.status === 'pending' ? (
                <Button
                  size="sm"
                  variant="outline"
                  type="button"
                  onClick={() => navigate(`/access/accounts/${account.id}`)}
                >
                  {text.activate}
                </Button>
              ) : null}
              {canManage ? (
                <Button
                  size="sm"
                  variant="ghost"
                  type="button"
                  onClick={() => navigate(`/access/accounts/${account.id}`)}
                >
                  {text.manage}
                </Button>
              ) : null}
            </div>
          )
        },
      },
    ],
    [text, locale, canManage, navigate],
  )

  return (
    <div className="space-y-4 min-w-0">
      <h2 className="text-xl font-semibold tracking-tight">{text.accounts}</h2>
      <div className="flex justify-end">
        {canManage ? (
          <Button size="sm" onClick={() => navigate('/access/accounts/new')}>
            {text.addAccount}
          </Button>
        ) : null}
      </div>

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
        onRetry={() => void accountsQuery.refetch()}
        correlationId={
          accountsQuery.error instanceof ApiError
            ? accountsQuery.error.correlationId
            : null
        }
        empty={
          <div className="py-12 text-center">
            <p className="text-foreground font-medium">{text.noAccounts}</p>
            <p className="text-muted-foreground text-sm">{text.noAccountsBody}</p>
          </div>
        }
      />
    </div>
  )
}

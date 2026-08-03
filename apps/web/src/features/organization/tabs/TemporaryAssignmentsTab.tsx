import { useEffect, useMemo, useState } from 'react'
import type { ColumnDef } from '@tanstack/react-table'
import { useLocale } from '../../../app/session-context'
import { usePrincipal } from '../../../app/principal-context'
import { useAllPeople, useTemporaryAssignments } from '../../../api/hooks'
import { stateFromError } from '../../../api/http'
import { formatDate, formatNumber, type Locale } from '../../../i18n'
import * as generated from '../../../api/generated/cluster'
import { DataTable } from '@/components/data-table'
import { Badge } from '@/components/ui/badge'
import { organizationCopy } from '../organization-copy'
import { useCapabilities } from '../organization-utils'

export function TemporaryAssignmentsTab() {
  const locale = useLocale()
  const text = organizationCopy[locale]
  const capabilities = useCapabilities()
  const { scopeEpoch } = usePrincipal()
  const [pagination, setPagination] = useState({ scopeEpoch, history: [] as string[] })
  const history = pagination.scopeEpoch === scopeEpoch ? pagination.history : []
  const cursor = history.at(-1)
  const temporaryQuery = useTemporaryAssignments(cursor)
  const peopleQuery = useAllPeople()

  useEffect(() => {
    setPagination({ scopeEpoch, history: [] })
  }, [scopeEpoch])

  const canRead = capabilities.includes('organization.temporary_assignment.read')
  const items = (temporaryQuery.data as generated.TemporaryAssignmentCollection | undefined)?.items ?? []
  const nextCursor = temporaryQuery.data?.next_cursor ?? null

  const state = temporaryQuery.isError
    ? stateFromError(temporaryQuery.error)
    : temporaryQuery.isLoading ? 'loading'
    : 'ready'

  const columns = useMemo<ColumnDef<generated.TemporaryAssignment>[]>(() => {
    const people = (peopleQuery.data as generated.PersonCollection | undefined)?.items ?? []
    return [
      {
        accessorKey: 'person_id',
        header: text.person,
        cell: ({ row }) => (
          <span className="font-medium">
            {peopleQuery.isLoading
              ? text.loading
              : peopleQuery.isError
                ? text.unavailable
                : people.find((person) => person.id === row.original.person_id)?.display_name_ar ?? text.unavailable}
          </span>
        ),
      },
      {
        accessorKey: 'start_at',
        header: text.startAt,
        cell: ({ row }) => <span className="text-sm">{formatDate(row.original.start_at, locale)}</span>,
      },
      {
        accessorKey: 'end_at',
        header: text.remaining,
        cell: ({ row }) => (
          <span className="text-sm">{remainingDuration(row.original.end_at, locale, text.remainingDays, text.remainingHours)}</span>
        ),
      },
      {
        accessorKey: 'status',
        header: text.status,
        cell: ({ row }) => (
          <Badge variant="outline">{row.original.status === 'active' ? text.active : text.ended}</Badge>
        ),
      },
    ]
  }, [locale, text, peopleQuery.data, peopleQuery.isLoading, peopleQuery.isError])

  return (
    <div className="space-y-4">
      {canRead && peopleQuery.isLoading ? (
        <p role="status" className="text-muted-foreground text-sm">{text.loading}</p>
      ) : canRead && peopleQuery.isError ? (
        <p role="alert" className="text-destructive text-sm">{text.error}</p>
      ) : null}
      <DataTable
        columns={columns}
        data={canRead ? items : []}
        state={canRead ? state : 'forbidden'}
        nextCursor={canRead ? nextCursor : null}
        onNext={() => {
          if (!canRead || !nextCursor) return
          setPagination((current) => ({
            scopeEpoch,
            history: [...(current.scopeEpoch === scopeEpoch ? current.history : []), nextCursor],
          }))
        }}
        onPrev={() => setPagination((current) => ({
          scopeEpoch,
          history: (current.scopeEpoch === scopeEpoch ? current.history : []).slice(0, -1),
        }))}
        canPrev={canRead && history.length > 0}
        locale={locale}
        empty={<p className="text-muted-foreground py-8 text-center text-sm">{text.noAssignments}</p>}
      />
    </div>
  )
}

function remainingDuration(
  endAt: string,
  locale: Locale,
  daysLabel: string,
  hoursLabel: string,
): string {
  const end = new Date(endAt)
  const now = new Date()
  if (Number.isNaN(end.getTime())) return endAt
  const diffMs = end.getTime() - now.getTime()
  if (diffMs <= 0) return daysLabel.replace('{n}', formatNumber(0, locale))
  const days = Math.floor(diffMs / 86_400_000)
  if (days >= 1) return daysLabel.replace('{n}', formatNumber(days, locale))
  const hours = Math.max(1, Math.floor(diffMs / 3_600_000))
  return hoursLabel.replace('{n}', formatNumber(hours, locale))
}

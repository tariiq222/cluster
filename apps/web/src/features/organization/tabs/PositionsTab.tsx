import { useEffect, useMemo, useState } from 'react'
import { Plus } from 'lucide-react'
import type { ColumnDef } from '@tanstack/react-table'
import { useLocale } from '../../../app/session-context'
import { usePrincipal } from '../../../app/principal-context'
import { useNavigate } from '../../../app/navigation-context'
import { useAllJobTitles, useAllOrganizationUnits, usePositions } from '../../../api/hooks'
import { stateFromError } from '../../../api/http'
import * as generated from '../../../api/generated/cluster'
import { DataTable } from '@/components/data-table'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { organizationCopy } from '../organization-copy'
import { useCapabilities } from '../organization-utils'

export function PositionsTab() {
  const locale = useLocale()
  const text = organizationCopy[locale]
  const navigate = useNavigate()
  const capabilities = useCapabilities()
  const { scopeEpoch } = usePrincipal()
  const [pagination, setPagination] = useState({ scopeEpoch, history: [] as string[] })
  const history = pagination.scopeEpoch === scopeEpoch ? pagination.history : []
  const cursor = history.at(-1)
  const positionsQuery = usePositions(cursor)
  const unitsQuery = useAllOrganizationUnits()
  const jobTitlesQuery = useAllJobTitles()

  useEffect(() => {
    setPagination({ scopeEpoch, history: [] })
  }, [scopeEpoch])

  const canManage = capabilities.includes('organization.position.manage')
  const positions = (positionsQuery.data as generated.PositionCollection | undefined)?.items ?? []
  const nextCursor = positionsQuery.data?.next_cursor ?? null
  const supportingLabelsLoading = unitsQuery.isLoading || jobTitlesQuery.isLoading
  const supportingLabelsError = unitsQuery.isError || jobTitlesQuery.isError

  const state = positionsQuery.isError
    ? stateFromError(positionsQuery.error)
    : positionsQuery.isLoading ? 'loading'
    : 'ready'

  const columns = useMemo<ColumnDef<generated.Position>[]>(() => {
    const units = (unitsQuery.data as generated.OrganizationUnitCollection | undefined)?.items ?? []
    const jobTitles = (jobTitlesQuery.data as generated.JobTitleCollection | undefined)?.items ?? []
    return [
      {
        accessorKey: 'title_ar',
        header: text.positionTitle,
        cell: ({ row }) => <span className="font-medium">{row.original.title_ar}</span>,
      },
      {
        accessorKey: 'code',
        header: text.identifier,
        cell: ({ row }) => <span className="font-mono text-sm" dir="ltr">{row.original.code}</span>,
      },
      {
        accessorKey: 'organization_unit_id',
        header: text.parent,
        cell: ({ row }) => (
          <span>
            {unitsQuery.isLoading
              ? text.loading
              : unitsQuery.isError
                ? text.unavailable
                : units.find((unit) => unit.id === row.original.organization_unit_id)?.name_ar ?? text.unavailable}
          </span>
        ),
      },
      {
        accessorKey: 'job_title_id',
        header: text.jobTitle,
        cell: ({ row }) => (
          <span>
            {!row.original.job_title_id
              ? '—'
              : jobTitlesQuery.isLoading
                ? text.loading
                : jobTitlesQuery.isError
                  ? text.unavailable
                  : jobTitles.find((title) => title.id === row.original.job_title_id)?.title_ar ?? text.unavailable}
          </span>
        ),
      },
      {
        accessorKey: 'is_active',
        header: text.status,
        cell: ({ row }) => (
          <Badge variant="outline">{row.original.is_active ? text.active : text.inactive}</Badge>
        ),
      },
    ]
  }, [text, unitsQuery.data, unitsQuery.isLoading, unitsQuery.isError, jobTitlesQuery.data, jobTitlesQuery.isLoading, jobTitlesQuery.isError])

  return (
    <div className="space-y-4">
      <div className="flex justify-end">
        {canManage ? (
          <Button size="sm" onClick={() => navigate('/organization/positions/new')}>
            <Plus aria-hidden="true" />
            {text.addPosition}
          </Button>
        ) : null}
      </div>
      {supportingLabelsLoading ? (
        <p role="status" className="text-muted-foreground text-sm">{text.loading}</p>
      ) : supportingLabelsError ? (
        <p role="alert" className="text-destructive text-sm">{text.error}</p>
      ) : null}
      <DataTable
        columns={columns}
        data={positions}
        state={state}
        nextCursor={nextCursor}
        onNext={() => {
          if (!nextCursor) return
          setPagination((current) => ({
            scopeEpoch,
            history: [...(current.scopeEpoch === scopeEpoch ? current.history : []), nextCursor],
          }))
        }}
        onPrev={() => setPagination((current) => ({
          scopeEpoch,
          history: (current.scopeEpoch === scopeEpoch ? current.history : []).slice(0, -1),
        }))}
        canPrev={history.length > 0}
        locale={locale}
        empty={<p className="text-muted-foreground py-8 text-center text-sm">{text.noPositions}</p>}
      />
    </div>
  )
}

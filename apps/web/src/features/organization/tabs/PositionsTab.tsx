import { useMemo } from 'react'
import { Plus } from 'lucide-react'
import type { ColumnDef } from '@tanstack/react-table'
import { useLocale } from '../../../app/session-context'
import { useNavigate } from '../../../app/navigation-context'
import { useJobTitles, useOrganizationUnits, usePositions } from '../../../api/hooks'
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
  const positionsQuery = usePositions()
  const unitsQuery = useOrganizationUnits()
  const jobTitlesQuery = useJobTitles()

  const canManage = capabilities.includes('organization.position.manage')
  const positions = (positionsQuery.data as generated.PositionCollection | undefined)?.items ?? []

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
          <span>{units.find((unit) => unit.id === row.original.organization_unit_id)?.name_ar ?? '—'}</span>
        ),
      },
      {
        accessorKey: 'job_title_id',
        header: text.jobTitle,
        cell: ({ row }) => (
          <span>{jobTitles.find((title) => title.id === row.original.job_title_id)?.title_ar ?? '—'}</span>
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
  }, [text, unitsQuery.data, jobTitlesQuery.data])

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
      <DataTable
        columns={columns}
        data={positions}
        state={state}
        nextCursor={null}
        onNext={() => {}}
        onPrev={() => {}}
        canPrev={false}
        locale={locale}
        empty={<p className="text-muted-foreground py-8 text-center text-sm">{text.noPositions}</p>}
      />
    </div>
  )
}

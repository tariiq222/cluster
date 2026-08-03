import { useEffect, useMemo, useState } from 'react'
import { Plus } from 'lucide-react'
import type { ColumnDef } from '@tanstack/react-table'
import { useLocale } from '../../../app/session-context'
import { usePrincipal } from '../../../app/principal-context'
import { useNavigate } from '../../../app/navigation-context'
import { useCluster, useFacilities } from '../../../api/hooks'
import { ApiError, stateFromError } from '../../../api/http'
import * as generated from '../../../api/generated/cluster'
import { DataTable } from '@/components/data-table'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { organizationCopy } from '../organization-copy'
import { displayName, facilityTypes, useCapabilities } from '../organization-utils'

export function FacilitiesTab() {
  const locale = useLocale()
  const text = organizationCopy[locale]
  const navigate = useNavigate()
  const capabilities = useCapabilities()
  const { scopeEpoch } = usePrincipal()
  const clusterQuery = useCluster()
  const [pagination, setPagination] = useState({ scopeEpoch, history: [] as string[] })
  const history = pagination.scopeEpoch === scopeEpoch ? pagination.history : []
  const cursor = history.at(-1)
  const facilitiesQuery = useFacilities(cursor)

  useEffect(() => {
    setPagination({ scopeEpoch, history: [] })
  }, [scopeEpoch])

  const canManage = capabilities.includes('organization.facility.manage')
  const clusterMissing = clusterQuery.error instanceof ApiError && clusterQuery.error.status === 404
  const cluster = clusterMissing ? null : ((clusterQuery.data as generated.Cluster | null) ?? null)
  const facilities = (facilitiesQuery.data as generated.FacilityCollection | undefined)?.items ?? []
  const nextCursor = facilitiesQuery.data?.next_cursor ?? null

  const state = facilitiesQuery.isError
    ? stateFromError(facilitiesQuery.error)
    : facilitiesQuery.isLoading ? 'loading'
    : 'ready'

  const columns = useMemo<ColumnDef<generated.Facility>[]>(
    () => [
      {
        accessorKey: 'name_ar',
        header: text.nameAr,
        cell: ({ row }) => <span className="font-medium">{displayName(locale, row.original)}</span>,
      },
      {
        accessorKey: 'code',
        header: text.identifier,
        cell: ({ row }) => <span className="font-mono text-sm" dir="ltr">{row.original.code}</span>,
      },
      {
        accessorKey: 'type_code',
        header: text.type,
        cell: ({ row }) => <span>{facilityTypeLabel(locale, row.original.type_code)}</span>,
      },
      {
        accessorKey: 'status',
        header: text.status,
        cell: ({ row }) => (
          <Badge variant="outline">{row.original.status === 'active' ? text.active : text.inactive}</Badge>
        ),
      },
    ],
    [locale, text],
  )

  return (
    <div className="space-y-4">
      <div className="flex justify-end">
        {cluster && canManage ? (
          <Button size="sm" onClick={() => navigate('/organization/facilities/new')}>
            <Plus aria-hidden="true" />
            {text.addFacility}
          </Button>
        ) : null}
      </div>

      <DataTable
        columns={columns}
        data={facilities}
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
        onRowClick={
          canManage
            ? (facility) => navigate(`/organization/facilities/${facility.id}/edit`)
            : undefined
        }
        empty={<p className="text-muted-foreground py-8 text-center text-sm">{text.noFacilities}</p>}
      />
    </div>
  )
}

function facilityTypeLabel(locale: 'ar' | 'en', typeCode: string): string {
  const text = organizationCopy[locale]
  const match = facilityTypes.find(([code]) => code === typeCode)
  return match ? text[match[1]] : typeCode
}

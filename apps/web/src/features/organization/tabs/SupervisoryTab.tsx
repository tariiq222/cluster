import { useEffect, useMemo, useState } from 'react'
import type { ColumnDef } from '@tanstack/react-table'
import { useLocale } from '../../../app/session-context'
import { usePrincipal } from '../../../app/principal-context'
import { useSupervisoryRelationships } from '../../../api/hooks'
import { stateFromError } from '../../../api/http'
import * as generated from '../../../api/generated/cluster'
import { DataTable } from '@/components/data-table'
import { Badge } from '@/components/ui/badge'
import { organizationCopy } from '../organization-copy'
import { useCapabilities } from '../organization-utils'

interface SupervisoryRow {
  id: string
  title: string
  relationshipType: string
  status: string
}

export function SupervisoryTab() {
  const locale = useLocale()
  const text = organizationCopy[locale]
  const capabilities = useCapabilities()
  const { scopeEpoch } = usePrincipal()
  const [pagination, setPagination] = useState({ scopeEpoch, history: [] as string[] })
  const history = pagination.scopeEpoch === scopeEpoch ? pagination.history : []
  const cursor = history.at(-1)
  const supervisoryQuery = useSupervisoryRelationships(cursor)

  useEffect(() => {
    setPagination({ scopeEpoch, history: [] })
  }, [scopeEpoch])

  const canRead = capabilities.includes('organization.unit.read')
  const nextCursor =
    (supervisoryQuery.data as generated.EntityCollection | undefined)?.next_cursor ?? null

  const rows: SupervisoryRow[] = useMemo(
    () =>
      (supervisoryQuery.data as generated.EntityCollection | undefined)?.items.map((entity) => ({
        id: entity.id,
        title: 'title' in entity && entity.title ? String(entity.title) : 'name' in entity && entity.name ? String(entity.name) : 'code' in entity && entity.code ? String(entity.code) : entity.id,
        relationshipType: 'description' in entity && entity.description ? String(entity.description) : '—',
        status: entity.status,
      })) ?? [],
    [supervisoryQuery.data],
  )

  const state = supervisoryQuery.isError
    ? stateFromError(supervisoryQuery.error)
    : supervisoryQuery.isLoading ? 'loading'
    : 'ready'

  const columns = useMemo<ColumnDef<SupervisoryRow>[]>(
    () => [
      {
        accessorKey: 'title',
        header: text.person,
        cell: ({ row }) => <span className="font-medium">{row.original.title}</span>,
      },
      {
        accessorKey: 'relationshipType',
        header: text.relationshipType,
        cell: ({ row }) => <span className="text-sm">{row.original.relationshipType}</span>,
      },
      {
        accessorKey: 'status',
        header: text.status,
        cell: ({ row }) => <Badge variant="outline">{row.original.status}</Badge>,
      },
    ],
    [text],
  )

  return (
    <div className="space-y-4">
      <DataTable
        columns={columns}
        data={canRead ? rows : []}
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
        empty={<p className="text-muted-foreground py-8 text-center text-sm">{text.noSupervisory}</p>}
      />
    </div>
  )
}

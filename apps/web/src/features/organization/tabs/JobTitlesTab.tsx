import { useEffect, useMemo, useState } from 'react'
import { Plus } from 'lucide-react'
import type { ColumnDef } from '@tanstack/react-table'
import { useLocale } from '../../../app/session-context'
import { usePrincipal } from '../../../app/principal-context'
import { useNavigate } from '../../../app/navigation-context'
import { useJobTitles } from '../../../api/hooks'
import { stateFromError } from '../../../api/http'
import * as generated from '../../../api/generated/cluster'
import { DataTable } from '@/components/data-table'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { organizationCopy } from '../organization-copy'
import { useCapabilities } from '../organization-utils'

export function JobTitlesTab() {
  const locale = useLocale()
  const text = organizationCopy[locale]
  const navigate = useNavigate()
  const capabilities = useCapabilities()
  const { scopeEpoch } = usePrincipal()
  const [pagination, setPagination] = useState({ scopeEpoch, history: [] as string[] })
  const history = pagination.scopeEpoch === scopeEpoch ? pagination.history : []
  const cursor = history.at(-1)
  const jobTitlesQuery = useJobTitles(cursor)

  useEffect(() => {
    setPagination({ scopeEpoch, history: [] })
  }, [scopeEpoch])

  const canManage = capabilities.includes('organization.job_title.manage')
  const jobTitles = (jobTitlesQuery.data as generated.JobTitleCollection | undefined)?.items ?? []
  const nextCursor = jobTitlesQuery.data?.next_cursor ?? null

  const state = jobTitlesQuery.isError
    ? stateFromError(jobTitlesQuery.error)
    : jobTitlesQuery.isLoading ? 'loading'
    : 'ready'

  const columns = useMemo<ColumnDef<generated.JobTitle>[]>(
    () => [
      {
        accessorKey: 'title_ar',
        header: text.jobTitle,
        cell: ({ row }) => <span className="font-medium">{row.original.title_ar}</span>,
      },
      {
        accessorKey: 'code',
        header: text.identifier,
        cell: ({ row }) => <span className="font-mono text-sm" dir="ltr">{row.original.code}</span>,
      },
      {
        accessorKey: 'status',
        header: text.status,
        cell: ({ row }) => (
          <Badge variant="outline">{row.original.status === 'active' ? text.active : text.inactive}</Badge>
        ),
      },
    ],
    [text],
  )

  return (
    <div className="space-y-4">
      <div className="flex justify-end">
        {canManage ? (
          <Button size="sm" onClick={() => navigate('/organization/job-titles/new')}>
            <Plus aria-hidden="true" />
            {text.addJobTitle}
          </Button>
        ) : null}
      </div>
      <DataTable
        columns={columns}
        data={jobTitles}
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
        empty={<p className="text-muted-foreground py-8 text-center text-sm">{text.noJobTitles}</p>}
      />
    </div>
  )
}

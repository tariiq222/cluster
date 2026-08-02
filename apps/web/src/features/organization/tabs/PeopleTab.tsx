import { useMemo } from 'react'
import { Plus } from 'lucide-react'
import type { ColumnDef } from '@tanstack/react-table'
import { useLocale } from '../../../app/session-context'
import { useNavigate } from '../../../app/navigation-context'
import { usePeople } from '../../../api/hooks'
import { stateFromError } from '../../../api/http'
import * as generated from '../../../api/generated/cluster'
import { DataTable } from '@/components/data-table'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { organizationCopy } from '../organization-copy'
import { useCapabilities } from '../organization-utils'

export function PeopleTab() {
  const locale = useLocale()
  const text = organizationCopy[locale]
  const navigate = useNavigate()
  const capabilities = useCapabilities()
  const peopleQuery = usePeople()

  const canManage = capabilities.includes('organization.person.manage')
  const people = peopleQuery.data?.items ?? []

  const state = peopleQuery.isError
    ? stateFromError(peopleQuery.error)
    : peopleQuery.isLoading ? 'loading'
    : 'ready'

  const columns = useMemo<ColumnDef<generated.Person>[]>(
    () => [
      {
        accessorKey: 'display_name_ar',
        header: text.person,
        cell: ({ row }) => <span className="font-medium">{row.original.display_name_ar}</span>,
      },
      {
        accessorKey: 'employee_number',
        header: text.employeeNumber,
        cell: ({ row }) => <span className="font-mono text-sm" dir="ltr">{row.original.employee_number}</span>,
      },
      {
        accessorKey: 'status',
        header: text.status,
        cell: ({ row }) => <Badge variant="outline">{personStatusLabel(row.original.status, text)}</Badge>,
      },
    ],
    [text],
  )

  return (
    <div className="space-y-4">
      <div className="flex justify-end">
        {canManage ? (
          <Button size="sm" onClick={() => navigate('/organization/people/new')}>
            <Plus aria-hidden="true" />
            {text.addPerson}
          </Button>
        ) : null}
      </div>
      <DataTable
        columns={columns}
        data={people}
        state={state}
        nextCursor={null}
        onNext={() => {}}
        onPrev={() => {}}
        canPrev={false}
        locale={locale}
        onRowClick={
          canManage
            ? (person) => navigate(`/organization/people/${person.id}/edit`)
            : undefined
        }
        empty={<p className="text-muted-foreground py-8 text-center text-sm">{text.noPeople}</p>}
      />
    </div>
  )
}

function personStatusLabel(status: generated.PersonStatus, text: (typeof organizationCopy)[keyof typeof organizationCopy]): string {
  switch (status) {
    case 'active':
      return text.active
    case 'suspended':
      return text.suspended
    case 'left':
      return text.left
    default:
      return status
  }
}

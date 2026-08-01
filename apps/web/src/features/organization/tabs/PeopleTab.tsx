import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Plus } from 'lucide-react'
import type { ColumnDef } from '@tanstack/react-table'
import { useLocale, useSessionToken } from '../../../app/session-context'
import { usePeople } from '../../../api/hooks'
import { ApiError, requestInit, stateFromError, unwrap } from '../../../api/http'
import * as generated from '../../../api/generated/cluster'
import { DataTable } from '@/components/data-table'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet'
import { organizationCopy } from '../organization-copy'
import { useCapabilities } from '../organization-utils'

export function PeopleTab() {
  const locale = useLocale()
  const text = organizationCopy[locale]
  const capabilities = useCapabilities()
  const peopleQuery = usePeople()
  const [sheetOpen, setSheetOpen] = useState(false)
  const [editing, setEditing] = useState<generated.Person | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  const canManage = capabilities.includes('organization.person.manage')
  const people = (peopleQuery.data as generated.PersonCollection | undefined)?.items ?? []

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
      {notice ? <p role="status">{notice}</p> : null}
      <div className="flex justify-end">
        {canManage ? (
          <Button
            size="sm"
            onClick={() => {
              setEditing(null)
              setSheetOpen(true)
            }}
          >
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
            ? (person) => {
                setEditing(person)
                setSheetOpen(true)
              }
            : undefined
        }
        empty={<p className="text-muted-foreground py-8 text-center text-sm">{text.noPeople}</p>}
      />
      <PersonSheet
        open={sheetOpen}
        person={editing}
        onClose={() => setSheetOpen(false)}
        onSaved={() => {
          setSheetOpen(false)
          setEditing(null)
          setNotice(text.personSaved)
        }}
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

function PersonSheet({
  open,
  person,
  onClose,
  onSaved,
}: {
  open: boolean
  person: generated.Person | null
  onClose: () => void
  onSaved: () => void
}) {
  const locale = useLocale()
  const token = useSessionToken()
  const text = organizationCopy[locale]
  const queryClient = useQueryClient()
  const editing = person !== null
  const [employeeNumber, setEmployeeNumber] = useState('')
  const [nameAr, setNameAr] = useState('')
  const [nameEn, setNameEn] = useState('')
  const [failure, setFailure] = useState<'validation' | 'stale' | 'save' | null>(null)

  useEffect(() => {
    if (!open) return
    setEmployeeNumber(person?.employee_number ?? '')
    setNameAr(person?.display_name_ar ?? '')
    setNameEn(person?.display_name_en ?? '')
    setFailure(null)
  }, [open, person])

  const mutation = useMutation({
    mutationFn: async ({ nextEmployeeNumber, nextNameAr, nextNameEn }: { nextEmployeeNumber: string; nextNameAr: string; nextNameEn: string }) => {
      if (editing && person) {
        const fresh = unwrap<generated.Person>(await generated.getPerson(person.id, requestInit(token)))
        return unwrap<generated.Person>(
          await generated.updatePerson(
            person.id,
            { display_name_ar: nextNameAr, ...(nextNameEn ? { display_name_en: nextNameEn } : {}) },
            requestInit(token, { command: true, idempotency: 'person-update', lockVersion: fresh.person_version }),
          ),
        )
      }
      return unwrap<generated.Person>(
        await generated.registerPerson(
          {
            employee_number: nextEmployeeNumber,
            display_name_ar: nextNameAr,
            display_name_en: nextNameEn.trim() || undefined,
            status: 'active',
          },
          requestInit(token, { command: true, idempotency: 'person' }),
        ),
      )
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['people'] })
      onSaved()
    },
    onError: (caught) => setFailure(caught instanceof ApiError && caught.status === 412 ? 'stale' : 'save'),
  })
  const submitting = mutation.isPending

  return (
    <Sheet open={open} onOpenChange={(next) => { if (!next && !submitting) onClose() }}>
      <SheetContent>
        <SheetHeader>
          <SheetTitle>{editing ? text.editPersonTitle : text.createPersonTitle}</SheetTitle>
          <SheetDescription>{text.people}</SheetDescription>
        </SheetHeader>
        <form
          className="grid gap-4"
          onSubmit={(event) => {
            event.preventDefault()
            if (!nameAr.trim() || (!editing && !employeeNumber.trim())) {
              setFailure('validation')
              return
            }
            setFailure(null)
            mutation.mutate({ nextEmployeeNumber: employeeNumber.trim(), nextNameAr: nameAr.trim(), nextNameEn: nameEn.trim() })
          }}
          noValidate
        >
          {failure === 'validation' ? (
            <p className="text-destructive text-sm" role="alert">{text.validation}</p>
          ) : failure === 'stale' ? (
            <p className="text-destructive text-sm" role="alert">{text.stale}</p>
          ) : failure === 'save' ? (
            <p className="text-destructive text-sm" role="alert">{text.saveError}</p>
          ) : null}
          {!editing ? (
            <div className="grid gap-2">
              <Label htmlFor="org-person-employee-number">{text.employeeNumber}</Label>
              <Input
                id="org-person-employee-number"
                dir="ltr"
                value={employeeNumber}
                aria-invalid={failure === 'validation' || undefined}
                onChange={(event) => setEmployeeNumber(event.target.value)}
              />
            </div>
          ) : null}
          <div className="grid gap-2">
            <Label htmlFor="org-person-name-ar">{text.nameAr}</Label>
            <Input
              id="org-person-name-ar"
              value={nameAr}
              aria-invalid={failure === 'validation' || undefined}
              onChange={(event) => setNameAr(event.target.value)}
            />
          </div>
          <div className="grid gap-2">
            <Label htmlFor="org-person-name-en">{text.nameEn}</Label>
            <Input id="org-person-name-en" value={nameEn} onChange={(event) => setNameEn(event.target.value)} />
          </div>
          <div className="flex justify-end gap-2">
            <Button type="button" variant="outline" onClick={onClose} disabled={submitting}>
              {text.cancel}
            </Button>
            <Button type="submit" disabled={submitting}>
              {submitting ? text.saving : text.save}
            </Button>
          </div>
        </form>
      </SheetContent>
    </Sheet>
  )
}

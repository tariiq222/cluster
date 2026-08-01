import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Plus } from 'lucide-react'
import type { ColumnDef } from '@tanstack/react-table'
import { useLocale, useSessionToken } from '../../../app/session-context'
import { useAssignments, usePeople, usePositions } from '../../../api/hooks'
import { requestInit, stateFromError, unwrap } from '../../../api/http'
import { formatDate } from '../../../i18n'
import * as generated from '../../../api/generated/cluster'
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog'
import { DataTable } from '@/components/data-table'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet'
import { organizationCopy } from '../organization-copy'
import { localDateTimeInput, toUtcIso, useCapabilities } from '../organization-utils'

export function AssignmentsTab() {
  const locale = useLocale()
  const text = organizationCopy[locale]
  const capabilities = useCapabilities()
  const assignmentsQuery = useAssignments()
  const peopleQuery = usePeople()
  const positionsQuery = usePositions()
  const [sheetOpen, setSheetOpen] = useState(false)
  const [ending, setEnding] = useState<generated.Assignment | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  const canManage = capabilities.includes('organization.assignment.manage')
  const assignments = (assignmentsQuery.data as generated.AssignmentCollection | undefined)?.items ?? []
  const people = (peopleQuery.data as generated.PersonCollection | undefined)?.items ?? []
  const positions = (positionsQuery.data as generated.PositionCollection | undefined)?.items ?? []

  const state = assignmentsQuery.isError
    ? stateFromError(assignmentsQuery.error)
    : assignmentsQuery.isLoading ? 'loading'
    : 'ready'

  const columns = useMemo<ColumnDef<generated.Assignment>[]>(() => {
    const people = (peopleQuery.data as generated.PersonCollection | undefined)?.items ?? []
    const positions = (positionsQuery.data as generated.PositionCollection | undefined)?.items ?? []
    return [
      {
        accessorKey: 'person_id',
        header: text.person,
        cell: ({ row }) => (
          <span className="font-medium">{people.find((person) => person.id === row.original.person_id)?.display_name_ar ?? row.original.person_id}</span>
        ),
      },
      {
        accessorKey: 'position_id',
        header: text.position,
        cell: ({ row }) => (
          <span>{positions.find((position) => position.id === row.original.position_id)?.title_ar ?? '—'}</span>
        ),
      },
      {
        accessorKey: 'start_at',
        header: text.startAt,
        cell: ({ row }) => <span className="text-sm">{formatDate(row.original.start_at, locale)}</span>,
      },
      {
        accessorKey: 'end_at',
        header: text.endAt,
        cell: ({ row }) => <span className="text-sm">{row.original.end_at ? formatDate(row.original.end_at, locale) : '—'}</span>,
      },
      {
        accessorKey: 'is_primary',
        header: text.primary,
        cell: ({ row }) => (
          <Badge variant="outline">{row.original.is_primary ? text.primary : '—'}</Badge>
        ),
      },
      {
        accessorKey: 'status',
        header: text.status,
        cell: ({ row }) => <Badge variant="outline">{assignmentStatusLabel(row.original.status, text)}</Badge>,
      },
    ]
  }, [locale, text, peopleQuery.data, positionsQuery.data])

  return (
    <div className="space-y-4">
      {notice ? <p role="status">{notice}</p> : null}
      <div className="flex justify-end">
        {canManage ? (
          <Button size="sm" onClick={() => setSheetOpen(true)}>
            <Plus aria-hidden="true" />
            {text.createAssignment}
          </Button>
        ) : null}
      </div>
      <DataTable
        columns={columns}
        data={assignments}
        state={state}
        nextCursor={null}
        onNext={() => {}}
        onPrev={() => {}}
        canPrev={false}
        locale={locale}
        onRowClick={
          canManage
            ? (assignment) => {
                if (assignment.status === 'active') setEnding(assignment)
              }
            : undefined
        }
        empty={<p className="text-muted-foreground py-8 text-center text-sm">{text.noAssignments}</p>}
      />

      <AssignmentSheet
        open={sheetOpen}
        people={people}
        positions={positions}
        onClose={() => setSheetOpen(false)}
        onSaved={() => {
          setSheetOpen(false)
          setNotice(text.assignmentSaved)
        }}
      />

      <EndAssignmentDialog
        assignment={ending}
        onClose={() => setEnding(null)}
        onEnded={() => {
          setEnding(null)
          setNotice(text.assignmentEnded)
        }}
      />
    </div>
  )
}

function assignmentStatusLabel(status: generated.AssignmentStatus, text: (typeof organizationCopy)[keyof typeof organizationCopy]): string {
  switch (status) {
    case 'pending':
      return text.pending
    case 'active':
      return text.active
    case 'ended':
      return text.ended
    default:
      return status
  }
}

function AssignmentSheet({
  open,
  people,
  positions,
  onClose,
  onSaved,
}: {
  open: boolean
  people: generated.Person[]
  positions: generated.Position[]
  onClose: () => void
  onSaved: () => void
}) {
  const locale = useLocale()
  const token = useSessionToken()
  const text = organizationCopy[locale]
  const queryClient = useQueryClient()
  const [personId, setPersonId] = useState('')
  const [positionId, setPositionId] = useState('')
  const [startAt, setStartAt] = useState('')
  const [failure, setFailure] = useState<'validation' | 'save' | null>(null)

  useEffect(() => {
    if (!open) return
    setPersonId('')
    setPositionId('')
    setStartAt('')
    setFailure(null)
  }, [open])

  const mutation = useMutation({
    mutationFn: async ({ nextPersonId, nextPositionId, nextStartAt }: { nextPersonId: string; nextPositionId: string; nextStartAt: string }) =>
      unwrap<generated.Assignment>(
        await generated.createAssignment(
          { person_id: nextPersonId, position_id: nextPositionId, start_at: nextStartAt },
          requestInit(token, { command: true, idempotency: 'assignment' }),
        ),
      ),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['assignments'] })
      onSaved()
    },
    onError: () => setFailure('save'),
  })
  const submitting = mutation.isPending

  const activePeople = people.filter((person) => person.status === 'active')
  const activePositions = positions.filter((position) => position.is_active)

  return (
    <Sheet open={open} onOpenChange={(next) => { if (!next && !submitting) onClose() }}>
      <SheetContent>
        <SheetHeader>
          <SheetTitle>{text.createAssignmentTitle}</SheetTitle>
          <SheetDescription>{text.assignments}</SheetDescription>
        </SheetHeader>
        {activePeople.length === 0 || activePositions.length === 0 ? (
          <p className="text-muted-foreground text-sm">
            {activePeople.length === 0 ? text.noActivePeople : text.noActivePositions}
          </p>
        ) : (
          <form
            className="grid gap-4"
            onSubmit={(event) => {
              event.preventDefault()
              if (!personId || !positionId || !startAt) {
                setFailure('validation')
                return
              }
              setFailure(null)
              mutation.mutate({ nextPersonId: personId, nextPositionId: positionId, nextStartAt: toUtcIso(startAt) ?? startAt })
            }}
            noValidate
          >
            {failure === 'validation' ? (
              <p className="text-destructive text-sm" role="alert">{text.validation}</p>
            ) : failure === 'save' ? (
              <p className="text-destructive text-sm" role="alert">{text.saveError}</p>
            ) : null}
            <div className="grid gap-2">
              <Label htmlFor="org-assignment-person">{text.person}</Label>
              <Select value={personId} onValueChange={setPersonId}>
                <SelectTrigger id="org-assignment-person">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {activePeople.map((person) => (
                    <SelectItem key={person.id} value={person.id}>
                      {person.display_name_ar}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="grid gap-2">
              <Label htmlFor="org-assignment-position">{text.position}</Label>
              <Select value={positionId} onValueChange={setPositionId}>
                <SelectTrigger id="org-assignment-position">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {activePositions.map((position) => (
                    <SelectItem key={position.id} value={position.id}>
                      {position.title_ar}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="grid gap-2">
              <Label htmlFor="org-assignment-start-at">{text.startAt}</Label>
              <Input
                id="org-assignment-start-at"
                type="datetime-local"
                value={startAt}
                onChange={(event) => setStartAt(event.target.value)}
              />
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
        )}
      </SheetContent>
    </Sheet>
  )
}

function EndAssignmentDialog({
  assignment,
  onClose,
  onEnded,
}: {
  assignment: generated.Assignment | null
  onClose: () => void
  onEnded: () => void
}) {
  const locale = useLocale()
  const token = useSessionToken()
  const text = organizationCopy[locale]
  const queryClient = useQueryClient()
  const [endAt, setEndAt] = useState('')
  const [reason, setReason] = useState('')
  const [failure, setFailure] = useState<'validation' | 'save' | null>(null)

  useEffect(() => {
    if (!assignment) return
    setEndAt(localDateTimeInput(assignment.start_at))
    setReason('')
    setFailure(null)
  }, [assignment])

  const mutation = useMutation({
    mutationFn: async ({ nextEndAt, nextReason }: { nextEndAt: string; nextReason: string }) =>
      unwrap<generated.Assignment>(
        await generated.endAssignment(
          assignment!.id,
          { end_at: nextEndAt, reason: nextReason },
          requestInit(token, { command: true, idempotency: 'assignment-end', lockVersion: assignment!.lock_version }),
        ),
      ),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['assignments'] })
      onEnded()
    },
    onError: () => setFailure('save'),
  })
  const submitting = mutation.isPending

  return (
    <AlertDialog open={assignment !== null} onOpenChange={(open) => { if (!open && !submitting) onClose() }}>
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>{text.endAssignmentTitle}</AlertDialogTitle>
          <AlertDialogDescription>{text.endReasonHelp}</AlertDialogDescription>
        </AlertDialogHeader>
        <div className="grid gap-4">
          <div className="grid gap-2">
            <Label htmlFor="org-assignment-end-at">{text.endAt}</Label>
            <Input
              id="org-assignment-end-at"
              type="datetime-local"
              value={endAt}
              onChange={(event) => setEndAt(event.target.value)}
            />
          </div>
          <div className="grid gap-2">
            <Label htmlFor="org-assignment-end-reason">{text.endReason}</Label>
            <Input
              id="org-assignment-end-reason"
              value={reason}
              onChange={(event) => setReason(event.target.value)}
            />
          </div>
          {failure === 'validation' ? (
            <p className="text-destructive text-sm" role="alert">{text.validation}</p>
          ) : failure === 'save' ? (
            <p className="text-destructive text-sm" role="alert">{text.saveError}</p>
          ) : null}
        </div>
        <AlertDialogFooter>
          <AlertDialogCancel disabled={submitting}>{text.cancel}</AlertDialogCancel>
          <AlertDialogAction
            disabled={submitting}
            onClick={() => {
              if (!endAt || !reason.trim()) {
                setFailure('validation')
                return
              }
              setFailure(null)
              mutation.mutate({ nextEndAt: toUtcIso(endAt) ?? endAt, nextReason: reason.trim() })
            }}
          >
            {submitting ? text.saving : text.confirm}
          </AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  )
}

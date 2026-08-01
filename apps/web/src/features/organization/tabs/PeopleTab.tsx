import { useEffect, useState, type FormEvent } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useLocale, useSessionToken } from '../../../app/session-context'
import { useAssignments, usePeople, usePositions } from '../../../api/hooks'
import { ApiError, requestInit, stateFromError, unwrap } from '../../../api/http'
import { formatDate, type Locale } from '../../../i18n'
import {
  Button,
  Drawer,
  EmptyState,
  Field,
  InlineError,
  Panel,
  PanelGrid,
  Select,
  SkeletonList,
  StatusBadge,
} from '../../../ui'
import * as generated from '../../../api/generated/cluster'
import { organizationCopy } from '../organization-copy'
import { localDateTimeInput, toUtcIso, useCapabilities } from '../organization-utils'

/* ------------------------------------------------------------------ */
/* People tab                                                          */
/* ------------------------------------------------------------------ */

export function PeopleTab() {
  const locale = useLocale()
  const text = organizationCopy[locale]
  const capabilities = useCapabilities()
  const peopleQuery = usePeople()
  const positionsQuery = usePositions()
  const assignmentsQuery = useAssignments()
  const [notice, setNotice] = useState<string | null>(null)
  const [personDrawer, setPersonDrawer] = useState<{
    open: boolean
    person: generated.Person | null
  }>({ open: false, person: null })
  const [assignmentDrawerOpen, setAssignmentDrawerOpen] = useState(false)
  const [endingAssignment, setEndingAssignment] =
    useState<generated.Assignment | null>(null)

  const canManagePerson = capabilities.includes('organization.person.manage')
  const canManageAssignment = capabilities.includes(
    'organization.assignment.manage',
  )

  const people =
    (peopleQuery.data as generated.PersonCollection | undefined)?.items ?? []
  const positions =
    (positionsQuery.data as generated.PositionCollection | undefined)?.items ??
    []
  const assignments =
    (assignmentsQuery.data as generated.AssignmentCollection | undefined)
      ?.items ?? []
  const loading =
    peopleQuery.isLoading ||
    positionsQuery.isLoading ||
    assignmentsQuery.isLoading
  const loadError =
    peopleQuery.error ?? positionsQuery.error ?? assignmentsQuery.error
  const state: 'ready' | 'forbidden' | 'error' = loadError
    ? stateFromError(loadError) === 'forbidden'
      ? 'forbidden'
      : 'error'
    : 'ready'
  const retry = () => {
    void peopleQuery.refetch()
    void positionsQuery.refetch()
    void assignmentsQuery.refetch()
  }

  const canRead = capabilities.includes('organization.person.read')
  if (!canRead) return <EmptyState title={text.unavailable} />

  return (
    <>
      {notice ? (
        <p role="status" className="status-message status-message--success">
          {notice}
        </p>
      ) : null}
      {loading ? <SkeletonList rows={3} /> : null}
      {!loading && state === 'forbidden' ? (
        <div className="state-panel" role="status">
          <p>{text.unavailable}</p>
        </div>
      ) : null}
      {!loading && state === 'error' ? (
        <InlineError
          message={text.error}
          retryLabel={text.retry}
          onRetry={retry}
        />
      ) : null}
      {!loading && state === 'ready' ? (
        <PanelGrid>
          <Panel
            id="people-panel-heading"
            title={text.people}
            actions={
              canManagePerson ? (
                <Button
                  onClick={() => setPersonDrawer({ open: true, person: null })}
                >
                  {text.addPerson}
                </Button>
              ) : undefined
            }
          >
            {people.length === 0 ? (
              <EmptyState
                title={text.noPeople}
                action={
                  canManagePerson ? (
                    <Button
                      onClick={() =>
                        setPersonDrawer({ open: true, person: null })
                      }
                    >
                      {text.addPerson}
                    </Button>
                  ) : undefined
                }
              />
            ) : (
              <div className="screen-list">
                {people.map((person) => (
                  <div className="screen-list__row" key={person.id}>
                    <div>
                      <div className="screen-list__row-title">
                        {locale === 'en' && person.display_name_en
                          ? person.display_name_en
                          : person.display_name_ar}
                      </div>
                      <div className="screen-list__row-meta" dir="ltr">
                        {text.employeeNumber}: {person.employee_number}
                      </div>
                    </div>
                    <div className="screen-list__row-actions">
                      <StatusBadge
                        variant={
                          person.status === 'active' ? 'success' : 'neutral'
                        }
                      >
                        {personStatusLabel(locale, person.status)}
                      </StatusBadge>
                      {canManagePerson ? (
                        <Button
                          variant="secondary"
                          onClick={() =>
                            setPersonDrawer({ open: true, person })
                          }
                        >
                          {text.edit}
                        </Button>
                      ) : null}
                    </div>
                  </div>
                ))}
              </div>
            )}
          </Panel>
          <Panel
            id="assignments-panel-heading"
            title={text.assignments}
            actions={
              canManageAssignment ? (
                <Button onClick={() => setAssignmentDrawerOpen(true)}>
                  {text.createAssignment}
                </Button>
              ) : undefined
            }
          >
            {assignments.length === 0 ? (
              <EmptyState
                title={text.noAssignments}
                action={
                  canManageAssignment ? (
                    <Button onClick={() => setAssignmentDrawerOpen(true)}>
                      {text.createAssignment}
                    </Button>
                  ) : undefined
                }
              />
            ) : (
              <div className="screen-list">
                {assignments.map((assignment) => {
                  const person = people.find(
                    (item) => item.id === assignment.person_id,
                  )
                  const position = positions.find(
                    (item) => item.id === assignment.position_id,
                  )
                  return (
                    <div className="screen-list__row" key={assignment.id}>
                      <div>
                        <div className="screen-list__row-title">
                          {person
                            ? locale === 'en' && person.display_name_en
                              ? person.display_name_en
                              : person.display_name_ar
                            : ''}
                          {assignment.is_primary ? (
                            <StatusBadge variant="info">
                              {text.primary}
                            </StatusBadge>
                          ) : null}
                        </div>
                        <div className="screen-list__row-meta">
                          {text.position}:{' '}
                          {position?.title_ar ?? position?.code ?? ''}
                        </div>
                        <div className="screen-list__row-meta">
                          {text.startAt}:{' '}
                          {formatDate(assignment.start_at, locale)}
                          {assignment.end_at
                            ? ` · ${text.endAt}: ${formatDate(assignment.end_at, locale)}`
                            : ''}
                        </div>
                      </div>
                      <div className="screen-list__row-actions">
                        <StatusBadge
                          variant={
                            assignment.status === 'active'
                              ? 'success'
                              : 'neutral'
                          }
                        >
                          {assignmentStatusLabel(locale, assignment.status)}
                        </StatusBadge>
                        {canManageAssignment &&
                        (assignment.status === 'active' ||
                          assignment.status === 'pending') ? (
                          <Button
                            variant="secondary"
                            onClick={() => setEndingAssignment(assignment)}
                          >
                            {text.endAssignment}
                          </Button>
                        ) : null}
                      </div>
                    </div>
                  )
                })}
              </div>
            )}
          </Panel>
        </PanelGrid>
      ) : null}
      <PersonDrawer
        open={personDrawer.open}
        person={personDrawer.person}
        onClose={() => setPersonDrawer({ open: false, person: null })}
        onSaved={() => {
          setPersonDrawer({ open: false, person: null })
          setNotice(text.personSaved)
        }}
      />
      <AssignmentDrawer
        open={assignmentDrawerOpen}
        onClose={() => setAssignmentDrawerOpen(false)}
        people={people}
        positions={positions}
        onSaved={() => {
          setAssignmentDrawerOpen(false)
          setNotice(text.assignmentSaved)
        }}
      />
      <EndAssignmentDrawer
        open={endingAssignment !== null}
        assignment={endingAssignment}
        onClose={() => setEndingAssignment(null)}
        onEnded={() => {
          setEndingAssignment(null)
          setNotice(text.assignmentEnded)
        }}
      />
    </>
  )
}

function personStatusLabel(
  locale: Locale,
  status: generated.PersonStatus,
): string {
  const text = organizationCopy[locale]
  return status === 'active'
    ? text.active
    : status === 'suspended'
      ? text.suspended
      : text.left
}

function assignmentStatusLabel(
  locale: Locale,
  status: generated.AssignmentStatus,
): string {
  const text = organizationCopy[locale]
  return status === 'active'
    ? text.active
    : status === 'pending'
      ? text.pending
      : text.ended
}

function PersonDrawer({
  open,
  person,
  onClose,
  onSaved,
}: {
  open: boolean
  person: generated.Person | null
  onClose: () => void
  onSaved: (person: generated.Person) => void
}) {
  const locale = useLocale()
  const token = useSessionToken()
  const text = organizationCopy[locale]
  const queryClient = useQueryClient()
  const editing = person !== null
  const [employeeNumber, setEmployeeNumber] = useState('')
  const [nameAr, setNameAr] = useState('')
  const [nameEn, setNameEn] = useState('')
  const [status, setStatus] = useState<string>('active')
  const [failure, setFailure] = useState<
    'validation' | 'stale' | 'save' | null
  >(null)

  useEffect(() => {
    if (!open) return
    setEmployeeNumber(person?.employee_number ?? '')
    setNameAr(person?.display_name_ar ?? '')
    setNameEn(person?.display_name_en ?? '')
    setStatus(person?.status ?? 'active')
    setFailure(null)
  }, [open, person])

  const mutation = useMutation({
    mutationFn: async ({
      nextEmployeeNumber,
      nextNameAr,
      nextNameEn,
      nextStatus,
    }: {
      nextEmployeeNumber: string
      nextNameAr: string
      nextNameEn: string
      nextStatus: string
    }) => {
      if (editing && person) {
        return unwrap<generated.Person>(
          await generated.updatePerson(
            person.id,
            {
              display_name_ar: nextNameAr,
              display_name_en: nextNameEn.trim() || null,
              status: nextStatus as generated.PersonPatchStatus,
            },
            requestInit(token, {
              command: true,
              idempotency: 'person-update',
              lockVersion: person.person_version,
            }),
          ),
        )
      }
      return unwrap<generated.Person>(
        await generated.registerPerson(
          {
            employee_number: nextEmployeeNumber,
            display_name_ar: nextNameAr,
            display_name_en: nextNameEn.trim() || undefined,
            status: nextStatus as generated.PersonCreateStatus,
          },
          requestInit(token, { command: true, idempotency: 'person' }),
        ),
      )
    },
    onSuccess: (saved) => {
      void queryClient.invalidateQueries({ queryKey: ['people'] })
      onSaved(saved)
    },
    onError: (caught) => {
      setFailure(
        caught instanceof ApiError && caught.status === 412 ? 'stale' : 'save',
      )
    },
  })
  const submitting = mutation.isPending

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!nameAr.trim() || (!editing && !employeeNumber.trim())) {
      setFailure('validation')
      return
    }
    setFailure(null)
    mutation.mutate({
      nextEmployeeNumber: employeeNumber.trim(),
      nextNameAr: nameAr.trim(),
      nextNameEn: nameEn,
      nextStatus: status,
    })
  }

  const failureMessage =
    failure === 'validation'
      ? text.validation
      : failure === 'stale'
        ? text.stale
        : failure === 'save'
          ? text.saveError
          : null

  return (
    <Drawer
      open={open}
      onClose={() => {
        if (!submitting) onClose()
      }}
      title={editing ? text.editPersonTitle : text.createPersonTitle}
    >
      <form onSubmit={(event) => void submit(event)} noValidate>
        {failureMessage ? (
          <p className="error-summary" role="alert">
            {failureMessage}
          </p>
        ) : null}
        {!editing ? (
          <Field id="org-person-number" label={text.employeeNumber} required>
            <input
              id="org-person-number"
              dir="ltr"
              value={employeeNumber}
              required
              aria-required="true"
              aria-invalid={failure === 'validation' || undefined}
              onChange={(event) => setEmployeeNumber(event.target.value)}
            />
          </Field>
        ) : null}
        <Field id="org-person-name-ar" label={text.nameAr} required>
          <input
            id="org-person-name-ar"
            value={nameAr}
            required
            aria-required="true"
            aria-invalid={failure === 'validation' || undefined}
            onChange={(event) => setNameAr(event.target.value)}
          />
        </Field>
        <Field id="org-person-name-en" label={text.nameEn}>
          <input
            id="org-person-name-en"
            value={nameEn}
            onChange={(event) => setNameEn(event.target.value)}
          />
        </Field>
        <Field id="org-person-status" label={text.status}>
          <Select
            id="org-person-status"
            value={status}
            onChange={setStatus}
            options={[
              { value: 'active', label: text.active },
              { value: 'suspended', label: text.suspended },
              { value: 'left', label: text.left },
            ]}
          />
        </Field>
        <div className="form-actions">
          <Button
            type="button"
            variant="quiet"
            onClick={onClose}
            disabled={submitting}
          >
            {text.cancel}
          </Button>
          <Button type="submit" disabled={submitting}>
            {submitting ? text.saving : text.save}
          </Button>
        </div>
      </form>
    </Drawer>
  )
}

function AssignmentDrawer({
  open,
  onClose,
  people,
  positions,
  onSaved,
}: {
  open: boolean
  onClose: () => void
  people: generated.Person[]
  positions: generated.Position[]
  onSaved: (assignment: generated.Assignment) => void
}) {
  const locale = useLocale()
  const token = useSessionToken()
  const text = organizationCopy[locale]
  const queryClient = useQueryClient()
  const [personId, setPersonId] = useState('')
  const [positionId, setPositionId] = useState('')
  const [startAt, setStartAt] = useState('')
  const [endAt, setEndAt] = useState('')
  const [isPrimary, setIsPrimary] = useState(false)
  const [failure, setFailure] = useState<
    'validation' | 'empty' | 'save' | null
  >(null)

  useEffect(() => {
    if (!open) return
    setPersonId('')
    setPositionId('')
    setStartAt('')
    setEndAt('')
    setIsPrimary(false)
    setFailure(null)
  }, [open])

  const activePeople = people.filter((person) => person.status === 'active')
  const activePositions = positions.filter((position) => position.is_active)

  const mutation = useMutation({
    mutationFn: async ({
      nextPersonId,
      nextPositionId,
      nextStartIso,
      nextEndIso,
      nextIsPrimary,
    }: {
      nextPersonId: string
      nextPositionId: string
      nextStartIso: string
      nextEndIso: string | undefined
      nextIsPrimary: boolean
    }) =>
      unwrap<generated.Assignment>(
        await generated.createAssignment(
          {
            person_id: nextPersonId,
            position_id: nextPositionId,
            start_at: nextStartIso,
            end_at: nextEndIso,
            is_primary: nextIsPrimary || undefined,
          },
          requestInit(token, { command: true, idempotency: 'assignment' }),
        ),
      ),
    onSuccess: (created) => {
      void queryClient.invalidateQueries({ queryKey: ['assignments'] })
      onSaved(created)
    },
    onError: () => setFailure('save'),
  })
  const submitting = mutation.isPending

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const startIso = toUtcIso(startAt)
    if (!personId || !positionId || !startIso) {
      setFailure('validation')
      return
    }
    if (activePeople.length === 0 || activePositions.length === 0) {
      setFailure('empty')
      return
    }
    setFailure(null)
    mutation.mutate({
      nextPersonId: personId,
      nextPositionId: positionId,
      nextStartIso: startIso,
      nextEndIso: toUtcIso(endAt),
      nextIsPrimary: isPrimary,
    })
  }

  const failureMessage =
    failure === 'validation'
      ? text.validation
      : failure === 'empty'
        ? activePeople.length === 0 && activePositions.length === 0
          ? text.noActivePeople
          : text.noActivePositions
        : failure === 'save'
          ? text.saveError
          : null

  return (
    <Drawer
      open={open}
      onClose={() => {
        if (!submitting) onClose()
      }}
      title={text.createAssignmentTitle}
    >
      <form onSubmit={(event) => void submit(event)} noValidate>
        {failureMessage ? (
          <p className="error-summary" role="alert">
            {failureMessage}
          </p>
        ) : null}
        <Field id="org-assignment-person" label={text.person} required>
          <Select
            id="org-assignment-person"
            value={personId}
            onChange={setPersonId}
            options={people.map((person) => ({
              value: person.id,
              label:
                locale === 'en' && person.display_name_en
                  ? person.display_name_en
                  : person.display_name_ar,
            }))}
          />
        </Field>
        <Field id="org-assignment-position" label={text.position} required>
          <Select
            id="org-assignment-position"
            value={positionId}
            onChange={setPositionId}
            options={positions.map((position) => ({
              value: position.id,
              label: position.title_ar,
            }))}
          />
        </Field>
        <Field id="org-assignment-start" label={text.startAt} required>
          <input
            id="org-assignment-start"
            type="datetime-local"
            value={startAt}
            required
            aria-required="true"
            onChange={(event) => setStartAt(event.target.value)}
          />
        </Field>
        <Field id="org-assignment-end" label={text.endAt}>
          <input
            id="org-assignment-end"
            type="datetime-local"
            value={endAt}
            onChange={(event) => setEndAt(event.target.value)}
          />
        </Field>
        <label className="field__label" htmlFor="org-assignment-primary">
          <input
            id="org-assignment-primary"
            type="checkbox"
            checked={isPrimary}
            onChange={(event) => setIsPrimary(event.target.checked)}
          />{' '}
          {text.primary}
        </label>
        <div className="form-actions">
          <Button
            type="button"
            variant="quiet"
            onClick={onClose}
            disabled={submitting}
          >
            {text.cancel}
          </Button>
          <Button type="submit" disabled={submitting}>
            {submitting ? text.saving : text.save}
          </Button>
        </div>
      </form>
    </Drawer>
  )
}

function EndAssignmentDrawer({
  open,
  assignment,
  onClose,
  onEnded,
}: {
  open: boolean
  assignment: generated.Assignment | null
  onClose: () => void
  onEnded: (assignment: generated.Assignment) => void
}) {
  const locale = useLocale()
  const token = useSessionToken()
  const text = organizationCopy[locale]
  const queryClient = useQueryClient()
  const [endAt, setEndAt] = useState('')
  const [reason, setReason] = useState('')
  const [failure, setFailure] = useState<
    'validation' | 'stale' | 'save' | null
  >(null)

  useEffect(() => {
    if (!open) return
    setEndAt(localDateTimeInput(new Date().toISOString()))
    setReason('')
    setFailure(null)
  }, [open, assignment])

  const mutation = useMutation({
    mutationFn: async ({
      nextEndIso,
      nextReason,
    }: {
      nextEndIso: string
      nextReason: string
    }) => {
      if (!assignment) throw new Error('Assignment is not available')
      return unwrap<generated.Assignment>(
        await generated.endAssignment(
          assignment.id,
          { end_at: nextEndIso, reason: nextReason },
          requestInit(token, {
            command: true,
            idempotency: 'assignment-end',
            lockVersion: assignment.lock_version,
          }),
        ),
      )
    },
    onSuccess: (ended) => {
      void queryClient.invalidateQueries({ queryKey: ['assignments'] })
      onEnded(ended)
    },
    onError: (caught) => {
      setFailure(
        caught instanceof ApiError && caught.status === 412 ? 'stale' : 'save',
      )
    },
  })
  const submitting = mutation.isPending

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!assignment) return
    const endIso = toUtcIso(endAt)
    if (
      !endIso ||
      !reason.trim() ||
      (assignment.start_at &&
        new Date(endIso).getTime() <= new Date(assignment.start_at).getTime())
    ) {
      setFailure('validation')
      return
    }
    setFailure(null)
    mutation.mutate({ nextEndIso: endIso, nextReason: reason.trim() })
  }

  const failureMessage =
    failure === 'validation'
      ? text.endAtRequired
      : failure === 'stale'
        ? text.stale
        : failure === 'save'
          ? text.saveError
          : null

  return (
    <Drawer
      open={open}
      onClose={() => {
        if (!submitting) onClose()
      }}
      title={text.endAssignmentTitle}
    >
      <form onSubmit={(event) => void submit(event)} noValidate>
        {failureMessage ? (
          <p className="error-summary" role="alert">
            {failureMessage}
          </p>
        ) : null}
        <Field id="org-assignment-end-at" label={text.endAt} required>
          <input
            id="org-assignment-end-at"
            type="datetime-local"
            value={endAt}
            required
            aria-required="true"
            onChange={(event) => setEndAt(event.target.value)}
          />
        </Field>
        <Field
          id="org-assignment-end-reason"
          label={text.endReason}
          required
          help={text.endReasonHelp}
        >
          <input
            id="org-assignment-end-reason"
            value={reason}
            required
            aria-required="true"
            onChange={(event) => setReason(event.target.value)}
          />
        </Field>
        <div className="form-actions">
          <Button
            type="button"
            variant="quiet"
            onClick={onClose}
            disabled={submitting}
          >
            {text.cancel}
          </Button>
          <Button type="submit" disabled={submitting}>
            {submitting ? text.saving : text.save}
          </Button>
        </div>
      </form>
    </Drawer>
  )
}

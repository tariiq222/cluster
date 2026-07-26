import { type FormEvent, useEffect, useRef, useState } from 'react'

import { endAssignment, type Assignment } from '../../api'
import { Button, Drawer, Field } from '../../ui'
import { peopleAssignmentsCopy, type OrganizationLocale } from './PeopleAssignments'
import {
  classifyOrganizationMutationFailure,
  type OrganizationMutationFailure,
} from './organization-mutation-error'


export function EndAssignmentDrawer({
  open,
  assignment,
  locale,
  token,
  onClose,
  onEnded,
}: {
  readonly open: boolean
  readonly assignment: Assignment | null
  readonly locale: OrganizationLocale
  readonly token: string
  readonly onClose: () => void
  readonly onEnded: (assignment: Assignment) => void
}) {
  const text = peopleAssignmentsCopy[locale]
  const [endAt, setEndAt] = useState('')
  const [reason, setReason] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [validationError, setValidationError] = useState(false)
  const [failure, setFailure] = useState<OrganizationMutationFailure | null>(null)
  const errorRef = useRef<HTMLParagraphElement>(null)

  useEffect(() => {
    if (!open) return
    setEndAt('')
    setReason('')
    setValidationError(false)
    setFailure(null)
  }, [open, assignment])

  useEffect(() => {
    if (failure === null || submitting) return
    const focusTimer = window.requestAnimationFrame(() => errorRef.current?.focus())
    return () => window.cancelAnimationFrame(focusTimer)
  }, [failure, submitting])

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setValidationError(false)
    setFailure(null)
    if (assignment === null) return
    const end = new Date(endAt)
    const start = new Date(assignment.start_at)
    if (!endAt || Number.isNaN(end.valueOf()) || end <= start || !reason.trim()) {
      setValidationError(true)
      window.requestAnimationFrame(() => errorRef.current?.focus())
      return
    }
    setSubmitting(true)
    try {
      onEnded(await endAssignment(token, assignment.id, assignment.lock_version, { end_at: end.toISOString(), reason: reason.trim() }))
    } catch (caught) {
      setFailure(classifyOrganizationMutationFailure(caught))
    } finally {
      setSubmitting(false)
    }
  }

  const failureMessage = failure?.kind === 'conflict'
    ? failure.message
    : failure?.kind === 'stale'
      ? text.stale
      : failure?.kind === 'save'
        ? text.saveError
        : null
  return (
    <Drawer open={open} onClose={onClose} title={text.endAssignment} ariaLabelClose={text.close} dismissable={!submitting}>
      <form className="org-drawer-form" onSubmit={(event) => void submit(event)} noValidate>
        {validationError ? <p ref={errorRef} className="error-summary" role="alert" tabIndex={-1}>{text.endAtRequired}</p> : null}
        {failure ? <p data-testid="org-drawer-alert" ref={errorRef} className="error-summary" role="alert" tabIndex={-1}>{failureMessage}</p> : null}
        <Field id="assignment-end-at" label={text.endAt} required error={validationError && !endAt ? text.endAtRequired : undefined}><input id="assignment-end-at" aria-label={text.endAt} type="datetime-local" value={endAt} required aria-required="true" aria-invalid={validationError && !endAt || undefined} onChange={(event) => setEndAt(event.target.value)} /></Field>
        <Field id="assignment-end-reason" label={text.endReason} required error={validationError && !reason.trim() ? text.endAtRequired : undefined}><input id="assignment-end-reason" aria-label={text.endReason} value={reason} required aria-required="true" aria-invalid={validationError && !reason.trim() || undefined} onChange={(event) => setReason(event.target.value)} /></Field>
        <div className="org-drawer-form-footer"><Button variant="quiet" onClick={onClose} disabled={submitting}>{text.cancel}</Button><Button type="submit" disabled={submitting}>{submitting ? text.ending : text.endAssignment}</Button></div>
      </form>
    </Drawer>
  )
}

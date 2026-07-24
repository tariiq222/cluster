import { type FormEvent, useEffect, useRef, useState } from 'react'

import { ApiError, endAssignment, type Assignment } from '../../api'
import { Button, Drawer, Field } from '../../ui'
import { peopleAssignmentsCopy, type OrganizationLocale } from './PeopleAssignments'

type EndError = 'validation' | 'stale' | 'save' | null

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
  const [error, setError] = useState<EndError>(null)
  const errorRef = useRef<HTMLParagraphElement>(null)

  useEffect(() => {
    if (!open) return
    setEndAt('')
    setReason('')
    setError(null)
  }, [open, assignment])

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (assignment === null) return
    const end = new Date(endAt)
    const start = new Date(assignment.start_at)
    if (!endAt || Number.isNaN(end.valueOf()) || end <= start || !reason.trim()) {
      setError('validation')
      window.requestAnimationFrame(() => errorRef.current?.focus())
      return
    }
    setSubmitting(true)
    setError(null)
    try {
      onEnded(await endAssignment(token, assignment.id, assignment.lock_version, { end_at: end.toISOString(), reason: reason.trim() }))
    } catch (caught) {
      setError(caught instanceof ApiError && (caught.status === 409 || caught.status === 412) ? 'stale' : 'save')
      window.requestAnimationFrame(() => errorRef.current?.focus())
    } finally {
      setSubmitting(false)
    }
  }

  const errorMessage = error === 'validation' ? text.endAtRequired : error === 'stale' ? text.stale : error === 'save' ? text.saveError : null
  return (
    <Drawer open={open} onClose={onClose} title={text.endAssignment} ariaLabelClose={text.close} dismissable={!submitting}>
      <form className="org-drawer-form" onSubmit={(event) => void submit(event)} noValidate>
        {errorMessage ? <p ref={errorRef} className="error-summary" role="alert" tabIndex={-1}>{errorMessage}</p> : null}
        <Field id="assignment-end-at" label={text.endAt} required error={error === 'validation' && !endAt ? text.endAtRequired : undefined}><input id="assignment-end-at" aria-label={text.endAt} type="datetime-local" value={endAt} required aria-required="true" aria-invalid={error === 'validation' && !endAt || undefined} onChange={(event) => setEndAt(event.target.value)} /></Field>
        <Field id="assignment-end-reason" label={text.endReason} required error={error === 'validation' && !reason.trim() ? text.endAtRequired : undefined}><input id="assignment-end-reason" aria-label={text.endReason} value={reason} required aria-required="true" aria-invalid={error === 'validation' && !reason.trim() || undefined} onChange={(event) => setReason(event.target.value)} /></Field>
        <div className="org-drawer-form-footer"><Button variant="quiet" onClick={onClose} disabled={submitting}>{text.cancel}</Button><Button type="submit" disabled={submitting}>{submitting ? text.ending : text.endAssignment}</Button></div>
      </form>
    </Drawer>
  )
}

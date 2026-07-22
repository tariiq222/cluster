import { type FormEvent, useEffect, useRef, useState } from 'react'

import { createAssignment, type Assignment, type Person, type Position } from '../../api'
import { Button, Drawer, Field, Select } from '../../ui'
import { peopleAssignmentsCopy, type OrganizationLocale } from './PeopleAssignments'

export function AssignmentDrawer({
  open,
  locale,
  token,
  people,
  positions,
  onClose,
  onCreated,
}: {
  readonly open: boolean
  readonly locale: OrganizationLocale
  readonly token: string
  readonly people: readonly Person[]
  readonly positions: readonly Position[]
  readonly onClose: () => void
  readonly onCreated: (assignment: Assignment) => void
}) {
  const text = peopleAssignmentsCopy[locale]
  const [personId, setPersonId] = useState('')
  const [positionId, setPositionId] = useState('')
  const [startAt, setStartAt] = useState('')
  const [endAt, setEndAt] = useState('')
  const [primary, setPrimary] = useState(true)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<'validation' | 'save' | null>(null)
  const errorRef = useRef<HTMLParagraphElement>(null)

  useEffect(() => {
    if (!open) return
    setPersonId(people[0]?.id ?? '')
    setPositionId(positions[0]?.id ?? '')
    setStartAt('')
    setEndAt('')
    setPrimary(true)
    setError(null)
  }, [open, people, positions])

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const start = new Date(startAt)
    const end = endAt ? new Date(endAt) : null
    if (!personId || !positionId || !startAt || Number.isNaN(start.valueOf()) || (end !== null && (Number.isNaN(end.valueOf()) || end <= start))) {
      setError('validation')
      window.requestAnimationFrame(() => errorRef.current?.focus())
      return
    }
    setSubmitting(true)
    setError(null)
    try {
      const payload = end === null
        ? { person_id: personId, position_id: positionId, start_at: start.toISOString(), is_primary: primary }
        : { person_id: personId, position_id: positionId, start_at: start.toISOString(), end_at: end.toISOString(), is_primary: primary }
      onCreated(await createAssignment(token, payload))
    } catch (caught) {
      setError('save')
      window.requestAnimationFrame(() => errorRef.current?.focus())
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Drawer open={open} onClose={onClose} title={text.createAssignment} ariaLabelClose={text.close} dismissable={!submitting}>
      <form className="org-drawer-form" onSubmit={(event) => void submit(event)} noValidate>
        {error ? <p ref={errorRef} className="error-summary" role="alert" tabIndex={-1}>{error === 'validation' ? text.validation : text.saveError}</p> : null}
        <Field id="assignment-person" label={text.person} required><Select id="assignment-person" value={personId} onChange={setPersonId} options={people.map((person) => ({ value: person.id, label: personName(person, locale) }))} /></Field>
        <Field id="assignment-position" label={text.jobTitle} required><Select id="assignment-position" value={positionId} onChange={setPositionId} options={positions.map((position) => ({ value: position.id, label: position.title_ar }))} /></Field>
        <Field id="assignment-start" label={text.startAt} required error={error === 'validation' && !startAt ? text.validation : undefined}><input id="assignment-start" type="datetime-local" value={startAt} required aria-required="true" aria-invalid={error === 'validation' && !startAt || undefined} onChange={(event) => setStartAt(event.target.value)} /></Field>
        <Field id="assignment-end" label={text.endAt}><input id="assignment-end" type="datetime-local" value={endAt} onChange={(event) => setEndAt(event.target.value)} /></Field>
        <Field id="assignment-primary" label={text.primary} help={text.primaryHelp}><input id="assignment-primary" type="checkbox" checked={primary} onChange={(event) => setPrimary(event.target.checked)} /></Field>
        <div className="org-drawer-form-footer"><Button variant="quiet" onClick={onClose} disabled={submitting}>{text.cancel}</Button><Button type="submit" disabled={submitting}>{submitting ? text.creating : text.saveAssignment}</Button></div>
      </form>
    </Drawer>
  )
}

function personName(person: Person, locale: OrganizationLocale) {
  return locale === 'en' && person.display_name_en ? person.display_name_en : person.display_name_ar
}

import { type FormEvent, useEffect, useRef, useState } from 'react'

import { createPerson, updatePerson, type Person } from '../../api'
import { Button, Drawer, Field, Select } from '../../ui'
import { peopleAssignmentsCopy, type OrganizationLocale } from './PeopleAssignments'
import {
  classifyOrganizationMutationFailure,
  type OrganizationMutationFailure,
} from './organization-mutation-error'


export function PersonDrawer({
  open,
  person,
  locale,
  token,
  onClose,
  onSaved,
}: {
  readonly open: boolean
  readonly person: Person | null
  readonly locale: OrganizationLocale
  readonly token: string
  readonly onClose: () => void
  readonly onSaved: (person: Person) => void
}) {
  const text = peopleAssignmentsCopy[locale]
  const [employeeNumber, setEmployeeNumber] = useState('')
  const [nameAr, setNameAr] = useState('')
  const [nameEn, setNameEn] = useState('')
  const [status, setStatus] = useState<Person['status']>('active')
  const [submitting, setSubmitting] = useState(false)
  const [validationError, setValidationError] = useState(false)
  const [failure, setFailure] = useState<OrganizationMutationFailure | null>(null)
  const errorRef = useRef<HTMLParagraphElement>(null)
  const editing = person !== null

  useEffect(() => {
    if (!open) return
    setEmployeeNumber('')
    setNameAr(person?.display_name_ar ?? '')
    setNameEn(person?.display_name_en ?? '')
    setStatus(person?.status ?? 'active')
    setValidationError(false)
    setFailure(null)
  }, [open, person])

  useEffect(() => {
    if (failure === null || submitting) return
    const focusTimer = window.requestAnimationFrame(() => errorRef.current?.focus())
    return () => window.cancelAnimationFrame(focusTimer)
  }, [failure, submitting])

  function close() {
    if (!submitting) onClose()
  }

  function changeStatus(value: string) {
    if (isPersonStatus(value)) setStatus(value)
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setValidationError(false)
    setFailure(null)
    if (!nameAr.trim() || (!editing && !employeeNumber.trim())) {
      setValidationError(true)
      window.requestAnimationFrame(() => errorRef.current?.focus())
      return
    }
    setSubmitting(true)
    try {
      const saved = person === null
        ? await createPerson(token, { employee_number: employeeNumber.trim(), display_name_ar: nameAr.trim(), display_name_en: nameEn.trim() || undefined, status })
        : await updatePerson(token, person.id, person.person_version, { display_name_ar: nameAr.trim(), display_name_en: nameEn.trim() || null, status })
      onSaved(saved)
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
    <Drawer open={open} onClose={close} title={editing ? text.editPerson : text.addPerson} ariaLabelClose={text.close} dismissable={!submitting}>
      <form className="org-drawer-form" onSubmit={(event) => void submit(event)} noValidate>
        {validationError ? <p ref={errorRef} className="error-summary" role="alert" tabIndex={-1}>{text.validation}</p> : null}
        {failure ? <p data-testid="org-drawer-alert" ref={errorRef} className="error-summary" role="alert" tabIndex={-1}>{failureMessage}</p> : null}
        {!editing ? <Field id="person-employee-number" label={text.employeeNumber} required error={validationError && !employeeNumber.trim() ? text.validation : undefined}><input id="person-employee-number" dir="ltr" value={employeeNumber} required aria-required="true" aria-invalid={validationError && !employeeNumber.trim() || undefined} onChange={(event) => setEmployeeNumber(event.target.value)} /></Field> : null}
        <Field id="person-name-ar" label={text.nameAr} required error={validationError && !nameAr.trim() ? text.validation : undefined}><input id="person-name-ar" value={nameAr} required aria-required="true" aria-invalid={validationError && !nameAr.trim() || undefined} onChange={(event) => setNameAr(event.target.value)} /></Field>
        <Field id="person-name-en" label={text.nameEn}><input id="person-name-en" value={nameEn} onChange={(event) => setNameEn(event.target.value)} /></Field>
        <Field id="person-status" label={text.status}><Select id="person-status" value={status} onChange={changeStatus} options={[{ value: 'active', label: text.active }, { value: 'suspended', label: text.suspended }, { value: 'left', label: text.left }]} /></Field>
        <div className="org-drawer-form-footer"><Button variant="quiet" onClick={close} disabled={submitting}>{text.cancel}</Button><Button type="submit" disabled={submitting}>{submitting ? text.saving : editing ? text.savePerson : text.addPerson}</Button></div>
      </form>
    </Drawer>
  )
}

function isPersonStatus(value: string): value is Person['status'] {
  return value === 'active' || value === 'suspended' || value === 'left'
}

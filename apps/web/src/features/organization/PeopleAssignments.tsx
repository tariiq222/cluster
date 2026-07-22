import { type FormEvent, useEffect, useRef, useState } from 'react'
import { formattingLocale } from '../../app/copy'
import { useLocale, useToken } from '../../app/session-context'

import { CalendarClock, Users } from 'lucide-react'

import {
  ApiError,
  createAssignment,
  createPerson,
  endAssignment,
  listAssignments,
  listPeople,
  listPositions,
  updatePerson,
  type Assignment,
  type Person,
  type Position,
  type UpdatePersonInput,
  stateFromError,
} from '../../api'
import {
  Button,
  EmptyState,
  Field as UiField,
  InlineError,
  Page,
  PageHeader,
  Panel,
  PanelGrid,
  Select as UiSelect,
  SkeletonList,
} from '../../ui'

type Locale = 'ar' | 'en'

const copy = {
  ar: {
    title: 'الأشخاص والتكليفات', intro: 'سجل Person مستقل عن الحساب، وتكليفات زمنية مرتبطة بالمناصب.',
    loading: 'جارٍ تحميل الأشخاص والتكليفات…', forbidden: 'لا تملك صلاحية إدارة الأشخاص والتكليفات.',
    error: 'تعذر تحميل الأشخاص والتكليفات.', retry: 'إعادة المحاولة', people: 'الأشخاص', assignments: 'التكليفات',
    noPeople: 'لا يوجد أشخاص بعد.', noAssignments: 'لا توجد تكليفات بعد.', addPerson: 'إضافة شخص', addAssignment: 'إضافة تكليف',
    employeeNumber: 'الرقم الوظيفي', nameAr: 'الاسم بالعربية', nameEn: 'الاسم بالإنجليزية', status: 'الحالة',
    active: 'نشط', suspended: 'موقوف', left: 'غادر', archived: 'مؤرشف', person: 'الشخص', position: 'المنصب',
    startAt: 'بداية التكليف', endAt: 'نهاية التكليف', primary: 'تكليف أساسي', current: 'الحالة الحالية',
    pending: 'قادم', ended: 'منتهٍ', saving: 'جارٍ الحفظ…', validation: 'أكمل الحقول المطلوبة وتحقق من الفترة.',
    saveError: 'لم يُحفظ التغيير. راجع البيانات أو التداخل ثم أعد المحاولة.', noPositions: 'أنشئ منصباً نشطاً قبل إضافة تكليف.',
    edit: 'تحرير', cancel: 'إلغاء', save: 'حفظ', actions: 'الإجراءات', endAssignment: 'إنهاء التكليف', endReason: 'سبب الإنهاء', ending: 'جارٍ الإنهاء…', endedSuccess: 'تم إنهاء التكليف.', stale: 'تغيرت البيانات منذ فتحها. أعد تحميل القائمة.', endError: 'تعذر إنهاء التكليف.', endAtRequired: 'أدخل وقت نهاية التكليف وسبباً واضحاً.',
  },
  en: {
    title: 'People and assignments', intro: 'A Person registry separate from accounts, with dated position assignments.',
    loading: 'Loading people and assignments…', forbidden: 'You do not have permission to manage people and assignments.',
    error: 'People and assignments could not be loaded.', retry: 'Try again', people: 'People', assignments: 'Assignments',
    noPeople: 'No people yet.', noAssignments: 'No assignments yet.', addPerson: 'Add person', addAssignment: 'Add assignment',
    employeeNumber: 'Employee number', nameAr: 'Name in Arabic', nameEn: 'Name in English', status: 'Status',
    active: 'Active', suspended: 'Suspended', left: 'Left', archived: 'Archived', person: 'Person', position: 'Position',
    startAt: 'Assignment start', endAt: 'Assignment end', primary: 'Primary assignment', current: 'Current state',
    pending: 'Pending', ended: 'Ended', saving: 'Saving…', validation: 'Complete the required fields and check the assignment period.',
    saveError: 'The change was not saved. Review the data or overlap and try again.', noPositions: 'Create an active position before adding an assignment.',
    edit: 'Edit', cancel: 'Cancel', save: 'Save', actions: 'Actions', endAssignment: 'End assignment', endReason: 'End reason', ending: 'Ending…', endedSuccess: 'Assignment ended.', stale: 'The data changed since it was opened. Reload the list.', endError: 'The assignment could not be ended.', endAtRequired: 'Enter an end time and a clear reason.',
  },
} as const

export function PeopleAssignments() {
  const locale = useLocale()
  const token = useToken()
  const text = copy[locale]
  const [people, setPeople] = useState<Person[]>([])
  const [positions, setPositions] = useState<Position[]>([])
  const [assignments, setAssignments] = useState<Assignment[]>([])
  const [loading, setLoading] = useState(true)
  const [state, setState] = useState<'ready' | 'forbidden' | 'error'>('ready')

  async function load() {
    setLoading(true); setState('ready')
    try {
      const [peoplePage, positionPage, assignmentPage] = await Promise.all([listPeople(token), listPositions(token), listAssignments(token)])
      setPeople(peoplePage.items); setPositions(positionPage.items); setAssignments(assignmentPage.items)
    } catch (error) {
      setPeople([]); setPositions([]); setAssignments([])
      setState(stateFromError(error) === 'forbidden' ? 'forbidden' : 'error')
    } finally { setLoading(false) }
  }

  useEffect(() => {
    void load()
    // This route reloads only when the authenticated session changes.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [token])

  async function handlePersonUpdated(person: Person, patch: UpdatePersonInput): Promise<Person> {
    const updated = await updatePerson(token, person.id, person.person_version, patch)
    setPeople((current) => current.map((entry) => entry.id === updated.id ? updated : entry))
    return updated
  }

  async function handleAssignmentEnded(
    assignment: Assignment,
    endAt: string,
    reason: string,
  ): Promise<Assignment> {
    const updated = await endAssignment(token, assignment.id, assignment.lock_version, { end_at: endAt, reason })
    setAssignments((current) => current.map((entry) => entry.id === updated.id ? updated : entry))
    return updated
  }

  return <Page>
    <PageHeader id="people-heading" title={text.title} description={text.intro} />
    {loading && <SkeletonList label={text.loading} />}
    {!loading && state === 'forbidden' && <div className="state-panel" role="status"><p>{text.forbidden}</p></div>}
    {!loading && state === 'error' && <InlineError message={text.error} retryLabel={text.retry} onRetry={() => void load()} />}
    {!loading && state === 'ready' && <PanelGrid>
      <Panel
        id="people-list-heading"
        title={text.people}
        level={2}
        actions={<span className="count-badge">{number(people.length, locale)}</span>}
      >
        {people.length === 0 ? <EmptyState icon={<Users />} title={text.noPeople} /> : <PeopleTable people={people} locale={locale} onUpdate={handlePersonUpdated} />}
        <PersonForm locale={locale} token={token} onCreated={(person) => setPeople((current) => [...current, person])} />
      </Panel>
      <Panel
        id="assignments-list-heading"
        title={text.assignments}
        level={2}
        actions={<span className="count-badge">{number(assignments.length, locale)}</span>}
      >
        {assignments.length === 0 ? <EmptyState icon={<CalendarClock />} title={text.noAssignments} /> : <AssignmentsTable assignments={assignments} people={people} positions={positions} locale={locale} onEnd={handleAssignmentEnded} />}
        {people.some((person) => person.status === 'active') && positions.some((position) => position.is_active)
          ? <AssignmentForm locale={locale} token={token} people={people} positions={positions} onCreated={(assignment) => setAssignments((current) => [...current, assignment])} />
          : <p className="status-message" role="status">{text.noPositions}</p>}
      </Panel>
    </PanelGrid>}
  </Page>
}

function PeopleTable({ people, locale, onUpdate }: { people: Person[]; locale: Locale; onUpdate: (person: Person, patch: UpdatePersonInput) => Promise<Person> }) {
  const text = copy[locale]
  const [editingId, setEditingId] = useState<string | null>(null)
  return <div className="table-scroll" tabIndex={0} role="region" aria-label={text.people}><table><thead><tr><th scope="col">{text.employeeNumber}</th><th scope="col">{text.nameAr}</th><th scope="col">{text.status}</th><th scope="col">{text.actions}</th></tr></thead><tbody>{people.map((person) => editingId === person.id ? <PersonEditRow key={person.id} person={person} locale={locale} onSave={onUpdate} onClose={() => setEditingId(null)} /> : <tr key={person.id}><td dir="ltr">{person.employee_number}</td><td>{locale === 'en' && person.display_name_en ? person.display_name_en : person.display_name_ar}</td><td><span className="status-badge">{text[person.status]}</span></td><td><Button variant="quiet" type="button" onClick={() => setEditingId(person.id)}>{text.edit}</Button></td></tr>)}</tbody></table></div>
}

function PersonEditRow({ person, locale, onSave, onClose }: { person: Person; locale: Locale; onSave: (person: Person, patch: UpdatePersonInput) => Promise<Person>; onClose: () => void }) {
  const text = copy[locale]
  const [nameAr, setNameAr] = useState(person.display_name_ar)
  const [nameEn, setNameEn] = useState(person.display_name_en ?? '')
  const [status, setStatus] = useState<Person['status']>(person.status)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState(false)
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!nameAr.trim()) { setError(true); return }
    setSaving(true); setError(false)
    try { await onSave(person, { display_name_ar: nameAr.trim(), display_name_en: nameEn.trim() || null, status }); onClose() }
    catch { setError(true) }
    finally { setSaving(false) }
  }
  return <tr><td dir="ltr">{person.employee_number}</td><td colSpan={2}><form className="field-row" onSubmit={(event) => void submit(event)} noValidate>{error && <p className="error-summary" role="alert">{text.saveError}</p>}<Field id={`person-name-ar-${person.id}`} label={text.nameAr} value={nameAr} onChange={setNameAr} required /><Field id={`person-name-en-${person.id}`} label={text.nameEn} value={nameEn} onChange={setNameEn} /><UiField id={`person-status-${person.id}`} label={text.status}><UiSelect id={`person-status-${person.id}`} value={status} onChange={(next) => setStatus(next as Person['status'])} options={[{ value: 'active', label: text.active }, { value: 'suspended', label: text.suspended }, { value: 'left', label: text.left }]} /></UiField><Button type="submit" disabled={saving}>{saving ? text.saving : text.save}</Button><Button variant="quiet" type="button" onClick={onClose}>{text.cancel}</Button></form></td></tr>
}

function AssignmentsTable({ assignments, people, positions, locale, onEnd }: { assignments: Assignment[]; people: Person[]; positions: Position[]; locale: Locale; onEnd: (assignment: Assignment, endAt: string, reason: string) => Promise<Assignment> }) {
  const text = copy[locale]
  const peopleById = new Map(people.map((person) => [person.id, locale === 'en' && person.display_name_en ? person.display_name_en : person.display_name_ar]))
  const positionsById = new Map(positions.map((position) => [position.id, position.title_ar]))
  return <div className="table-scroll" tabIndex={0} role="region" aria-label={text.assignments}><table><thead><tr><th scope="col">{text.person}</th><th scope="col">{text.position}</th><th scope="col">{text.startAt}</th><th scope="col">{text.current}</th><th scope="col">{text.actions}</th></tr></thead><tbody>{assignments.map((assignment) => <AssignmentRow key={assignment.id} assignment={assignment} personName={peopleById.get(assignment.person_id) ?? '—'} positionName={positionsById.get(assignment.position_id) ?? '—'} locale={locale} onEnd={onEnd} />)}</tbody></table></div>
}

function AssignmentRow({ assignment, personName, positionName, locale, onEnd }: { assignment: Assignment; personName: string; positionName: string; locale: Locale; onEnd: (assignment: Assignment, endAt: string, reason: string) => Promise<Assignment> }) {
  const text = copy[locale]
  const [open, setOpen] = useState(false)
  const [endAt, setEndAt] = useState('')
  const [reason, setReason] = useState('')
  const [state, setState] = useState<'idle' | 'saving' | 'success' | 'error' | 'stale'>('idle')
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const end = new Date(endAt)
    const start = new Date(assignment.start_at)
    if (!endAt || Number.isNaN(end.valueOf()) || end <= start || !reason.trim()) { setState('error'); return }
    setState('saving')
    try { await onEnd(assignment, end.toISOString(), reason.trim()); setState('success'); setOpen(false) }
    catch (error) { setState(error instanceof ApiError && error.status === 412 ? 'stale' : 'error') }
  }
  return <>
    <tr><td>{personName}</td><td>{positionName}</td><td><time dateTime={assignment.start_at}>{date(assignment.start_at, locale)}</time></td><td><span className="status-badge">{text[assignment.status]}</span>{state === 'success' && <span className="status-message" role="status">{text.endedSuccess}</span>}{state === 'stale' && <span className="error-summary" role="alert">{text.stale}</span>}</td><td>{assignment.status === 'active' && <Button variant="quiet" type="button" onClick={() => setOpen((current) => !current)} disabled={state === 'saving'}>{state === 'saving' ? text.ending : text.endAssignment}</Button>}</td></tr>
    {open && assignment.status === 'active' && <tr><td colSpan={5}><form className="field-row" onSubmit={(event) => void submit(event)} noValidate>{state === 'error' && <p className="error-summary" role="alert">{text.endError} {text.endAtRequired}</p>}<Field id={`assignment-end-${assignment.id}`} label={text.endAt} type="datetime-local" value={endAt} onChange={setEndAt} required /><Field id={`assignment-reason-${assignment.id}`} label={text.endReason} value={reason} onChange={setReason} required /><Button type="submit" disabled={state === 'saving'}>{state === 'saving' ? text.ending : text.endAssignment}</Button><Button variant="quiet" type="button" onClick={() => setOpen(false)}>{text.cancel}</Button></form></td></tr>}
  </>
}

function PersonForm({ locale, token, onCreated }: { locale: Locale; token: string; onCreated: (person: Person) => void; }) {
  const text = copy[locale]
  const [employeeNumber, setEmployeeNumber] = useState('')
  const [nameAr, setNameAr] = useState('')
  const [nameEn, setNameEn] = useState('')
  const [status, setStatus] = useState<'active' | 'suspended' | 'left'>('active')
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<'validation' | 'save' | null>(null)
  const errorRef = useRef<HTMLParagraphElement>(null)
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!employeeNumber.trim() || !nameAr.trim()) { setError('validation'); window.requestAnimationFrame(() => errorRef.current?.focus()); return }
    setSubmitting(true); setError(null)
    try {
      const created = await createPerson(token, { employee_number: employeeNumber.trim(), display_name_ar: nameAr.trim(), display_name_en: nameEn.trim() || undefined, status })
      onCreated(created); setEmployeeNumber(''); setNameAr(''); setNameEn('')
    } catch {
      { setError('save'); window.requestAnimationFrame(() => errorRef.current?.focus()) }
    } finally { setSubmitting(false) }
  }
  return <form className="resource-form" onSubmit={(event) => void submit(event)} noValidate>
    {error && <p className="error-summary" role="alert" tabIndex={-1} ref={errorRef}>{error === 'validation' ? text.validation : text.saveError}</p>}
    <div className="field-row">
      <Field id="employee-number" label={text.employeeNumber} value={employeeNumber} onChange={setEmployeeNumber} required invalid={Boolean(error && !employeeNumber.trim())} />
      <Field id="person-name-ar" label={text.nameAr} value={nameAr} onChange={setNameAr} required invalid={Boolean(error && !nameAr.trim())} />
      <Field id="person-name-en" label={text.nameEn} value={nameEn} onChange={setNameEn} />
      <UiField id="person-status" label={text.status}>
        <UiSelect
          id="person-status"
          value={status}
          onChange={(next) => setStatus(next as typeof status)}
          options={[
            { value: 'active', label: text.active },
            { value: 'suspended', label: text.suspended },
            { value: 'left', label: text.left },
          ]}
        />
      </UiField>
    </div>
    <Button type="submit" disabled={submitting}>{submitting ? text.saving : text.addPerson}</Button>
  </form>
}

function AssignmentForm({ locale, token, people, positions, onCreated }: { locale: Locale; token: string; people: Person[]; positions: Position[]; onCreated: (assignment: Assignment) => void; }) {
  const text = copy[locale]
  const activePeople = people.filter((person) => person.status === 'active')
  const activePositions = positions.filter((position) => position.is_active)
  const [personId, setPersonId] = useState(activePeople[0]?.id ?? '')
  const [positionId, setPositionId] = useState(activePositions[0]?.id ?? '')
  const [startAt, setStartAt] = useState('')
  const [endAt, setEndAt] = useState('')
  const [primary, setPrimary] = useState(true)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<'validation' | 'save' | null>(null)
  const errorRef = useRef<HTMLParagraphElement>(null)
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const start = new Date(startAt)
    const end = endAt ? new Date(endAt) : null
    if (!personId || !positionId || !startAt || Number.isNaN(start.valueOf()) || (end && (Number.isNaN(end.valueOf()) || end <= start))) { setError('validation'); window.requestAnimationFrame(() => errorRef.current?.focus()); return }
    setSubmitting(true); setError(null)
    try {
      const created = await createAssignment(token, { person_id: personId, position_id: positionId, start_at: start.toISOString(), end_at: end?.toISOString(), is_primary: primary })
      onCreated(created); setStartAt(''); setEndAt('')
    } catch {
      { setError('save'); window.requestAnimationFrame(() => errorRef.current?.focus()) }
    } finally { setSubmitting(false) }
  }
  return <form className="resource-form" onSubmit={(event) => void submit(event)} noValidate>
    {error && <p className="error-summary" role="alert" tabIndex={-1} ref={errorRef}>{error === 'validation' ? text.validation : text.saveError}</p>}
    <div className="field-row"><Select id="assignment-person" label={text.person} value={personId} onChange={setPersonId} options={activePeople.map((person) => ({ value: person.id, label: person.display_name_ar }))} /><Select id="assignment-position" label={text.position} value={positionId} onChange={setPositionId} options={activePositions.map((position) => ({ value: position.id, label: position.title_ar }))} /><Field id="assignment-start" label={text.startAt} type="datetime-local" value={startAt} onChange={setStartAt} required invalid={Boolean(error && !startAt)} /><Field id="assignment-end" label={text.endAt} type="datetime-local" value={endAt} onChange={setEndAt} /><label className="checkbox-field"><input type="checkbox" checked={primary} onChange={(event) => setPrimary(event.target.checked)} />{text.primary}</label></div>
    <Button type="submit" disabled={submitting}>{submitting ? text.saving : text.addAssignment}</Button>
  </form>
}

function Field({ id, label, value, onChange, type = 'text', required = false, invalid = false }: { id: string; label: string; value: string; onChange: (value: string) => void; type?: string; required?: boolean; invalid?: boolean }) {
  return <UiField id={id} label={label} required={required}><input id={id} type={type} value={value} required={required} aria-required={required || undefined} aria-invalid={invalid} onChange={(event) => onChange(event.target.value)} /></UiField>
}
function Select({ id, label, value, onChange, options }: { id: string; label: string; value: string; onChange: (value: string) => void; options: Array<{ value: string; label: string }> }) {
  return <UiField id={id} label={label}><UiSelect id={id} value={value} onChange={onChange} options={options} /></UiField>
}
function number(value: number, locale: Locale) { return new Intl.NumberFormat(formattingLocale(locale)).format(value) }
function date(value: string, locale: Locale) { return new Intl.DateTimeFormat(formattingLocale(locale), { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Riyadh' }).format(new Date(value)) }

import { type FormEvent, useEffect, useRef, useState } from 'react'

import {
  ApiError,
  createAssignment,
  createPerson,
  listAssignments,
  listPeople,
  listPositions,
  type Assignment,
  type Person,
  type Position,
} from '../../api'

type Locale = 'ar' | 'en'

const copy = {
  ar: {
    title: 'الأشخاص والتكليفات', intro: 'سجل Person مستقل عن الحساب، وتكليفات زمنية مرتبطة بالمناصب.',
    loading: 'جارٍ تحميل الأشخاص والتكليفات…', forbidden: 'لا تملك صلاحية إدارة الأشخاص والتكليفات.',
    error: 'تعذر تحميل الأشخاص والتكليفات.', retry: 'إعادة المحاولة', people: 'الأشخاص', assignments: 'التكليفات',
    noPeople: 'لا يوجد أشخاص بعد.', noAssignments: 'لا توجد تكليفات بعد.', addPerson: 'إضافة شخص', addAssignment: 'إضافة تكليف',
    employeeNumber: 'الرقم الوظيفي', nameAr: 'الاسم بالعربية', nameEn: 'الاسم بالإنجليزية', status: 'الحالة',
    active: 'نشط', suspended: 'موقوف', left: 'غادر', person: 'الشخص', position: 'المنصب',
    startAt: 'بداية التكليف', endAt: 'نهاية التكليف', primary: 'تكليف أساسي', current: 'الحالة الحالية',
    pending: 'قادم', ended: 'منتهٍ', saving: 'جارٍ الحفظ…', validation: 'أكمل الحقول المطلوبة وتحقق من الفترة.',
    saveError: 'لم يُحفظ التغيير. راجع البيانات أو التداخل ثم أعد المحاولة.', noPositions: 'أنشئ منصباً نشطاً قبل إضافة تكليف.',
  },
  en: {
    title: 'People and assignments', intro: 'A Person registry separate from accounts, with dated position assignments.',
    loading: 'Loading people and assignments…', forbidden: 'You do not have permission to manage people and assignments.',
    error: 'People and assignments could not be loaded.', retry: 'Try again', people: 'People', assignments: 'Assignments',
    noPeople: 'No people yet.', noAssignments: 'No assignments yet.', addPerson: 'Add person', addAssignment: 'Add assignment',
    employeeNumber: 'Employee number', nameAr: 'Name in Arabic', nameEn: 'Name in English', status: 'Status',
    active: 'Active', suspended: 'Suspended', left: 'Left', person: 'Person', position: 'Position',
    startAt: 'Assignment start', endAt: 'Assignment end', primary: 'Primary assignment', current: 'Current state',
    pending: 'Pending', ended: 'Ended', saving: 'Saving…', validation: 'Complete the required fields and check the assignment period.',
    saveError: 'The change was not saved. Review the data or overlap and try again.', noPositions: 'Create an active position before adding an assignment.',
  },
} as const

export function PeopleAssignments({ locale, token, onSessionExpired }: { locale: Locale; token: string; onSessionExpired: () => void }) {
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
      if (error instanceof ApiError && error.status === 401) onSessionExpired()
      else if (error instanceof ApiError && error.status === 403) setState('forbidden')
      else setState('error')
    } finally { setLoading(false) }
  }

  useEffect(() => {
    void load()
    // This route reloads only when the authenticated session changes.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [token])

  return <section className="organization-page" aria-labelledby="people-heading">
    <div className="page-heading page-heading-copy"><div><h1 id="people-heading">{text.title}</h1><p>{text.intro}</p></div></div>
    {loading && <div className="skeleton-list" aria-label={text.loading}>{[0, 1, 2].map((item) => <div className="skeleton-row" aria-hidden="true" key={item} />)}</div>}
    {!loading && state !== 'ready' && <div className="state-panel" role={state === 'error' ? 'alert' : 'status'}><p>{state === 'forbidden' ? text.forbidden : text.error}</p>{state === 'error' && <button type="button" className="secondary-button" onClick={() => void load()}>{text.retry}</button>}</div>}
    {!loading && state === 'ready' && <div className="organization-layout">
      <section className="organization-section" aria-labelledby="people-list-heading">
        <div className="section-heading"><h2 id="people-list-heading">{text.people}</h2><span className="count-badge">{number(people.length, locale)}</span></div>
        {people.length === 0 ? <p>{text.noPeople}</p> : <PeopleTable people={people} locale={locale} />}
        <PersonForm locale={locale} token={token} onCreated={(person) => setPeople((current) => [...current, person])} onSessionExpired={onSessionExpired} />
      </section>
      <section className="organization-section" aria-labelledby="assignments-list-heading">
        <div className="section-heading"><h2 id="assignments-list-heading">{text.assignments}</h2><span className="count-badge">{number(assignments.length, locale)}</span></div>
        {assignments.length === 0 ? <p>{text.noAssignments}</p> : <AssignmentsTable assignments={assignments} people={people} positions={positions} locale={locale} />}
        {people.some((person) => person.status === 'active') && positions.some((position) => position.is_active)
          ? <AssignmentForm locale={locale} token={token} people={people} positions={positions} onCreated={(assignment) => setAssignments((current) => [...current, assignment])} onSessionExpired={onSessionExpired} />
          : <p className="status-message" role="status">{text.noPositions}</p>}
      </section>
    </div>}
  </section>
}

function PeopleTable({ people, locale }: { people: Person[]; locale: Locale }) {
  const text = copy[locale]
  return <div className="table-scroll" tabIndex={0} role="region" aria-label={text.people}><table><thead><tr><th scope="col">{text.employeeNumber}</th><th scope="col">{text.nameAr}</th><th scope="col">{text.status}</th></tr></thead><tbody>{people.map((person) => <tr key={person.id}><td dir="ltr">{person.employee_number}</td><td>{locale === 'en' && person.display_name_en ? person.display_name_en : person.display_name_ar}</td><td><span className="status-badge">{text[person.status]}</span></td></tr>)}</tbody></table></div>
}

function AssignmentsTable({ assignments, people, positions, locale }: { assignments: Assignment[]; people: Person[]; positions: Position[]; locale: Locale }) {
  const text = copy[locale]
  const peopleById = new Map(people.map((person) => [person.id, locale === 'en' && person.display_name_en ? person.display_name_en : person.display_name_ar]))
  const positionsById = new Map(positions.map((position) => [position.id, position.title_ar]))
  return <div className="table-scroll" tabIndex={0} role="region" aria-label={text.assignments}><table><thead><tr><th scope="col">{text.person}</th><th scope="col">{text.position}</th><th scope="col">{text.startAt}</th><th scope="col">{text.current}</th></tr></thead><tbody>{assignments.map((assignment) => <tr key={assignment.id}><td>{peopleById.get(assignment.person_id) ?? '—'}</td><td>{positionsById.get(assignment.position_id) ?? '—'}</td><td><time dateTime={assignment.start_at}>{date(assignment.start_at, locale)}</time></td><td><span className="status-badge">{text[assignment.status]}</span></td></tr>)}</tbody></table></div>
}

function PersonForm({ locale, token, onCreated, onSessionExpired }: { locale: Locale; token: string; onCreated: (person: Person) => void; onSessionExpired: () => void }) {
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
    } catch (failure) {
      if (failure instanceof ApiError && failure.status === 401) onSessionExpired()
      else { setError('save'); window.requestAnimationFrame(() => errorRef.current?.focus()) }
    } finally { setSubmitting(false) }
  }
  return <form className="resource-form" onSubmit={(event) => void submit(event)} noValidate>
    {error && <p className="error-summary" role="alert" tabIndex={-1} ref={errorRef}>{error === 'validation' ? text.validation : text.saveError}</p>}
    <div className="field-row"><Field id="employee-number" label={text.employeeNumber} value={employeeNumber} onChange={setEmployeeNumber} required invalid={Boolean(error && !employeeNumber.trim())} /><Field id="person-name-ar" label={text.nameAr} value={nameAr} onChange={setNameAr} required invalid={Boolean(error && !nameAr.trim())} /><Field id="person-name-en" label={text.nameEn} value={nameEn} onChange={setNameEn} /><div className="field"><label htmlFor="person-status">{text.status}</label><select id="person-status" value={status} onChange={(event) => setStatus(event.target.value as typeof status)}><option value="active">{text.active}</option><option value="suspended">{text.suspended}</option><option value="left">{text.left}</option></select></div></div>
    <button type="submit" className="primary-button" disabled={submitting}>{submitting ? text.saving : text.addPerson}</button>
  </form>
}

function AssignmentForm({ locale, token, people, positions, onCreated, onSessionExpired }: { locale: Locale; token: string; people: Person[]; positions: Position[]; onCreated: (assignment: Assignment) => void; onSessionExpired: () => void }) {
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
    } catch (failure) {
      if (failure instanceof ApiError && failure.status === 401) onSessionExpired()
      else { setError('save'); window.requestAnimationFrame(() => errorRef.current?.focus()) }
    } finally { setSubmitting(false) }
  }
  return <form className="resource-form" onSubmit={(event) => void submit(event)} noValidate>
    {error && <p className="error-summary" role="alert" tabIndex={-1} ref={errorRef}>{error === 'validation' ? text.validation : text.saveError}</p>}
    <div className="field-row"><Select id="assignment-person" label={text.person} value={personId} onChange={setPersonId} options={activePeople.map((person) => ({ value: person.id, label: person.display_name_ar }))} /><Select id="assignment-position" label={text.position} value={positionId} onChange={setPositionId} options={activePositions.map((position) => ({ value: position.id, label: position.title_ar }))} /><Field id="assignment-start" label={text.startAt} type="datetime-local" value={startAt} onChange={setStartAt} required invalid={Boolean(error && !startAt)} /><Field id="assignment-end" label={text.endAt} type="datetime-local" value={endAt} onChange={setEndAt} /><label className="checkbox-field"><input type="checkbox" checked={primary} onChange={(event) => setPrimary(event.target.checked)} />{text.primary}</label></div>
    <button type="submit" className="primary-button" disabled={submitting}>{submitting ? text.saving : text.addAssignment}</button>
  </form>
}

function Field({ id, label, value, onChange, type = 'text', required = false, invalid = false }: { id: string; label: string; value: string; onChange: (value: string) => void; type?: string; required?: boolean; invalid?: boolean }) {
  return <div className="field"><label htmlFor={id}>{label}{required && <span aria-hidden="true"> *</span>}</label><input id={id} type={type} value={value} required={required} aria-required={required || undefined} aria-invalid={invalid} onChange={(event) => onChange(event.target.value)} /></div>
}
function Select({ id, label, value, onChange, options }: { id: string; label: string; value: string; onChange: (value: string) => void; options: Array<{ value: string; label: string }> }) {
  return <div className="field"><label htmlFor={id}>{label}</label><select id={id} value={value} onChange={(event) => onChange(event.target.value)}>{options.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}</select></div>
}
function number(value: number, locale: Locale) { return new Intl.NumberFormat(locale === 'ar' ? 'ar-SA' : 'en-GB').format(value) }
function date(value: string, locale: Locale) { return new Intl.DateTimeFormat(locale === 'ar' ? 'ar-SA' : 'en-GB', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Riyadh' }).format(new Date(value)) }

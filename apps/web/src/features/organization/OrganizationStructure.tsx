import { type FormEvent, useEffect, useRef, useState } from 'react'

import {
  ApiError,
  createOrganizationUnit,
  createPosition,
  getCluster,
  listFacilities,
  listOrganizationUnits,
  listPositions,
  type Cluster,
  type Facility,
  type OrganizationUnit,
  type Position,
} from '../../api'

type Locale = 'ar' | 'en'

const copy = {
  ar: {
    title: 'الوحدات والمناصب', intro: 'بناء شجرة التنظيم وربط المناصب بوحداتها المعتمدة.',
    loading: 'جارٍ تحميل الهيكل التنظيمي…', forbidden: 'لا تملك صلاحية إدارة الهيكل التنظيمي.',
    error: 'تعذر تحميل الهيكل التنظيمي.', retry: 'إعادة المحاولة', units: 'الوحدات التنظيمية', positions: 'المناصب',
    noUnits: 'لا توجد وحدات بعد.', noPositions: 'لا توجد مناصب بعد.', addUnit: 'إضافة وحدة', addPosition: 'إضافة منصب',
    code: 'الرمز', name: 'الاسم بالعربية', type: 'النوع', parent: 'الوحدة الأم', root: 'جذر التجمع',
    titleAr: 'المسمى بالعربية', unit: 'الوحدة التنظيمية', manager: 'المنصب المدير', noManager: 'بلا منصب مدير',
    status: 'الحالة', active: 'نشط', depth: 'المستوى', saving: 'جارٍ الحفظ…',
    validation: 'أكمل الحقول المطلوبة بالصيغة الصحيحة.', saveError: 'لم يُحفظ التغيير. راجع البيانات ثم أعد المحاولة.',
    sector: 'قطاع', department: 'إدارة', section: 'قسم', unitType: 'وحدة',
  },
  en: {
    title: 'Units and positions', intro: 'Build the organization tree and attach positions to governed units.',
    loading: 'Loading organization structure…', forbidden: 'You do not have permission to manage organization structure.',
    error: 'Organization structure could not be loaded.', retry: 'Try again', units: 'Organization units', positions: 'Positions',
    noUnits: 'No organization units yet.', noPositions: 'No positions yet.', addUnit: 'Add unit', addPosition: 'Add position',
    code: 'Code', name: 'Name in Arabic', type: 'Type', parent: 'Parent', root: 'Cluster root',
    titleAr: 'Title in Arabic', unit: 'Organization unit', manager: 'Manager position', noManager: 'No manager position',
    status: 'Status', active: 'Active', depth: 'Depth', saving: 'Saving…',
    validation: 'Complete the required fields using the expected format.', saveError: 'The change was not saved. Review the data and try again.',
    sector: 'Sector', department: 'Department', section: 'Section', unitType: 'Unit',
  },
} as const

const unitTypes = [
  ['sector', 'sector'], ['department', 'department'], ['section', 'section'], ['unit', 'unitType'],
] as const

export function OrganizationStructure({ locale, token, onSessionExpired }: {
  locale: Locale
  token: string
  onSessionExpired: () => void
}) {
  const text = copy[locale]
  const [cluster, setCluster] = useState<Cluster | null>(null)
  const [facilities, setFacilities] = useState<Facility[]>([])
  const [units, setUnits] = useState<OrganizationUnit[]>([])
  const [positions, setPositions] = useState<Position[]>([])
  const [loading, setLoading] = useState(true)
  const [state, setState] = useState<'ready' | 'forbidden' | 'error'>('ready')

  async function load() {
    setLoading(true)
    setState('ready')
    try {
      const [clusterValue, facilityPage, unitPage, positionPage] = await Promise.all([
        getCluster(token), listFacilities(token), listOrganizationUnits(token), listPositions(token),
      ])
      setCluster(clusterValue)
      setFacilities(facilityPage.items)
      setUnits(unitPage.items)
      setPositions(positionPage.items)
    } catch (error) {
      setCluster(null)
      setFacilities([])
      setUnits([])
      setPositions([])
      if (error instanceof ApiError && error.status === 401) onSessionExpired()
      else if (error instanceof ApiError && error.status === 403) setState('forbidden')
      else setState('error')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    void load()
    // The structure page reloads only when the authenticated session changes.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [token])

  return <section className="organization-page" aria-labelledby="structure-heading">
    <div className="page-heading page-heading-copy"><div><h1 id="structure-heading">{text.title}</h1><p>{text.intro}</p></div></div>
    {loading && <div className="skeleton-list" aria-label={text.loading}>{[0, 1, 2].map((item) => <div className="skeleton-row" aria-hidden="true" key={item} />)}</div>}
    {!loading && state !== 'ready' && <div className="state-panel" role={state === 'error' ? 'alert' : 'status'}><p>{state === 'forbidden' ? text.forbidden : text.error}</p>{state === 'error' && <button type="button" className="secondary-button" onClick={() => void load()}>{text.retry}</button>}</div>}
    {!loading && state === 'ready' && cluster && <div className="organization-layout">
      <section className="organization-section" aria-labelledby="units-heading">
        <div className="section-heading"><h2 id="units-heading">{text.units}</h2><span className="count-badge">{formatNumber(units.length, locale)}</span></div>
        {units.length === 0 ? <p>{text.noUnits}</p> : <UnitTable units={units} locale={locale} />}
        <UnitForm locale={locale} token={token} cluster={cluster} facilities={facilities} units={units} onCreated={(unit) => setUnits((current) => [...current, unit])} onSessionExpired={onSessionExpired} />
      </section>
      <section className="organization-section" aria-labelledby="positions-heading">
        <div className="section-heading"><h2 id="positions-heading">{text.positions}</h2><span className="count-badge">{formatNumber(positions.length, locale)}</span></div>
        {positions.length === 0 ? <p>{text.noPositions}</p> : <PositionTable positions={positions} units={units} locale={locale} />}
        {units.length > 0 && <PositionForm locale={locale} token={token} units={units} positions={positions} onCreated={(position) => setPositions((current) => [...current, position])} onSessionExpired={onSessionExpired} />}
      </section>
    </div>}
  </section>
}

function UnitTable({ units, locale }: { units: OrganizationUnit[]; locale: Locale }) {
  const text = copy[locale]
  return <div className="table-scroll" tabIndex={0} role="region" aria-label={text.units}><table><thead><tr><th scope="col">{text.code}</th><th scope="col">{text.name}</th><th scope="col">{text.type}</th><th scope="col">{text.depth}</th><th scope="col">{text.status}</th></tr></thead><tbody>{units.map((unit) => <tr key={unit.id}><td dir="ltr">{unit.code}</td><td><span className="tree-label" style={{ paddingInlineStart: `${Math.min(unit.depth - 1, 5) * 16}px` }}>{unit.name_ar}</span></td><td>{unit.type_code}</td><td>{formatNumber(unit.depth, locale)}</td><td><span className="status-badge">{unit.status === 'active' ? text.active : unit.status}</span></td></tr>)}</tbody></table></div>
}

function PositionTable({ positions, units, locale }: { positions: Position[]; units: OrganizationUnit[]; locale: Locale }) {
  const text = copy[locale]
  const names = new Map(units.map((unit) => [unit.id, unit.name_ar]))
  return <div className="table-scroll" tabIndex={0} role="region" aria-label={text.positions}><table><thead><tr><th scope="col">{text.code}</th><th scope="col">{text.titleAr}</th><th scope="col">{text.unit}</th><th scope="col">{text.status}</th></tr></thead><tbody>{positions.map((position) => <tr key={position.id}><td dir="ltr">{position.code}</td><td>{position.title_ar}</td><td>{names.get(position.organization_unit_id) ?? '—'}</td><td><span className="status-badge">{position.is_active ? text.active : '—'}</span></td></tr>)}</tbody></table></div>
}

function UnitForm({ locale, token, cluster, facilities, units, onCreated, onSessionExpired }: {
  locale: Locale; token: string; cluster: Cluster; facilities: Facility[]; units: OrganizationUnit[]
  onCreated: (unit: OrganizationUnit) => void; onSessionExpired: () => void
}) {
  const text = copy[locale]
  const [parentId, setParentId] = useState('')
  const [typeCode, setTypeCode] = useState('department')
  const [code, setCode] = useState('')
  const [name, setName] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<'validation' | 'save' | null>(null)
  const errorRef = useRef<HTMLParagraphElement>(null)

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!/^[A-Z0-9_-]{2,64}$/.test(code) || !name.trim()) {
      setError('validation'); window.requestAnimationFrame(() => errorRef.current?.focus()); return
    }
    setSubmitting(true); setError(null)
    try {
      const created = await createOrganizationUnit(token, { cluster_id: cluster.id, parent_id: parentId || undefined, type_code: typeCode, code, name: name.trim() })
      onCreated(created); setCode(''); setName('')
    } catch (failure) {
      if (failure instanceof ApiError && failure.status === 401) onSessionExpired()
      else { setError('save'); window.requestAnimationFrame(() => errorRef.current?.focus()) }
    } finally { setSubmitting(false) }
  }

  return <form className="resource-form" onSubmit={(event) => void submit(event)} noValidate>
    {error && <p className="error-summary" role="alert" tabIndex={-1} ref={errorRef}>{error === 'validation' ? text.validation : text.saveError}</p>}
    <div className="field-row">
      <TextField id="unit-code" label={text.code} value={code} onChange={(value) => setCode(value.toUpperCase())} required invalid={Boolean(error && !/^[A-Z0-9_-]{2,64}$/.test(code))} />
      <TextField id="unit-name" label={text.name} value={name} onChange={setName} required invalid={Boolean(error && !name.trim())} />
      <SelectField id="unit-type" label={text.type} value={typeCode} onChange={setTypeCode} options={unitTypes.map(([value, label]) => ({ value, label: text[label] }))} />
      <SelectField id="unit-parent" label={text.parent} value={parentId} onChange={setParentId} options={[
        { value: '', label: text.root },
        ...facilities.map((facility) => ({ value: facility.id, label: facility.name_ar })),
        ...units.map((unit) => ({ value: unit.id, label: `${'— '.repeat(Math.min(unit.depth, 4))}${unit.name_ar}` })),
      ]} />
    </div>
    <button type="submit" className="primary-button" disabled={submitting}>{submitting ? text.saving : text.addUnit}</button>
  </form>
}

function PositionForm({ locale, token, units, positions, onCreated, onSessionExpired }: {
  locale: Locale; token: string; units: OrganizationUnit[]; positions: Position[]
  onCreated: (position: Position) => void; onSessionExpired: () => void
}) {
  const text = copy[locale]
  const [unitId, setUnitId] = useState(units[0]?.id ?? '')
  const [managerId, setManagerId] = useState('')
  const [code, setCode] = useState('')
  const [title, setTitle] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<'validation' | 'save' | null>(null)
  const errorRef = useRef<HTMLParagraphElement>(null)

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!unitId || !/^[A-Z0-9_-]{2,64}$/.test(code) || !title.trim()) {
      setError('validation'); window.requestAnimationFrame(() => errorRef.current?.focus()); return
    }
    setSubmitting(true); setError(null)
    try {
      const created = await createPosition(token, { organization_unit_id: unitId, code, title: title.trim(), manager_position_id: managerId || null })
      onCreated(created); setCode(''); setTitle('')
    } catch (failure) {
      if (failure instanceof ApiError && failure.status === 401) onSessionExpired()
      else { setError('save'); window.requestAnimationFrame(() => errorRef.current?.focus()) }
    } finally { setSubmitting(false) }
  }

  return <form className="resource-form" onSubmit={(event) => void submit(event)} noValidate>
    {error && <p className="error-summary" role="alert" tabIndex={-1} ref={errorRef}>{error === 'validation' ? text.validation : text.saveError}</p>}
    <div className="field-row">
      <TextField id="position-code" label={text.code} value={code} onChange={(value) => setCode(value.toUpperCase())} required invalid={Boolean(error && !/^[A-Z0-9_-]{2,64}$/.test(code))} />
      <TextField id="position-title" label={text.titleAr} value={title} onChange={setTitle} required invalid={Boolean(error && !title.trim())} />
      <SelectField id="position-unit" label={text.unit} value={unitId} onChange={setUnitId} options={units.map((unit) => ({ value: unit.id, label: unit.name_ar }))} />
      <SelectField id="position-manager" label={text.manager} value={managerId} onChange={setManagerId} options={[{ value: '', label: text.noManager }, ...positions.map((position) => ({ value: position.id, label: position.title_ar }))]} />
    </div>
    <button type="submit" className="primary-button" disabled={submitting}>{submitting ? text.saving : text.addPosition}</button>
  </form>
}

function TextField({ id, label, value, onChange, required = false, invalid = false }: { id: string; label: string; value: string; onChange: (value: string) => void; required?: boolean; invalid?: boolean }) {
  return <div className="field"><label htmlFor={id}>{label}{required && <span aria-hidden="true"> *</span>}</label><input id={id} value={value} required={required} aria-required={required || undefined} aria-invalid={invalid} onChange={(event) => onChange(event.target.value)} /></div>
}

function SelectField({ id, label, value, onChange, options }: { id: string; label: string; value: string; onChange: (value: string) => void; options: Array<{ value: string; label: string }> }) {
  return <div className="field"><label htmlFor={id}>{label}</label><select id={id} value={value} onChange={(event) => onChange(event.target.value)}>{options.map((option) => <option key={`${option.value}-${option.label}`} value={option.value}>{option.label}</option>)}</select></div>
}

function formatNumber(value: number, locale: Locale) {
  return new Intl.NumberFormat(locale === 'ar' ? 'ar-SA' : 'en-GB').format(value)
}

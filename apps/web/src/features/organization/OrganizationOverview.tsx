import { type FormEvent, useEffect, useRef, useState } from 'react'

import {
  ApiError,
  createCluster,
  createFacility,
  getCluster,
  listFacilities,
  type Cluster,
  type Facility,
} from '../../api'

type Locale = 'ar' | 'en'

const copy = {
  ar: {
    title: 'التجمع والمنشآت',
    intro: 'إدارة جذر التجمع والمنشآت التابعة له من مصدر واحد محكوم.',
    loading: 'جارٍ تحميل بيانات التنظيم…',
    retry: 'إعادة المحاولة',
    forbidden: 'لا تملك صلاحية إدارة بيانات التنظيم.',
    error: 'تعذر تحميل بيانات التنظيم. أعد المحاولة.',
    cluster: 'جذر التجمع',
    noCluster: 'لم يُنشأ جذر التجمع بعد.',
    clusterCode: 'رمز التجمع',
    clusterName: 'اسم التجمع بالعربية',
    clusterNameEn: 'اسم التجمع بالإنجليزية',
    createCluster: 'إنشاء جذر التجمع',
    creating: 'جارٍ الحفظ…',
    facilities: 'المنشآت',
    noFacilities: 'لا توجد منشآت بعد. أضف أول منشأة تحت التجمع.',
    facilityCode: 'رمز المنشأة',
    facilityName: 'اسم المنشأة بالعربية',
    facilityNameEn: 'اسم المنشأة بالإنجليزية',
    facilityType: 'نوع المنشأة',
    addFacility: 'إضافة منشأة',
    code: 'الرمز',
    name: 'الاسم',
    type: 'النوع',
    status: 'الحالة',
    active: 'نشطة',
    validation: 'أكمل الحقول المطلوبة بالصيغة الصحيحة.',
    saveError: 'لم يُحفظ التغيير. راجع البيانات ثم أعد المحاولة.',
    hospital: 'مستشفى',
    center: 'مركز صحي',
    lab: 'مختبر',
    sharedServices: 'خدمات مشتركة',
  },
  en: {
    title: 'Cluster and facilities',
    intro: 'Manage the cluster root and its facilities from one governed source.',
    loading: 'Loading organization data…',
    retry: 'Try again',
    forbidden: 'You do not have permission to manage organization data.',
    error: 'Organization data could not be loaded. Try again.',
    cluster: 'Cluster root',
    noCluster: 'The cluster root has not been created yet.',
    clusterCode: 'Cluster code',
    clusterName: 'Cluster name in Arabic',
    clusterNameEn: 'Cluster name in English',
    createCluster: 'Create cluster root',
    creating: 'Saving…',
    facilities: 'Facilities',
    noFacilities: 'No facilities yet. Add the first facility under the cluster.',
    facilityCode: 'Facility code',
    facilityName: 'Facility name in Arabic',
    facilityNameEn: 'Facility name in English',
    facilityType: 'Facility type',
    addFacility: 'Add facility',
    code: 'Code',
    name: 'Name',
    type: 'Type',
    status: 'Status',
    active: 'Active',
    validation: 'Complete the required fields using the expected format.',
    saveError: 'The change was not saved. Review the data and try again.',
    hospital: 'Hospital',
    center: 'Health center',
    lab: 'Laboratory',
    sharedServices: 'Shared services',
  },
} as const

const facilityTypes = [
  ['hospital', 'hospital'],
  ['center', 'center'],
  ['lab', 'lab'],
  ['shared_services', 'sharedServices'],
] as const

export function OrganizationOverview({ locale, token, onSessionExpired }: {
  locale: Locale
  token: string
  onSessionExpired: () => void
}) {
  const text = copy[locale]
  const [cluster, setCluster] = useState<Cluster | null>(null)
  const [facilities, setFacilities] = useState<Facility[]>([])
  const [loading, setLoading] = useState(true)
  const [state, setState] = useState<'ready' | 'forbidden' | 'error'>('ready')

  async function load() {
    setLoading(true)
    setState('ready')
    try {
      try {
        setCluster(await getCluster(token))
      } catch (error) {
        if (error instanceof ApiError && error.status === 404) {
          setCluster(null)
        } else {
          throw error
        }
      }
      setFacilities((await listFacilities(token)).items)
    } catch (error) {
      setCluster(null)
      setFacilities([])
      if (error instanceof ApiError && error.status === 401) {
        onSessionExpired()
      } else if (error instanceof ApiError && error.status === 403) {
        setState('forbidden')
      } else {
        setState('error')
      }
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    void load()
    // The Organization page reloads only when the authenticated session changes.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [token])

  return (
    <section className="organization-page" aria-labelledby="organization-heading">
      <div className="page-heading page-heading-copy">
        <div>
          <h1 id="organization-heading">{text.title}</h1>
          <p>{text.intro}</p>
        </div>
      </div>

      {loading && <div className="skeleton-list" aria-label={text.loading}>{[0, 1, 2].map((item) => <div className="skeleton-row" aria-hidden="true" key={item} />)}</div>}
      {!loading && state !== 'ready' && (
        <div className="state-panel" role={state === 'error' ? 'alert' : 'status'}>
          <p>{state === 'forbidden' ? text.forbidden : text.error}</p>
          {state === 'error' && <button type="button" className="secondary-button" onClick={() => void load()}>{text.retry}</button>}
        </div>
      )}
      {!loading && state === 'ready' && (
        <div className="organization-layout">
          <section className="organization-section" aria-labelledby="cluster-heading">
            <h2 id="cluster-heading">{text.cluster}</h2>
            {cluster ? <ClusterSummary cluster={cluster} locale={locale} /> : (
              <>
                <p>{text.noCluster}</p>
                <ClusterForm locale={locale} token={token} onCreated={setCluster} onSessionExpired={onSessionExpired} />
              </>
            )}
          </section>

          <section className="organization-section" aria-labelledby="facilities-heading">
            <div className="section-heading">
              <h2 id="facilities-heading">{text.facilities}</h2>
              <span className="count-badge">{new Intl.NumberFormat(locale === 'ar' ? 'ar-SA' : 'en-GB').format(facilities.length)}</span>
            </div>
            {facilities.length === 0 ? <p>{text.noFacilities}</p> : <FacilityTable facilities={facilities} locale={locale} />}
            {cluster && (
              <FacilityForm
                locale={locale}
                token={token}
                clusterId={cluster.id}
                onCreated={(facility) => setFacilities((current) => [...current, facility])}
                onSessionExpired={onSessionExpired}
              />
            )}
          </section>
        </div>
      )}
    </section>
  )
}

function ClusterSummary({ cluster, locale }: { cluster: Cluster; locale: Locale }) {
  const text = copy[locale]
  return (
    <dl className="definition-grid">
      <div><dt>{text.code}</dt><dd dir="ltr">{cluster.code}</dd></div>
      <div><dt>{text.name}</dt><dd>{locale === 'en' && cluster.name_en ? cluster.name_en : cluster.name_ar}</dd></div>
      <div><dt>{text.status}</dt><dd><span className="status-badge">{text.active}</span></dd></div>
    </dl>
  )
}

function FacilityTable({ facilities, locale }: { facilities: Facility[]; locale: Locale }) {
  const text = copy[locale]
  return (
    <div className="table-scroll" tabIndex={0} role="region" aria-label={text.facilities}>
      <table>
        <thead><tr><th scope="col">{text.code}</th><th scope="col">{text.name}</th><th scope="col">{text.type}</th><th scope="col">{text.status}</th></tr></thead>
        <tbody>
          {facilities.map((facility) => (
            <tr key={facility.id}>
              <td dir="ltr">{facility.code}</td>
              <td>{locale === 'en' && facility.name_en ? facility.name_en : facility.name_ar}</td>
              <td>{text[facilityTypes.find(([code]) => code === facility.type_code)?.[1] ?? 'hospital']}</td>
              <td><span className="status-badge">{facility.status === 'active' ? text.active : facility.status}</span></td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

function ClusterForm({ locale, token, onCreated, onSessionExpired }: {
  locale: Locale
  token: string
  onCreated: (cluster: Cluster) => void
  onSessionExpired: () => void
}) {
  const text = copy[locale]
  const [code, setCode] = useState('')
  const [name, setName] = useState('')
  const [nameEn, setNameEn] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<'validation' | 'save' | null>(null)
  const errorRef = useRef<HTMLParagraphElement>(null)

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!/^[A-Z0-9_-]{2,64}$/.test(code) || !name.trim()) {
      setError('validation')
      window.requestAnimationFrame(() => errorRef.current?.focus())
      return
    }
    setSubmitting(true)
    setError(null)
    try {
      onCreated(await createCluster(token, { code, name: name.trim(), name_en: nameEn.trim() || null }))
    } catch (failure) {
      if (failure instanceof ApiError && failure.status === 401) {
        onSessionExpired()
      } else {
        setError('save')
        window.requestAnimationFrame(() => errorRef.current?.focus())
      }
    } finally {
      setSubmitting(false)
    }
  }

  return <form className="resource-form" onSubmit={(event) => void submit(event)} noValidate>
    {error && <p className="error-summary" role="alert" tabIndex={-1} ref={errorRef}>{error === 'validation' ? text.validation : text.saveError}</p>}
    <div className="field-row">
      <Field id="cluster-code" label={text.clusterCode} required value={code} onChange={(value) => setCode(value.toUpperCase())} invalid={Boolean(error && !/^[A-Z0-9_-]{2,64}$/.test(code))} />
      <Field id="cluster-name" label={text.clusterName} required value={name} onChange={setName} invalid={Boolean(error && !name.trim())} />
      <Field id="cluster-name-en" label={text.clusterNameEn} value={nameEn} onChange={setNameEn} />
    </div>
    <button type="submit" className="primary-button" disabled={submitting}>{submitting ? text.creating : text.createCluster}</button>
  </form>
}

function FacilityForm({ locale, token, clusterId, onCreated, onSessionExpired }: {
  locale: Locale
  token: string
  clusterId: string
  onCreated: (facility: Facility) => void
  onSessionExpired: () => void
}) {
  const text = copy[locale]
  const [code, setCode] = useState('')
  const [name, setName] = useState('')
  const [nameEn, setNameEn] = useState('')
  const [typeCode, setTypeCode] = useState('hospital')
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<'validation' | 'save' | null>(null)
  const errorRef = useRef<HTMLParagraphElement>(null)

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!/^[A-Z0-9_-]{2,64}$/.test(code) || !name.trim()) {
      setError('validation')
      window.requestAnimationFrame(() => errorRef.current?.focus())
      return
    }
    setSubmitting(true)
    setError(null)
    try {
      const facility = await createFacility(token, {
        cluster_id: clusterId,
        type_code: typeCode,
        code,
        name: name.trim(),
        name_en: nameEn.trim() || null,
      })
      onCreated(facility)
      setCode('')
      setName('')
      setNameEn('')
    } catch (failure) {
      if (failure instanceof ApiError && failure.status === 401) {
        onSessionExpired()
      } else {
        setError('save')
        window.requestAnimationFrame(() => errorRef.current?.focus())
      }
    } finally {
      setSubmitting(false)
    }
  }

  return <form className="resource-form" onSubmit={(event) => void submit(event)} noValidate>
    {error && <p className="error-summary" role="alert" tabIndex={-1} ref={errorRef}>{error === 'validation' ? text.validation : text.saveError}</p>}
    <div className="field-row">
      <Field id="facility-code" label={text.facilityCode} required value={code} onChange={(value) => setCode(value.toUpperCase())} invalid={Boolean(error && !/^[A-Z0-9_-]{2,64}$/.test(code))} />
      <Field id="facility-name" label={text.facilityName} required value={name} onChange={setName} invalid={Boolean(error && !name.trim())} />
      <Field id="facility-name-en" label={text.facilityNameEn} value={nameEn} onChange={setNameEn} />
      <div className="field">
        <label htmlFor="facility-type">{text.facilityType}</label>
        <select id="facility-type" value={typeCode} onChange={(event) => setTypeCode(event.target.value)}>
          {facilityTypes.map(([value, label]) => <option key={value} value={value}>{text[label]}</option>)}
        </select>
      </div>
    </div>
    <button type="submit" className="primary-button" disabled={submitting}>{submitting ? text.creating : text.addFacility}</button>
  </form>
}

function Field({ id, label, value, onChange, required = false, invalid = false }: {
  id: string
  label: string
  value: string
  onChange: (value: string) => void
  required?: boolean
  invalid?: boolean
}) {
  return <div className="field">
    <label htmlFor={id}>{label}{required && <span aria-hidden="true"> *</span>}</label>
    <input id={id} value={value} required={required} aria-required={required || undefined} aria-invalid={invalid} onChange={(event) => onChange(event.target.value)} />
  </div>
}

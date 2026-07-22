import { type FormEvent, useEffect, useRef, useState } from 'react'
import { formattingLocale } from '../../app/copy'
import { useLocale, useToken } from '../../app/session-context'

import { Building2, Hospital } from 'lucide-react'

import {
  ApiError,
  createCluster,
  createFacility,
  getCluster,
  listFacilities,
  type Cluster,
  type Facility,
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

export function OrganizationOverview() {
  const locale = useLocale()
  const token = useToken()
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
      if (error instanceof ApiError && error.status === 403) {
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
    <Page>
      <PageHeader id="organization-heading" title={text.title} description={text.intro} />

      {loading && <SkeletonList label={text.loading} />}
      {!loading && state === 'forbidden' && (
        <div className="state-panel" role="status"><p>{text.forbidden}</p></div>
      )}
      {!loading && state === 'error' && (
        <InlineError message={text.error} retryLabel={text.retry} onRetry={() => void load()} />
      )}
      {!loading && state === 'ready' && (
        <PanelGrid>
          <Panel id="cluster-heading" title={text.cluster} level={2}>
            {cluster ? <ClusterSummary cluster={cluster} locale={locale} /> : (
              <>
                <EmptyState icon={<Building2 />} title={text.noCluster} />
                <ClusterForm locale={locale} token={token} onCreated={setCluster} />
              </>
            )}
          </Panel>

          <Panel
            id="facilities-heading"
            title={text.facilities}
            level={2}
            actions={<span className="count-badge">{new Intl.NumberFormat(formattingLocale(locale)).format(facilities.length)}</span>}
          >
            {facilities.length === 0
              ? <EmptyState icon={<Hospital />} title={text.noFacilities} />
              : <FacilityTable facilities={facilities} locale={locale} />}
            {cluster && (
              <FacilityForm
                locale={locale}
                token={token}
                clusterId={cluster.id}
                onCreated={(facility) => setFacilities((current) => [...current, facility])}
              />
            )}
          </Panel>
        </PanelGrid>
      )}
    </Page>
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

function ClusterForm({ locale, token, onCreated }: {
  locale: Locale
  token: string
  onCreated: (cluster: Cluster) => void
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
    } catch {
        setError('save')
        window.requestAnimationFrame(() => errorRef.current?.focus())
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
    <Button type="submit" disabled={submitting}>{submitting ? text.creating : text.createCluster}</Button>
  </form>
}

function FacilityForm({ locale, token, clusterId, onCreated }: {
  locale: Locale
  token: string
  clusterId: string
  onCreated: (facility: Facility) => void
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
    } catch {
        setError('save')
        window.requestAnimationFrame(() => errorRef.current?.focus())
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
      <UiField id="facility-type" label={text.facilityType}>
        <UiSelect
          id="facility-type"
          value={typeCode}
          onChange={setTypeCode}
          options={facilityTypes.map(([value, label]) => ({ value, label: text[label] }))}
        />
      </UiField>
    </div>
    <Button type="submit" disabled={submitting}>{submitting ? text.creating : text.addFacility}</Button>
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
  return <UiField id={id} label={label} required={required}>
    <input id={id} value={value} required={required} aria-required={required || undefined} aria-invalid={invalid} onChange={(event) => onChange(event.target.value)} />
  </UiField>
}

import { type FormEvent, useCallback, useEffect, useState } from 'react'
import { useLocale, useToken } from '../../app/session-context'
import { Inbox } from 'lucide-react'
import { stateFromError } from '../../api'
import {
  createWorkDefinition,
  createWorkDefinitionVersion,
  createWorkflowDefinition,
  getDashboard,
  getReport,
  getReportExport,
  downloadReportExport,
  listDashboards,
  listReports,
  listWorkDefinitions,
  listWorkflowDefinitions,
  listWorkflowInstances,
  publishWorkDefinitionVersion,
  publishWorkflowVersion,
  requestReportExport,
  searchRecords,
  type R1Collection,
  type R1Entity,
} from '../../api/r1'
import { Button, EmptyState, Field, InlineError, PageHeader, Panel, PanelGrid, Select, SkeletonList } from '../../ui'

type Locale = 'ar' | 'en'
type State = 'loading' | 'ready' | 'empty' | 'forbidden' | 'error' | 'stale'
const common = {
  ar: {
    loading: 'جارٍ التحميل…',
    empty: 'لا توجد بيانات متاحة',
    forbidden: 'لا تملك صلاحية عرض هذه الشاشة.',
    error: 'تعذر تحميل البيانات.',
    stale: 'تغيّرت البيانات على الخادم. حدّث الشاشة ثم أعد المحاولة.',
    retry: 'إعادة المحاولة',
    actions: 'الإجراءات',
    exportFailed: 'فشل التصدير. حاول مرة أخرى.',
    exportRetry: 'إعادة محاولة التصدير',
    existingRecordsRemainPinnedToThe: 'تبقى السجلات الحالية مثبّتة على التعريف الأخير.',
    create: 'إنشاء',
    publish: 'نشر',
    status: 'الحالة',
    name: 'الاسم',
    code: 'الرمز',
    updated: 'آخر تحديث',
    workDefinitionAdministration: 'إدارة تعريفات العمل',
    createADefinitionTheSystemPublishes: 'أنشئ تعريفاً ثم ينشر النظام الإصدار الأول تلقائياً.',
    lowercaseLatinLettersDigitsAndDashes: 'أحرف لاتينية صغيرة وأرقام وشرطات فقط.',
    publishing: 'جارٍ النشر…',
    workflowAdministration: 'إدارة المسارات',
    publishedDefinitions: 'التعريفات المنشورة',
    runningInstancesAndSteps: 'المسارات الجارية وخطواتها',
    search: 'البحث',
    theServerReturnsAuthorizedResultsOnly: 'يعيد الخادم النتائج المصرح بها فقط؛ لا تُكشف عناوين الموارد المحجوبة.',
    searchText: 'نص البحث',
    eGReportRequestDefinition: 'مثال: تقرير، طلب، تعريف',
    type: 'النوع',
    all: 'الكل',
    searching: 'جارٍ البحث…',
    search2: 'بحث',
    clear: 'مسح',
    enterTextPlusOptionalFiltersThen: 'اختر نصاً ثم فلاتر اختيارية ثم ابحث.',
    reports: 'التقارير',
    reportOrDashboard: 'التقرير أو اللوحة',
    reportKpis: 'مؤشرات التقرير',
    countInScope: 'العدد ضمن النطاق',
    itemsRendered: 'العناصر المعروضة',
    exportReport: 'تصدير التقرير',
    requestingExport: 'جارٍ إنشاء التصدير…',
    requestExport: 'طلب تصدير',
    exportStatus: 'حالة التصدير',
    processing: 'جارٍ المعالجة',
    download: 'تنزيل',
    definitionPublished: (code: string) => `تم نشر التعريف ${code} بالإصدار الأول.`,
    workflowPublished: (code: string) => `تم نشر المسار ${code} بالإصدار الأول.`,
    totalDefinitions: (count: number) => `إجمالي التعريفات: ${count}`,
    countLabel: (count: number) => `العدد: ${count}`,
    authorizedResults: (query: string, count: number) => `النتائج المصرح بها لـ "${query}": ${count}`,
  },
  en: {
    loading: 'Loading…',
    empty: 'No data is available',
    forbidden: 'You do not have permission to view this screen.',
    error: 'We could not load the data.',
    stale: 'The server data changed. Refresh and try again.',
    retry: 'Try again',
    actions: 'Actions',
    exportFailed: 'Export failed. Try again.',
    exportRetry: 'Retry export',
    existingRecordsRemainPinnedToThe: 'Existing records remain pinned to the latest published definition.',
    create: 'Create',
    publish: 'Publish',
    status: 'Status',
    name: 'Name',
    code: 'Code',
    updated: 'Last refreshed',
    workDefinitionAdministration: 'Work definition administration',
    createADefinitionTheSystemPublishes: 'Create a definition; the system publishes its first version automatically.',
    lowercaseLatinLettersDigitsAndDashes: 'Lowercase Latin letters, digits, and dashes only.',
    publishing: 'Publishing…',
    workflowAdministration: 'Workflow administration',
    publishedDefinitions: 'Published definitions',
    runningInstancesAndSteps: 'Running instances and steps',
    search: 'Search',
    theServerReturnsAuthorizedResultsOnly: 'The server returns authorized results only; denied resource titles are never exposed.',
    searchText: 'Search text',
    eGReportRequestDefinition: 'e.g. report, request, definition',
    type: 'Type',
    all: 'All',
    searching: 'Searching…',
    search2: 'Search',
    clear: 'Clear',
    enterTextPlusOptionalFiltersThen: 'Enter text plus optional filters, then search.',
    reports: 'Reports',
    reportOrDashboard: 'Report or dashboard',
    reportKpis: 'Report KPIs',
    countInScope: 'Count in scope',
    itemsRendered: 'Items rendered',
    exportReport: 'Export report',
    requestingExport: 'Requesting export…',
    requestExport: 'Request export',
    exportStatus: 'Export status',
    processing: 'Processing',
    download: 'Download',
    definitionPublished: (code: string) => `Definition ${code} published at version 1.`,
    workflowPublished: (code: string) => `Workflow ${code} published at version 1.`,
    totalDefinitions: (count: number) => `Total definitions: ${count}`,
    countLabel: (count: number) => `Count: ${count}`,
    authorizedResults: (query: string, count: number) => `Authorized results for "${query}": ${count}`,
  },
} as const

function stateFrom(error: unknown): State {
  const state = stateFromError(error)
  // This screen family has no distinct not-found or conflict copy; both read as stale
  // because the remedy is the same: reload before retrying.
  if (state === 'conflict') return 'stale'
  if (state === 'not-found') return 'error'
  return state
}

export const __test = { stateFrom, common }

function ScreenState({ locale, state, retry }: { locale: Locale; state: State; retry: () => void }) {
  const t = common[locale]
  if (state === 'ready') return null
  if (state === 'loading') return <SkeletonList label={t.loading} />
  if (state === 'empty') return <EmptyState icon={<Inbox />} title={t.empty} />
  if (state === 'forbidden') return <InlineError message={t.forbidden} />
  return <InlineError message={state === 'stale' ? t.stale : t.error} retryLabel={t.retry} onRetry={retry} />
}

function EntityTable({ locale, items, actions }: { locale: Locale; items: R1Entity[]; actions?: (item: R1Entity) => React.ReactNode }) {
  const t = common[locale]
  return <div className="table-scroll"><table className="data-table"><thead><tr><th>{t.name}</th><th>{t.code}</th><th>{t.status}</th>{actions && <th>{common[locale].actions}</th>}</tr></thead><tbody>{items.map((item, index) => <tr key={String(item.id ?? index)}><td>{String(item.name ?? item.title ?? item.id ?? '—')}</td><td>{String(item.code ?? item.version_number ?? '—')}</td><td>{String(item.status ?? item.definition_state ?? '—')}</td>{actions && <td>{actions(item)}</td>}</tr>)}</tbody></table></div>
}

function mutationAllowed(capabilities: readonly string[], capability: string): boolean {
  return capabilities.includes(capability)
}

type R1CapabilityProps = { capabilities: readonly string[] }

export function WorkDefinitionsScreen({ capabilities }: R1CapabilityProps) {
  const locale = useLocale()
  const token = useToken()
  const [items, setItems] = useState<R1Entity[]>([])
  const [state, setState] = useState<State>('loading')
  const [submitting, setSubmitting] = useState(false)
  const [feedback, setFeedback] = useState<{ kind: 'success' | 'error'; message: string } | null>(null)
  const load = useCallback(async () => {
    setState('loading')
    try {
      const value = await listWorkDefinitions(token)
      setItems(value.items ?? [])
      setState(value.items?.length ? 'ready' : 'empty')
    } catch (error) {
      setState(stateFrom(error))
    }
  }, [token])
  useEffect(() => { void load() }, [load])
  async function create(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!capabilities.includes('work_definition.create') || !capabilities.includes('work_definition.publish') || submitting) return
    const form = new FormData(event.currentTarget)
    setSubmitting(true)
    setFeedback(null)
    try {
      const definition = await createWorkDefinition(token, {
        code: String(form.get('code')),
        name: String(form.get('name')),
        default_classification: 'internal',
      })
      const version = await createWorkDefinitionVersion(token, String(definition.id), {
        schema_document: { type: 'object', properties: { title: { type: 'string' }, description: { type: 'string' } } },
        field_policy_key: 'default',
      })
      await publishWorkDefinitionVersion(token, String(version.id), Number(version.lock_version ?? 1))
      event.currentTarget.reset()
      setFeedback({ kind: 'success', message: common[locale].definitionPublished(String(definition.code)) })
      await load()
    } catch (error) {
      setFeedback({ kind: 'error', message: stateFrom(error) === 'stale' ? common[locale].stale : common[locale].error })
    } finally {
      setSubmitting(false)
    }
  }
  const canMutate = capabilities.includes('work_definition.create') && capabilities.includes('work_definition.publish')
  return (
    <section className="ui-page" aria-labelledby="definitions-heading">
      <PageHeader id="definitions-heading" title={common[locale].workDefinitionAdministration} />
      <p className="status-message">{common[locale].existingRecordsRemainPinnedToThe}</p>
      {canMutate ? (
        <form className="inline-form" onSubmit={(event) => void create(event)} aria-describedby="definitions-form-help">
          <p id="definitions-form-help" className="visually-hidden">{common[locale].createADefinitionTheSystemPublishes}</p>
          <Field id="work-definition-code" label={common[locale].code} required help={common[locale].lowercaseLatinLettersDigitsAndDashes}>
            <input id="work-definition-code" name="code" required pattern="[a-z][a-z0-9-]+" />
          </Field>
          <Field id="work-definition-name" label={common[locale].name} required>
            <input id="work-definition-name" name="name" required />
          </Field>
          <Button type="submit" disabled={submitting}>
            {submitting ? (common[locale].publishing) : common[locale].create}
          </Button>
        </form>
      ) : null}
      {feedback && (
        <div className={`status-message ${feedback.kind === 'error' ? 'error' : 'success'}`} role={feedback.kind === 'error' ? 'alert' : 'status'} aria-live="polite">
          {feedback.message}
        </div>
      )}
      <ScreenState locale={locale} state={state} retry={() => void load()} />
      {state === 'ready' && items.length > 0 && (
        <>
          <p className="status-message" aria-live="polite">{common[locale].totalDefinitions(items.length)}</p>
          <EntityTable locale={locale} items={items} />
        </>
      )}
    </section>
  )
}

export function WorkflowAdminScreen({ capabilities }: R1CapabilityProps) {
  const locale = useLocale()
  const token = useToken()
  const [definitions, setDefinitions] = useState<R1Entity[]>([])
  const [instances, setInstances] = useState<R1Entity[]>([])
  const [state, setState] = useState<State>('loading')
  const [submitting, setSubmitting] = useState(false)
  const [feedback, setFeedback] = useState<{ kind: 'success' | 'error'; message: string } | null>(null)
  const load = useCallback(async () => {
    setState('loading')
    try {
      const [d, i] = await Promise.all([listWorkflowDefinitions(token), listWorkflowInstances(token)])
      setDefinitions(d.items ?? [])
      setInstances(i.items ?? [])
      setState(d.items?.length || i.items?.length ? 'ready' : 'empty')
    } catch (error) {
      setState(stateFrom(error))
    }
  }, [token])
  useEffect(() => { void load() }, [load])
  async function create(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!capabilities.includes('workflow.manage') || submitting) return
    const form = new FormData(event.currentTarget)
    setSubmitting(true)
    setFeedback(null)
    try {
      const created = await createWorkflowDefinition(token, { code: String(form.get('code')), name: String(form.get('name')), source_record_type: 'work_record' })
      await publishWorkflowVersion(token, String(created.version.id), Number(created.version.lock_version ?? 1))
      event.currentTarget.reset()
      setFeedback({ kind: 'success', message: common[locale].workflowPublished(String(form.get('code'))) })
      await load()
    } catch (error) {
      setFeedback({ kind: 'error', message: stateFrom(error) === 'stale' ? common[locale].stale : common[locale].error })
    } finally {
      setSubmitting(false)
    }
  }
  const canMutate = capabilities.includes('workflow.manage')
  return (
    <section className="ui-page" aria-labelledby="workflow-heading">
      <PageHeader id="workflow-heading" title={common[locale].workflowAdministration} />
      {canMutate ? (
        <form className="inline-form" onSubmit={(event) => void create(event)}>
          <Field id="workflow-code" label={common[locale].code} required><input id="workflow-code" name="code" required pattern="[a-z][a-z0-9_]+" /></Field>
          <Field id="workflow-name" label={common[locale].name} required><input id="workflow-name" name="name" required /></Field>
          <Button type="submit" disabled={submitting}>{submitting ? common[locale].publishing : common[locale].create}</Button>
        </form>
      ) : null}
      {feedback && <div className={`status-message ${feedback.kind === 'error' ? 'error' : 'success'}`} role={feedback.kind === 'error' ? 'alert' : 'status'} aria-live="polite">{feedback.message}</div>}
      <ScreenState locale={locale} state={state} retry={() => void load()} />
      {state === 'ready' && <PanelGrid>
        <Panel id="workflow-definitions-heading" title={common[locale].publishedDefinitions} level={2}><p className="status-message" aria-live="polite">{common[locale].countLabel(definitions.length)}</p><EntityTable locale={locale} items={definitions} /></Panel>
        <Panel id="workflow-instances-heading" title={common[locale].runningInstancesAndSteps} level={2}><p className="status-message" aria-live="polite">{common[locale].countLabel(instances.length)}</p><EntityTable locale={locale} items={instances} /></Panel>
      </PanelGrid>}
    </section>
  )
}


export function SearchScreen({ initialQuery = '' }: { initialQuery?: string }) {
  const locale = useLocale()
  const token = useToken()
  const [items, setItems] = useState<R1Entity[]>([])
  const [state, setState] = useState<State>('empty')
  const [query, setQuery] = useState('')
  const [typeFilter, setTypeFilter] = useState('')
  const [statusFilter, setStatusFilter] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [lastSearch, setLastSearch] = useState<{ query: string; type: string; status: string } | null>(null)

  const runSearch = useCallback(async (q: string, type = '', status = '') => {
    const normalizedQuery = q.trim()
    if (!normalizedQuery) return
    setLastSearch({ query: normalizedQuery, type, status })
    setSubmitting(true)
    setState('loading')
    try {
      const result = await searchRecords(token, normalizedQuery, { type: type || undefined, status: status || undefined })
      const values = result.items ?? []
      setItems(values)
      setState(values.length ? 'ready' : 'empty')
    } catch (error) {
      setState(stateFrom(error))
    } finally {
      setSubmitting(false)
    }
  }, [token])

  useEffect(() => {
    const normalizedQuery = initialQuery.trim()
    if (!normalizedQuery) return
    setQuery(normalizedQuery)
    void runSearch(normalizedQuery)
  }, [initialQuery, runSearch])

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (submitting) return
    const form = new FormData(event.currentTarget)
    const q = String(form.get('q') ?? '').trim()
    if (!q) return
    await runSearch(q, String(form.get('type') ?? ''), String(form.get('status') ?? ''))
  }

  function reset() {
    setQuery('')
    setItems([])
    setLastSearch(null)
    setState('empty')
  }

  return (
    <section className="ui-page" aria-labelledby="search-heading">
      <PageHeader id="search-heading" title={common[locale].search} />
      <p className="status-message">{common[locale].theServerReturnsAuthorizedResultsOnly}</p>
      <form className="inline-form" onSubmit={(event) => void submit(event)} aria-describedby="search-help">
        <Field id="search-query" label={common[locale].searchText} required>
          <input id="search-query" name="q" required value={query} onChange={(event) => setQuery(event.currentTarget.value)} placeholder={common[locale].eGReportRequestDefinition} />
        </Field>
        <Field id="search-type" label={common[locale].type}>
          <Select id="search-type" name="type" value={typeFilter} onChange={setTypeFilter} options={[
            { value: '', label: common[locale].all },
            { value: 'work_record', label: 'Work record' },
            { value: 'task', label: 'Task' },
            { value: 'document', label: 'Document' },
          ]} />
        </Field>
        <Field id="search-status" label={common[locale].status}>
          <Select id="search-status" name="status" value={statusFilter} onChange={setStatusFilter} options={[
            { value: '', label: common[locale].all },
            { value: 'draft', label: 'Draft' },
            { value: 'submitted', label: 'Submitted' },
            { value: 'in_review', label: 'In review' },
            { value: 'approved', label: 'Approved' },
            { value: 'completed', label: 'Completed' },
          ]} />
        </Field>
        <Button type="submit" disabled={submitting || !query.trim()}>
          {submitting ? (common[locale].searching) : (common[locale].search2)}
        </Button>
        {(lastSearch || items.length > 0) && (
          <Button variant="secondary" onClick={reset} disabled={submitting}>
            {common[locale].clear}
          </Button>
        )}
      </form>
      <p id="search-help" className="visually-hidden">{common[locale].enterTextPlusOptionalFiltersThen}</p>
      {state === 'ready' && lastSearch && (
        <p className="status-message" aria-live="polite">
          {common[locale].authorizedResults(lastSearch.query, items.length)}
        </p>
      )}
      <ScreenState locale={locale} state={state} retry={() => { if (lastSearch) void runSearch(lastSearch.query, lastSearch.type, lastSearch.status) }} />
      {state === 'ready' && <EntityTable locale={locale} items={items} />}
    </section>
  )
}

export function ReportsScreen({ capabilities }: R1CapabilityProps) {
  const locale = useLocale()
  const token = useToken()
  const [report, setReport] = useState<R1Collection | null>(null)
  const [state, setState] = useState<State>('loading')
  const [options, setOptions] = useState<Array<{ value: string; label: string; kind: 'report' | 'dashboard' }>>([])
  const [selection, setSelection] = useState('')
  const [selectedKind, setSelectedKind] = useState<'report' | 'dashboard' | null>(null)
  const [exportItem, setExportItem] = useState<R1Entity | null>(null)
  const [exporting, setExporting] = useState(false)
  const [downloading, setDownloading] = useState(false)
  const [exportError, setExportError] = useState<string | null>(null)

  const load = useCallback(async () => {
    setState('loading')
    try {
      const [reports, dashboards] = await Promise.all([listReports(token), listDashboards(token)])
      const reportOptions = (reports.items ?? []).filter((item) => item.id).map((item) => ({ value: `report:${String(item.id)}`, label: String(item.name ?? item.title ?? item.id), kind: 'report' as const }))
      const dashboardOptions = (dashboards.items ?? []).filter((item) => item.id).map((item) => ({ value: `dashboard:${String(item.id)}`, label: String(item.name ?? item.title ?? item.id), kind: 'dashboard' as const }))
      const allOptions = [...reportOptions, ...dashboardOptions]
      setOptions(allOptions)
      if (!allOptions.length) {
        setReport(null)
        setSelectedKind(null)
        setSelection('')
        setExportItem(null)
        setExportError(null)
        setExporting(false)
        setState('empty')
        return
      }
      const firstOption = allOptions[0]; setSelection((current) => allOptions.some((option) => option.value === current) ? current : (firstOption?.value ?? ''))
    } catch (error) {
      setReport(null)
      setSelectedKind(null)
      setExportItem(null)
      setExportError(null)
      setExporting(false)
      setState(stateFrom(error))
    }
  }, [token])
  useEffect(() => { void load() }, [load])

  const loadSelection = useCallback(async (value: string) => {
    const [kind, id] = value.split(':', 2)
    if (!id || (kind !== 'report' && kind !== 'dashboard')) return
    setState('loading')
    setExportItem(null)
    setExportError(null)
    try {
      const result = kind === 'report' ? await getReport(token, id) : await getDashboard(token, id)
      setReport(result)
      setSelectedKind(kind)
      setState('ready')
    } catch (error) {
      setState(stateFrom(error))
    }
  }, [token])

  useEffect(() => {
    if (selection) void loadSelection(selection)
  }, [loadSelection, selection])

  function selectResource(value: string) {
    setSelection(value)
  }

  async function createExport() {
    if (!mutationAllowed(capabilities, 'reporting.export') || exporting) return
    const selectedId = selection.startsWith('report:') ? selection.slice('report:'.length) : ''
    if (!selectedId || selectedKind !== 'report') return
    setExporting(true)
    setExportError(null)
    try {
      const created = await requestReportExport(token, selectedId)
      setExportItem(created)
      if (!created.id) {
        setExportError(common[locale].exportFailed)
        setExporting(false)
        return
      }
      const status = String(created.status ?? 'queued').toLowerCase()
      if (['ready', 'completed', 'available', 'failed', 'error', 'cancelled'].includes(status)) {
        setExporting(false)
        if (['failed', 'error', 'cancelled'].includes(status)) setExportError(common[locale].exportFailed)
      }
    } catch (error) {
      setExportError(stateFrom(error) === 'forbidden' ? common[locale].forbidden : common[locale].error)
      setExporting(false)
    }
  }

  useEffect(() => {
    const exportId = exportItem?.id ? String(exportItem.id) : null
    if (!exportId) return
    let cancelled = false
    let timer: ReturnType<typeof setTimeout> | undefined
    let attempts = 0
    const maxAttempts = 6
    const backoffMs = [500, 1000, 2000, 4000, 8000, 12000]
    const poll = async () => {
      if (cancelled) return
      try {
        const refreshed = await getReportExport(token, exportId)
        if (cancelled) return
        setExportItem(refreshed)
        const status = String(refreshed.status ?? 'queued').toLowerCase()
        if (['ready', 'completed', 'available'].includes(status)) {
          setExporting(false)
          return
        }
        if (['failed', 'error', 'cancelled'].includes(status)) {
          setExportError(common[locale].exportFailed)
          setExporting(false)
          return
        }
        if (attempts >= maxAttempts) {
          setExportError(common[locale].exportFailed)
          setExporting(false)
          return
        }
        timer = setTimeout(() => void poll(), backoffMs[attempts])
        attempts += 1
      } catch (error) {
        if (cancelled) return
        setExportError(stateFrom(error) === 'forbidden' ? common[locale].forbidden : common[locale].exportFailed)
        setExporting(false)
      }
    }
    timer = setTimeout(() => void poll(), backoffMs[attempts])
    attempts += 1
    return () => {
      cancelled = true
      if (timer) clearTimeout(timer)
    }
  }, [exportItem?.id, locale, token])

  const exportStatus = exportItem ? String(exportItem.status ?? 'queued').toLowerCase() : null
  const exportReady = exportStatus === 'ready' || exportStatus === 'completed' || exportStatus === 'available'
  const exportInProgress = exportStatus !== null && !exportReady && !['failed', 'error', 'cancelled'].includes(exportStatus)

  const downloadExport = useCallback(async () => {
    const exportId = exportItem?.id ? String(exportItem.id) : null
    if (!exportId) return
    setDownloading(true)
    setExportError(null)
    try {
      await downloadReportExport(token, exportId)
    } catch (error) {
      setExportError(stateFrom(error) === 'forbidden' ? common[locale].forbidden : common[locale].exportFailed)
    } finally {
      setDownloading(false)
    }
  }, [common, exportItem?.id, token])

  return (
    <section className="ui-page" aria-labelledby="reports-heading">
      <PageHeader id="reports-heading" title={common[locale].reports} />
      <ScreenState locale={locale} state={state} retry={() => { void load(); if (selection) void loadSelection(selection) }} />
      {options.length > 0 && (
        <Field id="report-resource" label={common[locale].reportOrDashboard}>
          <Select id="report-resource" value={selection} onChange={selectResource} options={options.map(({ value, label }) => ({ value, label }))} />
        </Field>
      )}
      {state === 'ready' && report && (
        <>
          <div className="dashboard-kpi-grid" role="group" aria-label={common[locale].reportKpis}>
            <article className="dashboard-kpi">
              <span>{common[locale].countInScope}</span>
              <strong>{report.total ?? report.items.length}</strong>
            </article>
            <article className="dashboard-kpi">
              <span>{common[locale].itemsRendered}</span>
              <strong>{report.items?.length ?? 0}</strong>
            </article>
          </div>
          <EntityTable locale={locale} items={report.items ?? []} />
          {selectedKind === 'report' && mutationAllowed(capabilities, 'reporting.export') && <Panel id="export-card-heading" title={common[locale].exportReport} level={2}>
            <Button onClick={() => void createExport()} disabled={exporting}>
              {exporting ? (common[locale].requestingExport) : (common[locale].requestExport)}
            </Button>
            {exportError && <div className="status-message error" role="alert">{exportError}</div>}
            {exportItem && (
              <div className="status-message" role="status" aria-live="polite">
                {common[locale].exportStatus}: <strong>{String(exportItem.status ?? 'queued')}</strong>
                {exportInProgress && <progress aria-label={common[locale].processing} />}
                {exportReady && Boolean(exportItem.download_url) && <> — <Button variant="secondary" onClick={() => void downloadExport()} disabled={downloading}>{downloading ? (common[locale].processing) : (common[locale].download)}</Button></>}
              </div>
            )}
            {exportError && <Button variant="secondary" onClick={() => void createExport()} disabled={exporting}>{common[locale].exportRetry}</Button>}
          </Panel>}
        </>
      )}
    </section>
  )
}



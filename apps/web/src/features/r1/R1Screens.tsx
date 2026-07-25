import { type FormEvent, useCallback, useEffect, useRef, useState } from 'react'
import { formattingLocale } from '../../app/copy'
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
  listDashboards,
  listReports,
  listTasks,
  listWorkDefinitions,
  listWorkflowDefinitions,
  listWorkflowInstances,
  publishWorkDefinitionVersion,
  publishWorkflowVersion,
  requestReportExport,
  searchRecords,
  transitionTask,
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
    create: 'إنشاء',
    publish: 'نشر',
    status: 'الحالة',
    name: 'الاسم',
    code: 'الرمز',
    updated: 'آخر تحديث',
    confirmComplete: 'هل تريد إكمال هذه المهمة؟',
    confirmReturn: 'هل تريد إعادة المهمة للمراجعة؟',
    cancel: 'إلغاء',
    filterAll: 'الكل',
    filterOpen: 'المفتوحة',
    filterDone: 'المكتملة',
    refresh: 'تحديث',
    dueAt: 'تاريخ الاستحقاق',
    linkedRecord: 'السجل المرتبط',
    openRecord: 'فتح السجل',
    completedAt: 'وقت الإكمال',
    exportFailed: 'فشل تصدير التقرير. يمكنك إعادة المحاولة.',
    exportRetry: 'إعادة تصدير',
    actions: 'الإجراءات',
    actionAppliedSuccessfully: 'تم تنفيذ العملية بنجاح.',
    myTasks: 'مهامي',
    filterTasks: 'تصفية المهام',
    open: 'فتح',
    complete: 'إكمال',
    return: 'إعادة',
    noDescription: 'لا يوجد وصف',
    status2: 'الحالة',
    workflowStep: 'خطوة المسار',
    applying: 'جارٍ التنفيذ…',
    workDefinitionAdministration: 'إدارة تعريفات العمل',
    existingRecordsRemainPinnedToThe: 'يبقى كل سجل مثبتاً على الإصدار الذي بدأ به عند نشر إصدار أحدث.',
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
    create: 'Create',
    publish: 'Publish',
    status: 'Status',
    name: 'Name',
    code: 'Code',
    updated: 'Last refreshed',
    confirmComplete: 'Complete this task?',
    confirmReturn: 'Return this task for revision?',
    cancel: 'Cancel',
    filterAll: 'All',
    filterOpen: 'Open',
    filterDone: 'Done',
    refresh: 'Refresh',
    dueAt: 'Due',
    linkedRecord: 'Linked record',
    openRecord: 'Open record',
    completedAt: 'Completed at',
    exportFailed: 'Report export failed. You can try again.',
    exportRetry: 'Retry export',
    actions: 'Actions',
    actionAppliedSuccessfully: 'Action applied successfully.',
    myTasks: 'My tasks',
    filterTasks: 'Filter tasks',
    open: 'Open',
    complete: 'Complete',
    return: 'Return',
    noDescription: 'No description',
    status2: 'Status',
    workflowStep: 'Workflow step',
    applying: 'Applying…',
    workDefinitionAdministration: 'Work definition administration',
    existingRecordsRemainPinnedToThe: 'Existing records remain pinned to the version on which they started.',
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

export function TasksScreen() {
  const locale = useLocale()
  const token = useToken()
  const [items, setItems] = useState<R1Entity[]>([])
  const [state, setState] = useState<State>('loading')
  const [selected, setSelected] = useState<R1Entity | null>(null)
  const [filter, setFilter] = useState<'all' | 'open' | 'done'>('open')
  const [lastRefreshedAt, setLastRefreshedAt] = useState<Date | null>(null)
  const [pendingAction, setPendingAction] = useState<{ item: R1Entity; action: 'complete' | 'return-completion' } | null>(null)
  const [submitting, setSubmitting] = useState(false)
  const [feedback, setFeedback] = useState<{ kind: 'success' | 'error'; message: string } | null>(null)
  /**
   * Track in-flight load and mutation requests so superseded calls cannot
   * overwrite the freshest snapshot. The unmount cleanup bumps the refs so no
   * async callback writes after the tasks route is torn down.
   */
  const activeRef = useRef(true)
  const loadRequestRef = useRef(0)
  const mutationEpochRef = useRef(0)
  useEffect(() => () => {
    activeRef.current = false
    loadRequestRef.current += 1
    mutationEpochRef.current += 1
  }, [])

  const load = useCallback(async () => {
    const epoch = ++loadRequestRef.current
    activeRef.current = true
    setState('loading')
    try {
      const result = await listTasks(token)
      if (!activeRef.current || epoch !== loadRequestRef.current) return
      setItems(result.items ?? [])
      setLastRefreshedAt(new Date())
      setState(result.items?.length ? 'ready' : 'empty')
    } catch (error) {
      if (!activeRef.current || epoch !== loadRequestRef.current) return
      setState(stateFrom(error))
    }
  }, [token])

  useEffect(() => { void load() }, [load])

  const filtered = items.filter((item) => {
    const status = String(item.status ?? '').toLowerCase()
    if (filter === 'open') return status !== 'completed' && status !== 'done'
    if (filter === 'done') return status === 'completed' || status === 'done'
    return true
  })

  async function confirmAction() {
    if (!pendingAction) return
    setSubmitting(true)
    try {
      await transitionTask(token, String(pendingAction.item.id), pendingAction.action, Number(pendingAction.item.lock_version ?? 1))
      setFeedback({ kind: 'success', message: common[locale].actionAppliedSuccessfully })
      setPendingAction(null)
      await load()
    } catch (error) {
      setFeedback({ kind: 'error', message: stateFrom(error) === 'stale' ? common[locale].stale : common[locale].error })
    } finally {
      setSubmitting(false)
    }
  }

  const t = common[locale]
  return (
    <section className="ui-page" aria-labelledby="tasks-heading">
      <PageHeader id="tasks-heading" title={common[locale].myTasks} />
      {lastRefreshedAt && <p className="status-message">{t.updated}: {lastRefreshedAt.toLocaleTimeString(formattingLocale(locale))}</p>}
      <div className="filter-bar" role="group" aria-label={common[locale].filterTasks}>
        {(['open', 'done', 'all'] as const).map((value) => (
          <Button
            key={value}
            variant={filter === value ? 'primary' : 'secondary'}
            aria-pressed={filter === value}
            onClick={() => setFilter(value)}
          >
            {value === 'open' ? t.filterOpen : value === 'done' ? t.filterDone : t.filterAll}
          </Button>
        ))}
        <Button variant="quiet" onClick={() => void load()} aria-label={t.refresh}>{t.refresh}</Button>
      </div>
      {feedback && (
        <div className={`status-message ${feedback.kind === 'error' ? 'error' : 'success'}`} role={feedback.kind === 'error' ? 'alert' : 'status'} aria-live="polite">
          {feedback.message}
        </div>
      )}
      <ScreenState locale={locale} state={state === 'ready' && filtered.length === 0 ? 'empty' : state} retry={() => void load()} />
      {state === 'ready' && filtered.length > 0 && (
        <EntityTable locale={locale} items={filtered} actions={(item) => {
          const status = String(item.status ?? '').toLowerCase()
          const isDone = status === 'completed' || status === 'done'
          return (
            <div className="table-actions">
              <Button variant="quiet" onClick={() => setSelected(item)}>{common[locale].open}</Button>
              {!isDone && (
                <>
                  <Button onClick={() => setPendingAction({ item, action: 'complete' })} disabled={submitting}>{common[locale].complete}</Button>
                  <Button variant="secondary" onClick={() => setPendingAction({ item, action: 'return-completion' })} disabled={submitting}>{common[locale].return}</Button>
                </>
              )}
            </div>
          )
        }} />
      )}
      {selected && (
        <div className="surface-card" aria-live="polite">
          <h2>{String(selected.title ?? selected.id)}</h2>
          <p>{String(selected.description ?? (common[locale].noDescription))}</p>
          <dl className="record-summary">
            <div><dt>{common[locale].status2}</dt><dd>{String(selected.status ?? '—')}</dd></div>
            <div><dt>{common[locale].workflowStep}</dt><dd>{String(selected.workflow_step_id ?? '—')}</dd></div>
            {selected.due_at != null && <div><dt>{t.dueAt}</dt><dd>{String(selected.due_at)}</dd></div>}
            {selected.completed_at != null && <div><dt>{t.completedAt}</dt><dd>{String(selected.completed_at)}</dd></div>}
            {selected.work_record_id != null && (
              <div>
                <dt>{t.linkedRecord}</dt>
                <dd>
                  <a href={`/work-records/${String(selected.work_record_id)}`} onClick={(event) => { event.preventDefault(); window.history.pushState({}, '', `/work-records/${String(selected.work_record_id)}`); window.dispatchEvent(new PopStateEvent('popstate')) }}>
                    {t.openRecord}
                  </a>
                </dd>
              </div>
            )}
          </dl>
          <Button variant="secondary" onClick={() => setSelected(null)}>{t.cancel}</Button>
        </div>
      )}
      {pendingAction && (
        <div className="surface-card" role="dialog" aria-labelledby="task-confirm-heading" aria-describedby="task-confirm-body">
          <h2 id="task-confirm-heading">{pendingAction.action === 'complete' ? t.confirmComplete : t.confirmReturn}</h2>
          <p id="task-confirm-body">{String(pendingAction.item.title ?? pendingAction.item.id)}</p>
          <div className="table-actions">
            <Button onClick={() => void confirmAction()} disabled={submitting}>
              {submitting
                ? (common[locale].applying)
                : (pendingAction.action === 'complete' ? t.confirmComplete : t.confirmReturn).split('?')[0]}
            </Button>
            <Button variant="secondary" onClick={() => setPendingAction(null)} disabled={submitting}>{t.cancel}</Button>
          </div>
        </div>
      )}
    </section>
  )
}

export function WorkDefinitionsScreen() {
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
    if (submitting) return
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
  return (
    <section className="ui-page" aria-labelledby="definitions-heading">
      <PageHeader id="definitions-heading" title={common[locale].workDefinitionAdministration} />
      <p className="status-message">{common[locale].existingRecordsRemainPinnedToThe}</p>
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
      {feedback && (
        <div className={`status-message ${feedback.kind === 'error' ? 'error' : 'success'}`} role={feedback.kind === 'error' ? 'alert' : 'status'} aria-live="polite">
          {feedback.message}
        </div>
      )}
      <ScreenState locale={locale} state={state} retry={() => void load()} />
      {state === 'ready' && items.length > 0 && (
        <>
          <p className="status-message" aria-live="polite">
            {common[locale].totalDefinitions(items.length)}
          </p>
          <EntityTable locale={locale} items={items} />
        </>
      )}
    </section>
  )
}

export function WorkflowAdminScreen() {
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
    if (submitting) return
    const form = new FormData(event.currentTarget)
    setSubmitting(true)
    setFeedback(null)
    try {
      const created = await createWorkflowDefinition(token, {
        code: String(form.get('code')),
        name: String(form.get('name')),
        source_record_type: 'work_record',
      })
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
  return (
    <section className="ui-page" aria-labelledby="workflow-heading">
      <PageHeader id="workflow-heading" title={common[locale].workflowAdministration} />
      <form className="inline-form" onSubmit={(event) => void create(event)}>
        <Field id="workflow-code" label={common[locale].code} required>
          <input id="workflow-code" name="code" required pattern="[a-z][a-z0-9_]+" />
        </Field>
        <Field id="workflow-name" label={common[locale].name} required>
          <input id="workflow-name" name="name" required />
        </Field>
        <Button type="submit" disabled={submitting}>
          {submitting ? (common[locale].publishing) : common[locale].create}
        </Button>
      </form>
      {feedback && (
        <div className={`status-message ${feedback.kind === 'error' ? 'error' : 'success'}`} role={feedback.kind === 'error' ? 'alert' : 'status'} aria-live="polite">
          {feedback.message}
        </div>
      )}
      <ScreenState locale={locale} state={state} retry={() => void load()} />
      {state === 'ready' && (
        <PanelGrid>
          <Panel id="workflow-definitions-heading" title={common[locale].publishedDefinitions} level={2}>
            <p className="status-message" aria-live="polite">{common[locale].countLabel(definitions.length)}</p>
            <EntityTable locale={locale} items={definitions} />
          </Panel>
          <Panel id="workflow-instances-heading" title={common[locale].runningInstancesAndSteps} level={2}>
            <p className="status-message" aria-live="polite">{common[locale].countLabel(instances.length)}</p>
            <EntityTable locale={locale} items={instances} />
          </Panel>
        </PanelGrid>
      )}
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

export function ReportsScreen() {
  const locale = useLocale()
  const token = useToken()
  const [report, setReport] = useState<R1Collection | null>(null)
  const [state, setState] = useState<State>('loading')
  const [options, setOptions] = useState<Array<{ value: string; label: string; kind: 'report' | 'dashboard' }>>([])
  const [selection, setSelection] = useState('')
  const [selectedKind, setSelectedKind] = useState<'report' | 'dashboard' | null>(null)
  const [exportItem, setExportItem] = useState<R1Entity | null>(null)
  const [exporting, setExporting] = useState(false)
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
    if (exporting) return
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
          {selectedKind === 'report' && <Panel id="export-card-heading" title={common[locale].exportReport} level={2}>
            <Button onClick={() => void createExport()} disabled={exporting}>
              {exporting ? (common[locale].requestingExport) : (common[locale].requestExport)}
            </Button>
            {exportError && <div className="status-message error" role="alert">{exportError}</div>}
            {exportItem && (
              <div className="status-message" role="status" aria-live="polite">
                {common[locale].exportStatus}: <strong>{String(exportItem.status ?? 'queued')}</strong>
                {exportInProgress && (
                  <progress aria-label={common[locale].processing} />
                )}
                {exportReady && Boolean(exportItem.download_url) && (
                  <> — <a href={String(exportItem.download_url)}>{common[locale].download}</a></>
                )}
              </div>
            )}
            {exportError && <Button variant="secondary" onClick={() => void createExport()} disabled={exporting}>{common[locale].exportRetry}</Button>}
          </Panel>}
        </>
      )}
    </section>
  )
}



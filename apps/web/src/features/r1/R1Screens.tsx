import { type FormEvent, useCallback, useEffect, useState } from 'react'
import { ApiError } from '../../api'
import {
  createWorkDefinition,
  createWorkDefinitionVersion,
  createWorkflowDefinition,
  getDashboard,
  getReport,
  getReportExport,
  listTasks,
  listWorkDefinitions,
  listWorkflowDefinitions,
  listWorkflowInstances,
  markNotificationRead,
  publishWorkDefinitionVersion,
  publishWorkflowVersion,
  requestReportExport,
  searchRecords,
  transitionTask,
  type R1Collection,
  type R1Entity,
} from '../../api/r1'

type Locale = 'ar' | 'en'
type State = 'loading' | 'ready' | 'empty' | 'forbidden' | 'error' | 'stale'
type Props = { locale: Locale; token: string; onSessionExpired: () => void }

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
  },
} as const

function stateFrom(error: unknown, expired: () => void): State {
  if (error instanceof ApiError && error.status === 401) { expired(); return 'error' }
  if (error instanceof ApiError && error.status === 403) return 'forbidden'
  if (error instanceof ApiError && (error.status === 409 || error.status === 412)) return 'stale'
  return 'error'
}

export const __test = { stateFrom, common }

function ScreenState({ locale, state, retry }: { locale: Locale; state: State; retry: () => void }) {
  const t = common[locale]
  if (state === 'ready') return null
  if (state === 'loading') return <div className="skeleton-list" aria-label={t.loading}>{[0, 1, 2].map((row) => <div className="skeleton-row" aria-hidden="true" key={row} />)}</div>
  return <div className="state-panel" role={state === 'empty' ? undefined : 'alert'}><p>{state === 'empty' ? t.empty : state === 'forbidden' ? t.forbidden : state === 'stale' ? t.stale : t.error}</p>{state !== 'forbidden' && state !== 'empty' && <button type="button" className="secondary-button" onClick={retry}>{t.retry}</button>}</div>
}

function EntityTable({ locale, items, actions }: { locale: Locale; items: R1Entity[]; actions?: (item: R1Entity) => React.ReactNode }) {
  const t = common[locale]
  return <div className="table-scroll"><table className="data-table"><thead><tr><th>{t.name}</th><th>{t.code}</th><th>{t.status}</th>{actions && <th>{locale === 'ar' ? 'الإجراءات' : 'Actions'}</th>}</tr></thead><tbody>{items.map((item, index) => <tr key={String(item.id ?? index)}><td>{String(item.name ?? item.title ?? item.id ?? '—')}</td><td>{String(item.code ?? item.version_number ?? '—')}</td><td>{String(item.status ?? item.definition_state ?? '—')}</td>{actions && <td>{actions(item)}</td>}</tr>)}</tbody></table></div>
}

export function TasksScreen({ locale, token, onSessionExpired }: Props) {
  const [items, setItems] = useState<R1Entity[]>([])
  const [state, setState] = useState<State>('loading')
  const [selected, setSelected] = useState<R1Entity | null>(null)
  const [filter, setFilter] = useState<'all' | 'open' | 'done'>('open')
  const [lastRefreshedAt, setLastRefreshedAt] = useState<Date | null>(null)
  const [pendingAction, setPendingAction] = useState<{ item: R1Entity; action: 'complete' | 'return-completion' } | null>(null)
  const [submitting, setSubmitting] = useState(false)
  const [feedback, setFeedback] = useState<{ kind: 'success' | 'error'; message: string } | null>(null)

  const load = useCallback(async () => {
    setState('loading')
    try {
      const result = await listTasks(token)
      setItems(result.items ?? [])
      setLastRefreshedAt(new Date())
      setState(result.items?.length ? 'ready' : 'empty')
    } catch (error) {
      setState(stateFrom(error, onSessionExpired))
    }
  }, [token, onSessionExpired])

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
      setFeedback({ kind: 'success', message: locale === 'ar' ? 'تم تنفيذ العملية بنجاح.' : 'Action applied successfully.' })
      setPendingAction(null)
      await load()
    } catch (error) {
      setFeedback({ kind: 'error', message: stateFrom(error, onSessionExpired) === 'stale' ? common[locale].stale : common[locale].error })
    } finally {
      setSubmitting(false)
    }
  }

  const t = common[locale]
  return (
    <section className="organization-page" aria-labelledby="tasks-heading">
      <div className="page-heading">
        <h1 id="tasks-heading">{locale === 'ar' ? 'مهامي' : 'My tasks'}</h1>
        {lastRefreshedAt && <p className="status-message">{t.updated}: {lastRefreshedAt.toLocaleTimeString(locale === 'ar' ? 'ar-SA' : 'en-US')}</p>}
      </div>
      <div className="filter-bar" role="group" aria-label={locale === 'ar' ? 'تصفية المهام' : 'Filter tasks'}>
        {(['open', 'done', 'all'] as const).map((value) => (
          <button
            key={value}
            type="button"
            className={filter === value ? 'primary-button' : 'secondary-button'}
            aria-pressed={filter === value}
            onClick={() => setFilter(value)}
          >
            {value === 'open' ? t.filterOpen : value === 'done' ? t.filterDone : t.filterAll}
          </button>
        ))}
        <button type="button" className="quiet-button" onClick={() => void load()} aria-label={t.refresh}>{t.refresh}</button>
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
              <button type="button" className="quiet-button" onClick={() => setSelected(item)}>{locale === 'ar' ? 'فتح' : 'Open'}</button>
              {!isDone && (
                <>
                  <button type="button" className="primary-button" onClick={() => setPendingAction({ item, action: 'complete' })} disabled={submitting}>{locale === 'ar' ? 'إكمال' : 'Complete'}</button>
                  <button type="button" className="secondary-button" onClick={() => setPendingAction({ item, action: 'return-completion' })} disabled={submitting}>{locale === 'ar' ? 'إعادة' : 'Return'}</button>
                </>
              )}
            </div>
          )
        }} />
      )}
      {selected && (
        <div className="surface-card" aria-live="polite">
          <h2>{String(selected.title ?? selected.id)}</h2>
          <p>{String(selected.description ?? (locale === 'ar' ? 'لا يوجد وصف' : 'No description'))}</p>
          <dl className="record-summary">
            <div><dt>{locale === 'ar' ? 'الحالة' : 'Status'}</dt><dd>{String(selected.status ?? '—')}</dd></div>
            <div><dt>{locale === 'ar' ? 'خطوة المسار' : 'Workflow step'}</dt><dd>{String(selected.workflow_step_id ?? '—')}</dd></div>
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
          <button type="button" className="secondary-button" onClick={() => setSelected(null)}>{t.cancel}</button>
        </div>
      )}
      {pendingAction && (
        <div className="surface-card" role="dialog" aria-labelledby="task-confirm-heading" aria-describedby="task-confirm-body">
          <h2 id="task-confirm-heading">{pendingAction.action === 'complete' ? t.confirmComplete : t.confirmReturn}</h2>
          <p id="task-confirm-body">{String(pendingAction.item.title ?? pendingAction.item.id)}</p>
          <div className="table-actions">
            <button type="button" className="primary-button" onClick={() => void confirmAction()} disabled={submitting}>
              {submitting ? (locale === 'ar' ? 'جارٍ التنفيذ…' : 'Applying…') : t.confirmComplete.split('?')[0]}
            </button>
            <button type="button" className="secondary-button" onClick={() => setPendingAction(null)} disabled={submitting}>{t.cancel}</button>
          </div>
        </div>
      )}
    </section>
  )
}

export function WorkDefinitionsScreen({ locale, token, onSessionExpired }: Props) {
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
      setState(stateFrom(error, onSessionExpired))
    }
  }, [token, onSessionExpired])
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
      setFeedback({ kind: 'success', message: locale === 'ar' ? `تم نشر التعريف ${String(definition.code)} بالإصدار الأول.` : `Definition ${String(definition.code)} published at version 1.` })
      await load()
    } catch (error) {
      setFeedback({ kind: 'error', message: stateFrom(error, onSessionExpired) === 'stale' ? common[locale].stale : common[locale].error })
    } finally {
      setSubmitting(false)
    }
  }
  return (
    <section className="organization-page" aria-labelledby="definitions-heading">
      <div className="page-heading">
        <h1 id="definitions-heading">{locale === 'ar' ? 'إدارة تعريفات العمل' : 'Work definition administration'}</h1>
        <p className="status-message">{locale === 'ar' ? 'يبقى كل سجل مثبتاً على الإصدار الذي بدأ به عند نشر إصدار أحدث.' : 'Existing records remain pinned to the version on which they started.'}</p>
      </div>
      <form className="inline-form" onSubmit={(event) => void create(event)} aria-describedby="definitions-form-help">
        <p id="definitions-form-help" className="visually-hidden">{locale === 'ar' ? 'أنشئ تعريفاً ثم ينشر النظام الإصدار الأول تلقائياً.' : 'Create a definition; the system publishes its first version automatically.'}</p>
        <label>{common[locale].code}<input name="code" required pattern="[a-z][a-z0-9-]+" aria-describedby="definitions-code-help" /></label>
        <span id="definitions-code-help" className="field-help">{locale === 'ar' ? 'أحرف لاتينية صغيرة وأرقام وشرطات فقط.' : 'Lowercase Latin letters, digits, and dashes only.'}</span>
        <label>{common[locale].name}<input name="name" required /></label>
        <button type="submit" className="primary-button" disabled={submitting}>
          {submitting ? (locale === 'ar' ? 'جارٍ النشر…' : 'Publishing…') : common[locale].create}
        </button>
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
            {locale === 'ar' ? `إجمالي التعريفات: ${items.length}` : `Total definitions: ${items.length}`}
          </p>
          <EntityTable locale={locale} items={items} />
        </>
      )}
    </section>
  )
}

export function WorkflowAdminScreen({ locale, token, onSessionExpired }: Props) {
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
      setState(stateFrom(error, onSessionExpired))
    }
  }, [token, onSessionExpired])
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
      setFeedback({ kind: 'success', message: locale === 'ar' ? `تم نشر المسار ${String(form.get('code'))} بالإصدار الأول.` : `Workflow ${String(form.get('code'))} published at version 1.` })
      await load()
    } catch (error) {
      setFeedback({ kind: 'error', message: stateFrom(error, onSessionExpired) === 'stale' ? common[locale].stale : common[locale].error })
    } finally {
      setSubmitting(false)
    }
  }
  return (
    <section className="organization-page" aria-labelledby="workflow-heading">
      <div className="page-heading">
        <h1 id="workflow-heading">{locale === 'ar' ? 'إدارة المسارات' : 'Workflow administration'}</h1>
      </div>
      <form className="inline-form" onSubmit={(event) => void create(event)}>
        <label>{common[locale].code}<input name="code" required pattern="[a-z][a-z0-9_]+" /></label>
        <label>{common[locale].name}<input name="name" required /></label>
        <button type="submit" className="primary-button" disabled={submitting}>
          {submitting ? (locale === 'ar' ? 'جارٍ النشر…' : 'Publishing…') : common[locale].create}
        </button>
      </form>
      {feedback && (
        <div className={`status-message ${feedback.kind === 'error' ? 'error' : 'success'}`} role={feedback.kind === 'error' ? 'alert' : 'status'} aria-live="polite">
          {feedback.message}
        </div>
      )}
      <ScreenState locale={locale} state={state} retry={() => void load()} />
      {state === 'ready' && (
        <div className="form-grid">
          <div className="surface-card">
            <h2>{locale === 'ar' ? 'التعريفات المنشورة' : 'Published definitions'}</h2>
            <p className="status-message" aria-live="polite">{locale === 'ar' ? `العدد: ${definitions.length}` : `Count: ${definitions.length}`}</p>
            <EntityTable locale={locale} items={definitions} />
          </div>
          <div className="surface-card">
            <h2>{locale === 'ar' ? 'المسارات الجارية وخطواتها' : 'Running instances and steps'}</h2>
            <p className="status-message" aria-live="polite">{locale === 'ar' ? `العدد: ${instances.length}` : `Count: ${instances.length}`}</p>
            <EntityTable locale={locale} items={instances} />
          </div>
        </div>
      )}
    </section>
  )
}

export function SearchScreen({ locale, token, onSessionExpired }: Props) {
  const [items, setItems] = useState<R1Entity[]>([])
  const [state, setState] = useState<State>('empty')
  const [query, setQuery] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [lastQuery, setLastQuery] = useState('')

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (submitting) return
    const form = new FormData(event.currentTarget)
    const q = String(form.get('q') ?? '').trim()
    if (!q) return
    setSubmitting(true)
    setState('loading')
    try {
      const result = await searchRecords(token, q)
      let values = result.items ?? []
      const type = String(form.get('type') ?? '')
      const status = String(form.get('status') ?? '')
      if (type) values = values.filter((item) => item.resource_type === type || item.record_type === type)
      if (status) values = values.filter((item) => item.status === status)
      setItems(values)
      setLastQuery(q)
      setState(values.length ? 'ready' : 'empty')
    } catch (error) {
      setState(stateFrom(error, onSessionExpired))
    } finally {
      setSubmitting(false)
    }
  }

  function reset() {
    setQuery('')
    setItems([])
    setLastQuery('')
    setState('empty')
  }

  return (
    <section className="organization-page" aria-labelledby="search-heading">
      <div className="page-heading">
        <h1 id="search-heading">{locale === 'ar' ? 'البحث' : 'Search'}</h1>
        <p className="status-message">{locale === 'ar' ? 'يعيد الخادم النتائج المصرح بها فقط؛ لا تُكشف عناوين الموارد المحجوبة.' : 'The server returns authorized results only; denied resource titles are never exposed.'}</p>
      </div>
      <form className="inline-form" onSubmit={(event) => void submit(event)} aria-describedby="search-help">
        <label>{locale === 'ar' ? 'نص البحث' : 'Search text'}<input name="q" required value={query} onChange={(event) => setQuery(event.currentTarget.value)} placeholder={locale === 'ar' ? 'مثال: تقرير، طلب، تعريف' : 'e.g. report, request, definition'} /></label>
        <label>{locale === 'ar' ? 'النوع' : 'Type'}<select name="type" defaultValue="">
          <option value="">{locale === 'ar' ? 'الكل' : 'All'}</option>
          <option value="work_record">Work record</option>
          <option value="task">Task</option>
          <option value="document">Document</option>
        </select></label>
        <label>{common[locale].status}<select name="status" defaultValue="">
          <option value="">{locale === 'ar' ? 'الكل' : 'All'}</option>
          <option value="draft">Draft</option>
          <option value="submitted">Submitted</option>
          <option value="in_review">In review</option>
          <option value="approved">Approved</option>
          <option value="completed">Completed</option>
        </select></label>
        <button type="submit" className="primary-button" disabled={submitting || !query.trim()}>
          {submitting ? (locale === 'ar' ? 'جارٍ البحث…' : 'Searching…') : (locale === 'ar' ? 'بحث' : 'Search')}
        </button>
        {(lastQuery || items.length > 0) && (
          <button type="button" className="secondary-button" onClick={reset} disabled={submitting}>
            {locale === 'ar' ? 'مسح' : 'Clear'}
          </button>
        )}
      </form>
      <p id="search-help" className="visually-hidden">{locale === 'ar' ? 'اختر نصاً ثم فلاتر اختيارية ثم ابحث.' : 'Enter text plus optional filters, then search.'}</p>
      {state === 'ready' && lastQuery && (
        <p className="status-message" aria-live="polite">
          {locale === 'ar' ? `النتائج المصرح بها لـ "${lastQuery}": ${items.length}` : `Authorized results for "${lastQuery}": ${items.length}`}
        </p>
      )}
      <ScreenState locale={locale} state={state} retry={() => undefined} />
      {state === 'ready' && <EntityTable locale={locale} items={items} />}
    </section>
  )
}

const REPORT_ID = '019f7000-0000-7000-8000-000000000901'
const DASHBOARD_ID = '019f7000-0000-7000-8000-000000000902'

export function ReportsScreen({ locale, token, onSessionExpired }: Props) {
  const [report, setReport] = useState<R1Collection | null>(null)
  const [state, setState] = useState<State>('loading')
  const [exportItem, setExportItem] = useState<R1Entity | null>(null)
  const [exporting, setExporting] = useState(false)
  const [exportError, setExportError] = useState<string | null>(null)

  const load = useCallback(async () => {
    setState('loading')
    try {
      const result = await getReport(token, REPORT_ID)
      setReport(result)
      setState('ready')
    } catch (error) {
      setState(stateFrom(error, onSessionExpired))
    }
  }, [token, onSessionExpired])
  useEffect(() => { void load() }, [load])

  async function createExport() {
    if (exporting) return
    setExporting(true)
    setExportError(null)
    try {
      const created = await requestReportExport(token, REPORT_ID)
      setExportItem(created)
      if (created.id) {
        const refreshed = await getReportExport(token, String(created.id))
        setExportItem(refreshed)
      }
    } catch (error) {
      setExportError(stateFrom(error, onSessionExpired) === 'forbidden' ? common[locale].forbidden : common[locale].error)
    } finally {
      setExporting(false)
    }
  }

  const exportStatus = exportItem ? String(exportItem.status ?? 'queued').toLowerCase() : null
  const exportReady = exportStatus === 'ready' || exportStatus === 'completed' || exportStatus === 'available'
  const exportInProgress = exportStatus !== null && !exportReady && exportStatus !== 'failed'

  return (
    <section className="organization-page" aria-labelledby="reports-heading">
      <div className="page-heading">
        <h1 id="reports-heading">{locale === 'ar' ? 'التقارير' : 'Reports'}</h1>
      </div>
      <ScreenState locale={locale} state={state} retry={() => void load()} />
      {state === 'ready' && report && (
        <>
          <div className="dashboard-kpi-grid" role="group" aria-label={locale === 'ar' ? 'مؤشرات التقرير' : 'Report KPIs'}>
            <article className="dashboard-kpi">
              <span>{locale === 'ar' ? 'العدد ضمن النطاق' : 'Count in scope'}</span>
              <strong>{report.total ?? report.items.length}</strong>
            </article>
            <article className="dashboard-kpi">
              <span>{locale === 'ar' ? 'العناصر المعروضة' : 'Items rendered'}</span>
              <strong>{report.items?.length ?? 0}</strong>
            </article>
          </div>
          <EntityTable locale={locale} items={report.items ?? []} />
          <div className="surface-card" aria-labelledby="export-card-heading">
            <h2 id="export-card-heading">{locale === 'ar' ? 'تصدير التقرير' : 'Export report'}</h2>
            <button type="button" className="primary-button" onClick={() => void createExport()} disabled={exporting}>
              {exporting ? (locale === 'ar' ? 'جارٍ إنشاء التصدير…' : 'Requesting export…') : (locale === 'ar' ? 'طلب تصدير' : 'Request export')}
            </button>
            {exportError && <div className="status-message error" role="alert">{exportError}</div>}
            {exportItem && (
              <div className="status-message" role="status" aria-live="polite">
                {locale === 'ar' ? 'حالة التصدير' : 'Export status'}: <strong>{String(exportItem.status ?? 'queued')}</strong>
                {exportInProgress && (
                  <progress max={100} value={50} aria-label={locale === 'ar' ? 'جارٍ المعالجة' : 'Processing'} />
                )}
                {exportReady && Boolean(exportItem.download_url) && (
                  <> — <a href={String(exportItem.download_url)}>{locale === 'ar' ? 'تنزيل' : 'Download'}</a></>
                )}
              </div>
            )}
          </div>
        </>
      )}
    </section>
  )
}

export function AdaptiveDashboard({ locale, token, scopeId, onSessionExpired }: Props & { scopeId: string }) {
  const [data, setData] = useState<R1Collection | null>(null)
  const [state, setState] = useState<State>('loading')
  const [lastRefreshedAt, setLastRefreshedAt] = useState<Date | null>(null)

  const load = useCallback(async () => {
    setState('loading')
    try {
      const result = await getDashboard(token, DASHBOARD_ID, scopeId)
      setData(result)
      setLastRefreshedAt(new Date())
      setState('ready')
    } catch (error) {
      setState(stateFrom(error, onSessionExpired))
    }
  }, [token, scopeId, onSessionExpired])
  useEffect(() => { void load() }, [load])

  const totalCount = data?.total ?? data?.items.length ?? 0

  return (
    <section aria-labelledby="adaptive-dashboard-heading">
      <h2 id="adaptive-dashboard-heading">{locale === 'ar' ? 'اللوحة التكيفية حسب الدور والنطاق' : 'Role and scope adaptive dashboard'}</h2>
      <p>{locale === 'ar' ? 'الأرقام التالية أعادها الخادم للنطاق الحالي.' : 'The server returned these figures for the current scope.'}</p>
      {lastRefreshedAt && (
        <p className="status-message">{locale === 'ar' ? 'آخر تحديث' : 'Last refreshed'}: {lastRefreshedAt.toLocaleTimeString(locale === 'ar' ? 'ar-SA' : 'en-US')}</p>
      )}
      <ScreenState locale={locale} state={state} retry={() => void load()} />
      {state === 'ready' && data && (
        <>
          <div className="dashboard-kpi-grid" role="group" aria-label={locale === 'ar' ? 'مؤشرات النطاق' : 'Scope KPIs'}>
            <article className="dashboard-kpi">
              <span>{locale === 'ar' ? 'إجمالي ضمن النطاق' : 'Total in scope'}</span>
              <strong>{totalCount}</strong>
            </article>
            <article className="dashboard-kpi">
              <span>{locale === 'ar' ? 'النطاق الفعلي' : 'Effective scope'}</span>
              <strong dir="ltr">{scopeId || (locale === 'ar' ? '—' : '—')}</strong>
            </article>
          </div>
          <nav className="dashboard-links" aria-label={locale === 'ar' ? 'روابط سريعة' : 'Quick links'}>
            <a href="/tasks" onClick={(event) => { event.preventDefault(); window.history.pushState({}, '', '/tasks'); window.dispatchEvent(new PopStateEvent('popstate')) }}>
              {locale === 'ar' ? 'فتح المهام' : 'Open tasks'}
            </a>
            <a href="/search" onClick={(event) => { event.preventDefault(); window.history.pushState({}, '', '/search'); window.dispatchEvent(new PopStateEvent('popstate')) }}>
              {locale === 'ar' ? 'البحث في السجلات' : 'Search records'}
            </a>
            <a href="/reports" onClick={(event) => { event.preventDefault(); window.history.pushState({}, '', '/reports'); window.dispatchEvent(new PopStateEvent('popstate')) }}>
              {locale === 'ar' ? 'عرض التقارير' : 'View reports'}
            </a>
          </nav>
          <EntityTable locale={locale} items={data.items ?? []} />
        </>
      )}
    </section>
  )
}

export function NotificationsScreen({ locale, token, notifications, onRead, onOpen, onSessionExpired }: Props & { notifications: Array<{ id: string; title: string; is_read: boolean; source: { record_id: string } }>; onRead: () => void; onOpen: (id: string) => void }) {
  const [state, setState] = useState<State>(notifications.length ? 'ready' : 'empty')
  const [busy, setBusy] = useState<string | null>(null)
  useEffect(() => setState(notifications.length ? 'ready' : 'empty'), [notifications])

  const unreadCount = notifications.filter((item) => !item.is_read).length
  const readCount = notifications.length - unreadCount

  async function open(item: { id: string; source: { record_id: string } }) {
    if (busy) return
    setBusy(item.id)
    try {
      await markNotificationRead(token, item.id)
      onRead()
      onOpen(item.source.record_id)
    } catch (error) {
      setState(stateFrom(error, onSessionExpired))
    } finally {
      setBusy(null)
    }
  }

  return (
    <section className="organization-page" aria-labelledby="notifications-page-heading">
      <div className="page-heading">
        <h1 id="notifications-page-heading">{locale === 'ar' ? 'الإشعارات' : 'Notifications'}</h1>
        {notifications.length > 0 && (
          <p className="status-message" aria-live="polite">
            {locale === 'ar' ? `غير المقروء: ${unreadCount} | المقروء: ${readCount}` : `Unread: ${unreadCount} | Read: ${readCount}`}
          </p>
        )}
      </div>
      <ScreenState locale={locale} state={state} retry={onRead} />
      {state === 'ready' && (
        <div className="card-list">
          {notifications.map((item) => (
            <article className={`surface-card ${item.is_read ? '' : 'unread'}`} key={item.id} aria-label={item.title}>
              <h2>{item.title}</h2>
              <span className="status-chip" data-state={item.is_read ? 'read' : 'unread'}>
                {item.is_read ? (locale === 'ar' ? 'مقروء' : 'Read') : (locale === 'ar' ? 'غير مقروء' : 'Unread')}
              </span>
              <button className="primary-button" type="button" onClick={() => void open(item)} disabled={busy === item.id || busy !== null} aria-busy={busy === item.id}>
                {busy === item.id ? (locale === 'ar' ? 'جارٍ الفتح…' : 'Opening…') : (locale === 'ar' ? 'فتح المورد' : 'Open resource')}
              </button>
            </article>
          ))}
        </div>
      )}
    </section>
  )
}

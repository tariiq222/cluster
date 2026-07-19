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
  ar: { loading: 'جارٍ التحميل…', empty: 'لا توجد بيانات متاحة', forbidden: 'لا تملك صلاحية عرض هذه الشاشة.', error: 'تعذر تحميل البيانات.', stale: 'تغيّرت البيانات على الخادم. حدّث الشاشة ثم أعد المحاولة.', retry: 'إعادة المحاولة', create: 'إنشاء', publish: 'نشر', status: 'الحالة', name: 'الاسم', code: 'الرمز' },
  en: { loading: 'Loading…', empty: 'No data is available', forbidden: 'You do not have permission to view this screen.', error: 'We could not load the data.', stale: 'The server data changed. Refresh and try again.', retry: 'Try again', create: 'Create', publish: 'Publish', status: 'Status', name: 'Name', code: 'Code' },
} as const

function stateFrom(error: unknown, expired: () => void): State {
  if (error instanceof ApiError && error.status === 401) { expired(); return 'error' }
  if (error instanceof ApiError && error.status === 403) return 'forbidden'
  if (error instanceof ApiError && (error.status === 409 || error.status === 412)) return 'stale'
  return 'error'
}

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
  const [items, setItems] = useState<R1Entity[]>([]); const [state, setState] = useState<State>('loading'); const [selected, setSelected] = useState<R1Entity | null>(null)
  const load = useCallback(async () => { setState('loading'); try { const result = await listTasks(token); setItems(result.items ?? []); setState(result.items?.length ? 'ready' : 'empty') } catch (error) { setState(stateFrom(error, onSessionExpired)) } }, [token, onSessionExpired])
  useEffect(() => { void load() }, [load])
  async function act(item: R1Entity, action: 'complete' | 'return-completion') { try { await transitionTask(token, String(item.id), action, Number(item.lock_version ?? 1)); await load() } catch (error) { setState(stateFrom(error, onSessionExpired)) } }
  return <section className="organization-page" aria-labelledby="tasks-heading"><div className="page-heading"><h1 id="tasks-heading">{locale === 'ar' ? 'مهامي' : 'My tasks'}</h1></div><ScreenState locale={locale} state={state} retry={() => void load()} />{state === 'ready' && <EntityTable locale={locale} items={items} actions={(item) => <div className="table-actions"><button type="button" className="quiet-button" onClick={() => setSelected(item)}>{locale === 'ar' ? 'فتح' : 'Open'}</button><button type="button" className="primary-button" onClick={() => void act(item, 'complete')}>{locale === 'ar' ? 'إكمال' : 'Complete'}</button><button type="button" className="secondary-button" onClick={() => void act(item, 'return-completion')}>{locale === 'ar' ? 'إعادة' : 'Return'}</button></div>} />}{selected && <div className="surface-card" aria-live="polite"><h2>{String(selected.title ?? selected.id)}</h2><p>{String(selected.description ?? (locale === 'ar' ? 'لا يوجد وصف' : 'No description'))}</p><p>{locale === 'ar' ? 'خطوة المسار المنتظرة' : 'Waiting workflow step'}: {String(selected.workflow_step_id ?? '—')}</p></div>}</section>
}

export function WorkDefinitionsScreen({ locale, token, onSessionExpired }: Props) {
  const [items, setItems] = useState<R1Entity[]>([]); const [state, setState] = useState<State>('loading')
  const load = useCallback(async () => { setState('loading'); try { const value = await listWorkDefinitions(token); setItems(value.items ?? []); setState(value.items?.length ? 'ready' : 'empty') } catch (error) { setState(stateFrom(error, onSessionExpired)) } }, [token, onSessionExpired])
  useEffect(() => { void load() }, [load])
  async function create(event: FormEvent<HTMLFormElement>) { event.preventDefault(); const form = new FormData(event.currentTarget); try { const definition = await createWorkDefinition(token, { code: String(form.get('code')), name: String(form.get('name')), default_classification: 'internal' }); const version = await createWorkDefinitionVersion(token, String(definition.id), { schema_document: { type: 'object', properties: { title: { type: 'string' }, description: { type: 'string' } } }, field_policy_key: 'default' }); await publishWorkDefinitionVersion(token, String(version.id), Number(version.lock_version ?? 1)); event.currentTarget.reset(); await load() } catch (error) { setState(stateFrom(error, onSessionExpired)) } }
  return <section className="organization-page" aria-labelledby="definitions-heading"><div className="page-heading"><h1 id="definitions-heading">{locale === 'ar' ? 'إدارة تعريفات العمل' : 'Work definition administration'}</h1></div><form className="inline-form" onSubmit={(event) => void create(event)}><label>{common[locale].code}<input name="code" required pattern="[a-z][a-z0-9-]+" /></label><label>{common[locale].name}<input name="name" required /></label><button className="primary-button">{common[locale].create}</button></form><ScreenState locale={locale} state={state} retry={() => void load()} />{state === 'ready' && <><EntityTable locale={locale} items={items} /><p className="status-message">{locale === 'ar' ? 'يبقى كل سجل مثبتاً على الإصدار الذي بدأ به عند نشر إصدار أحدث.' : 'Existing records remain pinned to the version on which they started.'}</p></>}</section>
}

export function WorkflowAdminScreen({ locale, token, onSessionExpired }: Props) {
  const [definitions, setDefinitions] = useState<R1Entity[]>([]); const [instances, setInstances] = useState<R1Entity[]>([]); const [state, setState] = useState<State>('loading')
  const load = useCallback(async () => { setState('loading'); try { const [d, i] = await Promise.all([listWorkflowDefinitions(token), listWorkflowInstances(token)]); setDefinitions(d.items ?? []); setInstances(i.items ?? []); setState(d.items?.length || i.items?.length ? 'ready' : 'empty') } catch (error) { setState(stateFrom(error, onSessionExpired)) } }, [token, onSessionExpired])
  useEffect(() => { void load() }, [load])
  async function create(event: FormEvent<HTMLFormElement>) { event.preventDefault(); const form = new FormData(event.currentTarget); try { const created = await createWorkflowDefinition(token, { code: String(form.get('code')), name: String(form.get('name')), source_record_type: 'work_record' }); await publishWorkflowVersion(token, String(created.version.id), Number(created.version.lock_version ?? 1)); event.currentTarget.reset(); await load() } catch (error) { setState(stateFrom(error, onSessionExpired)) } }
  return <section className="organization-page" aria-labelledby="workflow-heading"><div className="page-heading"><h1 id="workflow-heading">{locale === 'ar' ? 'إدارة المسارات' : 'Workflow administration'}</h1></div><form className="inline-form" onSubmit={(event) => void create(event)}><label>{common[locale].code}<input name="code" required pattern="[a-z][a-z0-9_]+" /></label><label>{common[locale].name}<input name="name" required /></label><button className="primary-button">{common[locale].create}</button></form><ScreenState locale={locale} state={state} retry={() => void load()} />{state === 'ready' && <div className="form-grid"><div className="surface-card"><h2>{locale === 'ar' ? 'التعريفات المنشورة' : 'Published definitions'}</h2><EntityTable locale={locale} items={definitions} /></div><div className="surface-card"><h2>{locale === 'ar' ? 'المسارات الجارية وخطواتها' : 'Running instances and steps'}</h2><EntityTable locale={locale} items={instances} /></div></div>}</section>
}

export function SearchScreen({ locale, token, onSessionExpired }: Props) {
  const [items, setItems] = useState<R1Entity[]>([]); const [state, setState] = useState<State>('empty')
  async function submit(event: FormEvent<HTMLFormElement>) { event.preventDefault(); const form = new FormData(event.currentTarget); setState('loading'); try { const result = await searchRecords(token, String(form.get('q'))); let values = result.items ?? []; const type = String(form.get('type')); const status = String(form.get('status')); if (type) values = values.filter((item) => item.resource_type === type || item.record_type === type); if (status) values = values.filter((item) => item.status === status); setItems(values); setState(values.length ? 'ready' : 'empty') } catch (error) { setState(stateFrom(error, onSessionExpired)) } }
  return <section className="organization-page" aria-labelledby="search-heading"><div className="page-heading"><h1 id="search-heading">{locale === 'ar' ? 'البحث' : 'Search'}</h1></div><form className="inline-form" onSubmit={(event) => void submit(event)}><label>{locale === 'ar' ? 'نص البحث' : 'Search text'}<input name="q" required /></label><label>{locale === 'ar' ? 'النوع' : 'Type'}<select name="type"><option value="">{locale === 'ar' ? 'الكل' : 'All'}</option><option value="work_record">Work record</option></select></label><label>{common[locale].status}<select name="status"><option value="">{locale === 'ar' ? 'الكل' : 'All'}</option><option value="submitted">Submitted</option><option value="completed">Completed</option></select></label><button className="primary-button">{locale === 'ar' ? 'بحث' : 'Search'}</button></form><p>{locale === 'ar' ? 'يعيد الخادم النتائج المصرح بها فقط؛ لا تُكشف عناوين الموارد المحجوبة.' : 'The server returns authorized results only; denied resource titles are never exposed.'}</p><ScreenState locale={locale} state={state} retry={() => undefined} />{state === 'ready' && <EntityTable locale={locale} items={items} />}</section>
}

const REPORT_ID = '019f7000-0000-7000-8000-000000000901'
const DASHBOARD_ID = '019f7000-0000-7000-8000-000000000902'

export function ReportsScreen({ locale, token, onSessionExpired }: Props) {
  const [report, setReport] = useState<R1Collection | null>(null); const [state, setState] = useState<State>('loading'); const [exportItem, setExportItem] = useState<R1Entity | null>(null)
  const load = useCallback(async () => { setState('loading'); try { const result = await getReport(token, REPORT_ID); setReport(result); setState('ready') } catch (error) { setState(stateFrom(error, onSessionExpired)) } }, [token, onSessionExpired])
  useEffect(() => { void load() }, [load])
  async function createExport() { try { const created = await requestReportExport(token, REPORT_ID); setExportItem(created); if (created.id) setExportItem(await getReportExport(token, String(created.id))) } catch (error) { setState(stateFrom(error, onSessionExpired)) } }
  return <section className="organization-page" aria-labelledby="reports-heading"><div className="page-heading"><h1 id="reports-heading">{locale === 'ar' ? 'التقارير' : 'Reports'}</h1></div><ScreenState locale={locale} state={state} retry={() => void load()} />{state === 'ready' && report && <><div className="dashboard-kpi-grid"><article className="dashboard-kpi"><span>{locale === 'ar' ? 'العدد ضمن النطاق' : 'Count in scope'}</span><strong>{report.total ?? report.items.length}</strong></article></div><EntityTable locale={locale} items={report.items ?? []} /><button type="button" className="primary-button" onClick={() => void createExport()}>{locale === 'ar' ? 'طلب تصدير' : 'Request export'}</button>{exportItem && <div className="status-message" role="status">{locale === 'ar' ? 'حالة التصدير' : 'Export status'}: {String(exportItem.status ?? 'queued')}{Boolean(exportItem.download_url) && <> — <a href={String(exportItem.download_url)}>{locale === 'ar' ? 'تنزيل' : 'Download'}</a></>}</div>}</>}</section>
}

export function AdaptiveDashboard({ locale, token, scopeId, onSessionExpired }: Props & { scopeId: string }) {
  const [data, setData] = useState<R1Collection | null>(null); const [state, setState] = useState<State>('loading')
  const load = useCallback(async () => { setState('loading'); try { const result = await getDashboard(token, DASHBOARD_ID, scopeId); setData(result); setState('ready') } catch (error) { setState(stateFrom(error, onSessionExpired)) } }, [token, scopeId, onSessionExpired])
  useEffect(() => { void load() }, [load])
  return <section aria-labelledby="adaptive-dashboard-heading"><h2 id="adaptive-dashboard-heading">{locale === 'ar' ? 'اللوحة التكيفية حسب الدور والنطاق' : 'Role and scope adaptive dashboard'}</h2><ScreenState locale={locale} state={state} retry={() => void load()} />{state === 'ready' && data && <><p>{locale === 'ar' ? 'الأرقام التالية أعادها الخادم للنطاق الحالي.' : 'The server returned these figures for the current scope.'}</p><EntityTable locale={locale} items={data.items ?? []} /></>}</section>
}

export function NotificationsScreen({ locale, token, notifications, onRead, onOpen, onSessionExpired }: Props & { notifications: Array<{ id: string; title: string; is_read: boolean; source: { record_id: string } }>; onRead: () => void; onOpen: (id: string) => void }) {
  const [state, setState] = useState<State>(notifications.length ? 'ready' : 'empty')
  useEffect(() => setState(notifications.length ? 'ready' : 'empty'), [notifications])
  async function open(item: { id: string; source: { record_id: string } }) { try { await markNotificationRead(token, item.id); onRead(); onOpen(item.source.record_id) } catch (error) { setState(stateFrom(error, onSessionExpired)) } }
  return <section className="organization-page" aria-labelledby="notifications-page-heading"><div className="page-heading"><h1 id="notifications-page-heading">{locale === 'ar' ? 'الإشعارات' : 'Notifications'}</h1></div><ScreenState locale={locale} state={state} retry={onRead} />{state === 'ready' && <div className="card-list">{notifications.map((item) => <article className="surface-card" key={item.id}><h2>{item.title}</h2><span className="status-chip">{item.is_read ? (locale === 'ar' ? 'مقروء' : 'Read') : (locale === 'ar' ? 'غير مقروء' : 'Unread')}</span><button className="primary-button" type="button" onClick={() => void open(item)}>{locale === 'ar' ? 'فتح المورد' : 'Open resource'}</button></article>)}</div>}</section>
}

import { useCallback, useEffect, useMemo, useState, type FormEvent } from 'react'
import { ApiError } from '../../api'
import {
  createDelegation,
  createRoleAssignment,
  createSupervisoryRelationship,
  explainAccessDecision,
  listAuthorization,
  listSupervisoryRelationships,
  simulateAccessDecision,
  updateAuthorizationAdminResource,
  type AccessDecision,
  type AuthorizationAdminPatch,
  type AuthorizationItem,
  type AuthorizationResource,
} from '../../api/r1'

export type Locale = 'ar' | 'en'
export type AdminResource = AuthorizationResource | 'supervisory'
export type AdminState = 'loading' | 'ready' | 'empty' | 'forbidden' | 'not-found' | 'conflict' | 'stale' | 'error'

const labels = {
  ar: {
    roles: 'الأدوار', capabilities: 'الصلاحيات', 'role-assignments': 'إسنادات الأدوار', delegations: 'التفويضات',
    'classification-policies': 'سياسات التصنيف', 'field-access-templates': 'قوالب وصول الحقول', supervisory: 'العلاقات الإشرافية',
    title: 'إدارة التفويض والوصول', intro: 'بيانات مصفاة من خادم التفويض. لا تُتخذ قرارات الصلاحية في المتصفح.', loading: 'جارٍ التحميل…',
    empty: 'لا توجد سجلات متاحة', forbidden: 'لا تملك صلاحية عرض هذه البيانات.', notFound: 'السجل غير موجود أو لم يعد متاحاً.',
    conflict: 'حدث تعارض. حدّث البيانات ثم أعد المحاولة.', stale: 'البيانات قديمة. أعد المحاولة.', error: 'تعذر تحميل البيانات.', retry: 'إعادة المحاولة',
    create: 'إنشاء', update: 'حفظ التعديل', code: 'الرمز', name: 'الاسم', status: 'الحالة', scope: 'النطاق', scopeId: 'معرّف النطاق',
    subject: 'معرّف المستخدم المستهدف', role: 'معرّف الدور', start: 'بداية السريان', end: 'نهاية السريان', policy: 'وثيقة السياسة (JSON)',
    choose: 'اختر سجلاً', roleMatrix: 'مصفوفة الدور والصلاحية', wizard: 'معالج الإسناد والتفويض', denies: 'المنع الصريح والسياسات',
    simulator: 'محاكي قرار الوصول', audit: 'عرض التدقيق والشرح', decisionId: 'معرّف القرار', loadExplanation: 'عرض الشرح',
    simulate: 'محاكاة القرار', requestJson: 'طلب المحاكاة المقدم من الخادم (JSON)', invalidJson: 'أدخل JSON صالحاً.', invalid: 'أكمل الحقول المطلوبة.', saved: 'تم الحفظ.',
  },
  en: {
    roles: 'Roles', capabilities: 'Capabilities', 'role-assignments': 'Role assignments', delegations: 'Delegations',
    'classification-policies': 'Classification policies', 'field-access-templates': 'Field access templates', supervisory: 'Supervisory relationships',
    title: 'Authorization administration', intro: 'Data is filtered by the authorization service. The browser never makes access decisions.', loading: 'Loading…',
    empty: 'No records available', forbidden: 'You do not have permission to view this data.', notFound: 'The record was not found or is no longer available.',
    conflict: 'A conflict occurred. Refresh and try again.', stale: 'The data is stale. Try again.', error: 'We could not load the data.', retry: 'Try again',
    create: 'Create', update: 'Save change', code: 'Code', name: 'Name', status: 'Status', scope: 'Scope', scopeId: 'Scope ID',
    subject: 'Subject user ID', role: 'Role ID', start: 'Start time', end: 'End time', policy: 'Policy document (JSON)',
    choose: 'Select a record', roleMatrix: 'Role-capability matrix', wizard: 'Assignment and delegation wizard', denies: 'Explicit denies and policies',
    simulator: 'Access decision simulator', audit: 'Audit and explanation view', decisionId: 'Decision ID', loadExplanation: 'Show explanation',
    simulate: 'Simulate decision', requestJson: 'Server-provided simulation request (JSON)', invalidJson: 'Enter valid JSON.', invalid: 'Complete the required fields.', saved: 'Saved.',
  },
} as const

export const RESOURCE_LABELS: Record<AdminResource, string> = {
  roles: 'Roles', capabilities: 'Capabilities', 'role-assignments': 'Role assignments', delegations: 'Delegations',
  'classification-policies': 'Classification policies', 'field-access-templates': 'Field access templates', supervisory: 'Supervisory relationships',
}

export function resourceCreateType(resource: AuthorizationResource): string {
  return ({ roles: 'role', capabilities: 'capability', 'role-assignments': 'role_assignment', delegations: 'delegation', 'classification-policies': 'classification_policy', 'field-access-templates': 'field_access_template' } as Record<AuthorizationResource, string>)[resource]
}

export function parsePolicyDocument(value: string): Record<string, unknown> | undefined {
  const trimmed = value.trim()
  if (!trimmed) return undefined
  const parsed: unknown = JSON.parse(trimmed)
  if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) throw new Error('policy')
  return parsed as Record<string, unknown>
}

export function validateAdminForm(input: { code?: string; name?: string; policyDocument?: string }): string | null {
  if (!input.code?.trim()) return 'code'
  if (input.name !== undefined && input.name.length > 255) return 'name'
  if (input.policyDocument?.trim()) {
    try { parsePolicyDocument(input.policyDocument) } catch { return 'policy' }
  }
  return null
}

export function mapAuthorizationRows(items: AuthorizationItem[]): Array<{ id: string; name: string; code: string; status: string; lockVersion?: number }> {
  return items.map((item, index) => ({ id: typeof item.id === 'string' ? item.id : `item-${index}`, name: typeof item.name === 'string' ? item.name : '—', code: typeof item.code === 'string' ? item.code : '—', status: typeof item.status === 'string' ? item.status : '—', lockVersion: item.lock_version }))
}

function stateFrom(error: unknown, onSessionExpired: () => void): AdminState {
  if (error instanceof ApiError && error.status === 401) { onSessionExpired(); return 'error' }
  if (error instanceof ApiError && error.status === 403) return 'forbidden'
  if (error instanceof ApiError && error.status === 404) return 'not-found'
  if (error instanceof ApiError && error.status === 409) return 'conflict'
  if (error instanceof ApiError && error.status === 412) return 'stale'
  return 'error'
}

function StatusPanel({ state, text, retry }: { state: AdminState; text: typeof labels.ar; retry: () => void }) {
  if (state === 'loading') return <div className="skeleton-list" aria-label={text.loading}>{[0, 1, 2].map((item) => <div className="skeleton-row" aria-hidden="true" key={item} />)}</div>
  if (state === 'ready') return null
  const message = state === 'empty' ? text.empty : state === 'forbidden' ? text.forbidden : state === 'not-found' ? text.notFound : state === 'conflict' ? text.conflict : state === 'stale' ? text.stale : text.error
  return <div className="state-panel" role={state === 'empty' ? 'status' : 'alert'}><p>{message}</p>{state !== 'empty' && state !== 'forbidden' && state !== 'not-found' && <button type="button" className="secondary-button" onClick={retry}>{text.retry}</button>}</div>
}

function ItemTable({ items, locale, onSelect }: { items: AuthorizationItem[]; locale: Locale; onSelect: (item: AuthorizationItem) => void }) {
  const text = labels[locale]
  return <div className="table-scroll"><table className="data-table"><caption className="visually-hidden">{text.title}</caption><thead><tr><th>{text.name}</th><th>{text.code}</th><th>{text.status}</th><th>{locale === 'ar' ? 'إجراء' : 'Action'}</th></tr></thead><tbody>{mapAuthorizationRows(items).map((row, index) => <tr key={row.id}><td>{row.name}</td><td>{row.code}</td><td>{row.status}</td><td><button type="button" className="secondary-button" onClick={() => onSelect(items[index])}>{text.update}</button></td></tr>)}</tbody></table></div>
}

function AdminForm({ resource, locale, token, onSessionExpired, onSaved }: { resource: AuthorizationResource; locale: Locale; token: string; onSessionExpired: () => void; onSaved: () => Promise<void> }) {
  const text = labels[locale]
  const [error, setError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); setError(null)
    const form = new FormData(event.currentTarget); const code = String(form.get('code') ?? '').trim(); const name = String(form.get('name') ?? '').trim(); const policy = String(form.get('policy') ?? '')
    if (validateAdminForm({ code, name, policyDocument: policy })) { setError(text.invalid); return }
    const input: Record<string, unknown> = { resource_type: resourceCreateType(resource), code, name: name || undefined, scope_type: String(form.get('scope') || '') || undefined, scope_id: String(form.get('scopeId') || '') || undefined, subject_user_id: String(form.get('subject') || '') || undefined, role_id: String(form.get('role') || '') || undefined, start_at: String(form.get('start') || '') || undefined, end_at: String(form.get('end') || '') || undefined, policy_document: parsePolicyDocument(policy) }
    setSaving(true)
    try { if (resource === 'role-assignments') await createRoleAssignment(input, token); else if (resource === 'delegations') await createDelegation(input, token); else throw new Error('creation-not-supported'); event.currentTarget.reset(); await onSaved() } catch (caught) { if (caught instanceof ApiError && caught.status === 401) onSessionExpired(); setError(caught instanceof ApiError && (caught.status === 409 || caught.status === 412) ? (caught.status === 412 ? text.stale : text.conflict) : text.error) } finally { setSaving(false) }
  }
  return <form className="inline-form" onSubmit={submit} noValidate aria-label={text.wizard}><label htmlFor="authorization-code">{text.code}<input id="authorization-code" name="code" required aria-required="true" /></label><label htmlFor="authorization-name">{text.name}<input id="authorization-name" name="name" /></label><label htmlFor="authorization-scope">{text.scope}<select id="authorization-scope" name="scope" defaultValue=""><option value="">—</option><option value="cluster">cluster</option><option value="facility">facility</option><option value="unit">unit</option><option value="record_set">record_set</option></select></label><label htmlFor="authorization-scope-id">{text.scopeId}<input id="authorization-scope-id" name="scopeId" /></label>{(resource === 'role-assignments' || resource === 'delegations') && <><label htmlFor="authorization-subject">{text.subject}<input id="authorization-subject" name="subject" required /></label><label htmlFor="authorization-role">{text.role}<input id="authorization-role" name="role" required={resource === 'role-assignments'} /></label></>}<label htmlFor="authorization-start">{text.start}<input id="authorization-start" name="start" type="datetime-local" /></label><label htmlFor="authorization-end">{text.end}<input id="authorization-end" name="end" type="datetime-local" /></label><label htmlFor="authorization-policy">{text.policy}<textarea id="authorization-policy" name="policy" rows={3} dir="ltr" /></label>{error && <p role="alert">{error}</p>}<button className="primary-button" type="submit" disabled={saving}>{saving ? text.loading : text.create}</button></form>
}

function EditPanel({ resource, item, locale, token, onSessionExpired, onSaved }: { resource: AuthorizationResource; item: AuthorizationItem; locale: Locale; token: string; onSessionExpired: () => void; onSaved: () => Promise<void> }) {
  const text = labels[locale]; const [name, setName] = useState(item.name ?? ''); const [status, setStatus] = useState(item.status ?? 'active'); const [error, setError] = useState<string | null>(null); const [saving, setSaving] = useState(false)
  async function submit(event: FormEvent) { event.preventDefault(); setSaving(true); setError(null); try { const patch: AuthorizationAdminPatch = { name, status: status as AuthorizationAdminPatch['status'] }; await updateAuthorizationAdminResource(resource, String(item.id), patch, token, item.lock_version); await onSaved() } catch (caught) { if (caught instanceof ApiError && caught.status === 401) onSessionExpired(); setError(caught instanceof ApiError && caught.status === 412 ? text.stale : caught instanceof ApiError && caught.status === 409 ? text.conflict : text.error) } finally { setSaving(false) } }
  return <form className="inline-form" onSubmit={submit} aria-label={text.update}><label htmlFor="edit-name">{text.name}<input id="edit-name" value={name} onChange={(event) => setName(event.target.value)} /></label><label htmlFor="edit-status">{text.status}<select id="edit-status" value={status} onChange={(event) => setStatus(event.target.value)}><option value="draft">draft</option><option value="active">active</option><option value="inactive">inactive</option><option value="revoked">revoked</option><option value="published">published</option></select></label>{error && <p role="alert">{error}</p>}<button className="primary-button" type="submit" disabled={saving}>{saving ? text.loading : text.update}</button></form>
}

export function RoleCapabilityMatrix({ items, locale }: { items: AuthorizationItem[]; locale: Locale }) {
  const text = labels[locale]
  return <section aria-labelledby="role-capability-heading"><h2 id="role-capability-heading">{text.roleMatrix}</h2><p>{locale === 'ar' ? 'عرض معلوماتي فقط؛ مصدر القرار هو الخادم.' : 'Read-only server data; policy decisions remain server-owned.'}</p><ItemTable items={items} locale={locale} onSelect={() => undefined} /></section>
}

export function AuthorizationState({ state, locale, onRetry }: { state: AdminState; locale: Locale; onRetry: () => void }) {
  return <StatusPanel state={state} text={labels[locale]} retry={onRetry} />
}

export function AuthorizationAdmin({ locale, token, resource, onSessionExpired }: { locale: Locale; token: string; resource: AdminResource; onSessionExpired: () => void }) {
  const text = labels[locale]; const [items, setItems] = useState<AuthorizationItem[]>([]); const [state, setState] = useState<AdminState>('loading'); const [selected, setSelected] = useState<AuthorizationItem | null>(null)
  const load = useCallback(async () => { setState('loading'); setSelected(null); try { const result = resource === 'supervisory' ? await listSupervisoryRelationships(token) : await listAuthorization(resource, token); setItems(result); setState(result.length ? 'ready' : 'empty') } catch (error) { setState(stateFrom(error, onSessionExpired)) } }, [onSessionExpired, resource, token])
  useEffect(() => { void load() }, [load])
  const title = text[resource]; const matrix = useMemo(() => resource === 'roles' || resource === 'capabilities', [resource])
  return <section className="organization-page authorization-page" aria-labelledby="authorization-heading" dir={locale === 'ar' ? 'rtl' : 'ltr'}><div className="page-heading page-heading-copy"><div><h1 id="authorization-heading">{title}</h1><p>{text.intro}</p></div></div>{(resource === 'role-assignments' || resource === 'delegations') && <><h2>{text.wizard}</h2><AdminForm resource={resource} locale={locale} token={token} onSessionExpired={onSessionExpired} onSaved={load} /></>}{state === 'loading' || state === 'empty' || state === 'forbidden' || state === 'not-found' || state === 'conflict' || state === 'stale' || state === 'error' ? <StatusPanel state={state} text={text} retry={() => void load()} /> : <><ItemTable items={items} locale={locale} onSelect={setSelected} />{selected && resource !== 'supervisory' && <EditPanel resource={resource} item={selected} locale={locale} token={token} onSessionExpired={onSessionExpired} onSaved={load} />}{matrix && <RoleCapabilityMatrix items={items} locale={locale} /></>}</>}</section>
}

export function AccessExplanation({ locale, token, decisionId, onSessionExpired }: { locale: Locale; token: string; decisionId?: string; onSessionExpired: () => void }) {
  const text = labels[locale]; const [id, setId] = useState(decisionId ?? ''); const [item, setItem] = useState<AccessDecision | null>(null); const [state, setState] = useState<'idle' | 'loading' | 'ready' | 'error'>('idle')
  async function load(event?: FormEvent) { event?.preventDefault(); if (!id.trim()) return setState('error'); setState('loading'); try { setItem(await explainAccessDecision(id.trim(), token)); setState('ready') } catch (error) { if (error instanceof ApiError && error.status === 401) onSessionExpired(); setState('error') } }
  return <section className="organization-page authorization-page" aria-labelledby="explanation-heading" dir={locale === 'ar' ? 'rtl' : 'ltr'}><h1 id="explanation-heading">{text.audit}</h1><p>{text.intro}</p><form className="inline-form" onSubmit={load}><label htmlFor="decision-id">{text.decisionId}</label><input id="decision-id" value={id} onChange={(event) => setId(event.target.value)} dir="ltr" /><button type="submit" className="primary-button" disabled={state === 'loading'}>{state === 'loading' ? text.loading : text.loadExplanation}</button></form>{state === 'error' && <div className="state-panel" role="alert"><p>{id.trim() ? text.error : text.invalid}</p></div>}{state === 'ready' && item && <pre className="access-explanation" aria-live="polite" dir="ltr">{JSON.stringify(item, null, 2)}</pre>}</section>
}

export function AccessDecisionSimulator({ locale, token, onSessionExpired }: { locale: Locale; token: string; onSessionExpired: () => void }) {
  const text = labels[locale]; const [request, setRequest] = useState(''); const [result, setResult] = useState<AccessDecision | null>(null); const [error, setError] = useState<string | null>(null); const [loading, setLoading] = useState(false)
  async function submit(event: FormEvent) { event.preventDefault(); setError(null); try { const parsed = JSON.parse(request) as Parameters<typeof simulateAccessDecision>[0]; setLoading(true); setResult(await simulateAccessDecision(parsed, token)) } catch (caught) { if (caught instanceof ApiError && caught.status === 401) onSessionExpired(); setError(caught instanceof SyntaxError ? text.invalidJson : text.error) } finally { setLoading(false) } }
  return <section className="organization-page authorization-page" aria-labelledby="simulator-heading" dir={locale === 'ar' ? 'rtl' : 'ltr'}><h1 id="simulator-heading">{text.simulator}</h1><p>{text.intro}</p><form onSubmit={submit}><label htmlFor="simulation-request">{text.requestJson}</label><textarea id="simulation-request" value={request} onChange={(event) => setRequest(event.target.value)} rows={10} dir="ltr" required aria-required="true" />{error && <p role="alert">{error}</p>}<button type="submit" className="primary-button" disabled={loading}>{loading ? text.loading : text.simulate}</button></form>{result && <pre className="access-explanation" aria-live="polite" dir="ltr">{JSON.stringify(result, null, 2)}</pre>}</section>
}

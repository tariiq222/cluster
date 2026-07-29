import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { directionForLocale } from '../../app/copy'
import { useLocale, useToken } from '../../app/session-context'
import { FolderSearch } from 'lucide-react'
import { ApiError, stateFromError } from '../../api'
import { Button, EmptyState, Field, InlineError, Page, PageHeader, Panel, Select, SkeletonList } from '../../ui'
import {
  createRoleAssignment,
  explainAccessDecision,
  listAuthorization,
  listSupervisoryRelationships,
  simulateAccessDecision,
  transitionAuthorizationAdminResource,
  updateAuthorizationAdminResource,
  type AccessDecision,
  type AuthorizationAdminPatch,
  type AuthorizationItem,
  type AuthorizationResource,
  type AuthorizationTransitionAction,
} from '../../api/r1'

const screenCopy = {
  ar: {
    action: 'إجراء',
    readOnlyServerDataPolicy: 'عرض معلوماتي فقط؛ مصدر القرار هو الخادم.',
  },
  en: {
    action: 'Action',
    readOnlyServerDataPolicy: 'Read-only server data; policy decisions remain server-owned.',
  },
} as const


export type Locale = 'ar' | 'en'
export type AdminResource = AuthorizationResource | 'supervisory'
export type AdminState = 'loading' | 'ready' | 'empty' | 'forbidden' | 'not-found' | 'conflict' | 'stale' | 'error'

const labels = {
  ar: {
    roles: 'الأدوار', capabilities: 'الصلاحيات', 'role-assignments': 'إسنادات الأدوار',
    'classification-policies': 'سياسات التصنيف', 'field-access-templates': 'قوالب وصول الحقول', supervisory: 'العلاقات الإشرافية',
    title: 'إدارة التفويض والوصول', intro: 'بيانات مصفاة من خادم التفويض. لا تُتخذ قرارات الصلاحية في المتصفح.', loading: 'جارٍ التحميل…',
    empty: 'لا توجد سجلات متاحة', forbidden: 'لا تملك صلاحية عرض هذه البيانات.', notFound: 'السجل غير موجود أو لم يعد متاحاً.',
    conflict: 'حدث تعارض. حدّث البيانات ثم أعد المحاولة.', stale: 'البيانات قديمة. أعد المحاولة.', error: 'تعذر تحميل البيانات.', retry: 'إعادة المحاولة',
    create: 'إنشاء', update: 'حفظ التعديل', code: 'الرمز', name: 'الاسم', status: 'الحالة', scope: 'النطاق', scopeId: 'معرّف النطاق', reason: 'سبب التغيير (مطلوب)', transitionUnavailable: 'لا يمكن تطبيق انتقال الحالة المحدد من خلال مسار معتمد.',
    subject: 'معرّف المستخدم المستهدف', role: 'معرّف الدور', start: 'بداية السريان', end: 'نهاية السريان', policy: 'وثيقة السياسة (JSON)',
    choose: 'اختر سجلاً', roleMatrix: 'مصفوفة الدور والصلاحية', wizard: 'معالج الإسناد', denies: 'المنع الصريح والسياسات',
    simulator: 'محاكي قرار الوصول', audit: 'عرض التدقيق والشرح', decisionId: 'معرّف القرار', loadExplanation: 'عرض الشرح',
    simulate: 'محاكاة القرار', requestJson: 'طلب المحاكاة المقدم من الخادم (JSON)', invalidJson: 'أدخل JSON صالحاً.', invalid: 'أكمل الحقول المطلوبة.', saved: 'تم الحفظ.',
  },
  en: {
    roles: 'Roles', capabilities: 'Capabilities', 'role-assignments': 'Role assignments',
    'classification-policies': 'Classification policies', 'field-access-templates': 'Field access templates', supervisory: 'Supervisory relationships',
    title: 'Authorization administration', intro: 'Data is filtered by the authorization service. The browser never makes access decisions.', loading: 'Loading…',
    empty: 'No records available', forbidden: 'You do not have permission to view this data.', notFound: 'The record was not found or is no longer available.',
    conflict: 'A conflict occurred. Refresh and try again.', stale: 'The data is stale. Try again.', error: 'We could not load the data.', retry: 'Try again',
    create: 'Create', update: 'Save change', code: 'Code', name: 'Name', status: 'Status', scope: 'Scope', scopeId: 'Scope ID', reason: 'Reason for change (required)', transitionUnavailable: 'The selected status has no approved transition path.',
    subject: 'Subject user ID', role: 'Role ID', start: 'Start time', end: 'End time', policy: 'Policy document (JSON)',
    choose: 'Select a record', roleMatrix: 'Role-capability matrix', wizard: 'Assignment wizard', denies: 'Explicit denies and policies',
    simulator: 'Access decision simulator', audit: 'Audit and explanation view', decisionId: 'Decision ID', loadExplanation: 'Show explanation',
    simulate: 'Simulate decision', requestJson: 'Server-provided simulation request (JSON)', invalidJson: 'Enter valid JSON.', invalid: 'Complete the required fields.', saved: 'Saved.',
  },
} satisfies Record<Locale, Record<string, string>>

type Labels = (typeof labels)[Locale]

export const RESOURCE_LABELS: Record<AdminResource, string> = {
  roles: 'Roles', capabilities: 'Capabilities', 'role-assignments': 'Role assignments',
  delegations: 'Delegations',
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

const GOVERNED_RESOURCES: Record<AuthorizationResource, true> = { 'role-assignments': true }
function isGoverned(resource: AuthorizationResource): boolean {
  return GOVERNED_RESOURCES[resource] === true
}
const STATUS_TRANSITIONS: Partial<Record<NonNullable<AuthorizationItem['status']>, AuthorizationTransitionAction>> = {
  active: 'activate', revoked: 'revoke', expired: 'expire', published: 'publish',
}

export function authorizationTransitionForStatus(resource: AuthorizationResource, currentStatus: string, nextStatus: string): AuthorizationTransitionAction | null {
  if (!isGoverned(resource) || currentStatus === nextStatus) return null
  return STATUS_TRANSITIONS[nextStatus] ?? null
}

export function canMutateAuthorizationResource(resource: AdminResource, capabilities: readonly string[]): boolean {
  if (resource === 'role-assignments') return capabilities.includes('authorization.assignment.manage')
  return false
}


function StatusPanel({ state, text, retry }: { state: AdminState; text: Labels; retry: () => void }) {
  if (state === 'loading') return <SkeletonList label={text.loading} />
  if (state === 'ready') return null
  if (state === 'empty') return <EmptyState icon={<FolderSearch />} title={text.empty} />
  const message = state === 'forbidden' ? text.forbidden : state === 'not-found' ? text.notFound : state === 'conflict' ? text.conflict : state === 'stale' ? text.stale : text.error
  const canRetry = state !== 'forbidden' && state !== 'not-found'
  return <InlineError message={message} retryLabel={canRetry ? text.retry : undefined} onRetry={canRetry ? retry : undefined} />
}

function ItemTable({ items, locale, onSelect }: { items: AuthorizationItem[]; locale: Locale; onSelect?: (item: AuthorizationItem) => void }) {
  const text = labels[locale]
  return <div className="table-scroll"><table className="data-table"><caption className="visually-hidden">{text.title}</caption><thead><tr><th>{text.name}</th><th>{text.code}</th><th>{text.status}</th>{onSelect && <th>{screenCopy[locale].action}</th>}</tr></thead><tbody>{mapAuthorizationRows(items).map((row, index) => {
    const item = items[index]
    return <tr key={row.id}><td>{row.name}</td><td>{row.code}</td><td>{row.status}</td>{onSelect && item && <td><Button variant="secondary" onClick={() => onSelect(item)}>{text.update}</Button></td>}</tr>
  })}</tbody></table></div>
}

const ADMIN_SCOPE_OPTIONS = [
  { value: '', label: '—' },
  { value: 'cluster', label: 'cluster' },
  { value: 'facility', label: 'facility' },
  { value: 'unit', label: 'unit' },
  { value: 'record_set', label: 'record_set' },
]

const ADMIN_STATUS_OPTIONS = [
  { value: 'draft', label: 'draft' },
  { value: 'active', label: 'active' },
  { value: 'inactive', label: 'inactive' },
  { value: 'revoked', label: 'revoked' },
  { value: 'expired', label: 'expired' },
  { value: 'published', label: 'published' },
]

function AdminForm({ resource, locale, token, onSaved }: { resource: 'role-assignments'; locale: Locale; token: string; onSaved: () => Promise<void> }) {
  const text = labels[locale]
  const [error, setError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)
  const [scope, setScope] = useState('')

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setError(null)
    const form = new FormData(event.currentTarget)
    const code = String(form.get('code') ?? '').trim()
    const name = String(form.get('name') ?? '').trim()
    const policy = String(form.get('policy') ?? '')
    if (validateAdminForm({ code, name, policyDocument: policy })) {
      setError(text.invalid)
      return
    }

    const input: Record<string, unknown> = {
      resource_type: resourceCreateType(resource),
      code,
      name: name || undefined,
      scope_type: String(form.get('scope') || '') || undefined,
      scope_id: String(form.get('scopeId') || '') || undefined,
      subject_user_id: String(form.get('subject') || '') || undefined,
      role_id: String(form.get('role') || '') || undefined,
      start_at: String(form.get('start') || '') || undefined,
      end_at: String(form.get('end') || '') || undefined,
      policy_document: parsePolicyDocument(policy),
    }

    setSaving(true)
    try {
      await createRoleAssignment(input, token)
      event.currentTarget.reset()
      setScope('')
      await onSaved()
    } catch (caught) {
      setError(caught instanceof ApiError && (caught.status === 409 || caught.status === 412)
        ? (caught.status === 412 ? text.stale : text.conflict)
        : text.error)
    } finally {
      setSaving(false)
    }
  }

  return (
    <form className="inline-form" onSubmit={submit} noValidate aria-label={text.wizard}>
      <Field id="authorization-code" label={text.code} required>
        <input id="authorization-code" name="code" required aria-required="true" />
      </Field>
      <Field id="authorization-name" label={text.name}>
        <input id="authorization-name" name="name" />
      </Field>
      <Field id="authorization-scope" label={text.scope}>
        <Select id="authorization-scope" name="scope" value={scope} onChange={setScope} options={ADMIN_SCOPE_OPTIONS} />
      </Field>
      <Field id="authorization-scope-id" label={text.scopeId}>
        <input id="authorization-scope-id" name="scopeId" />
      </Field>
      <Field id="authorization-subject" label={text.subject} required>
        <input id="authorization-subject" name="subject" required />
      </Field>
      <Field id="authorization-role" label={text.role} required>
        <input id="authorization-role" name="role" required />
      </Field>
      <Field id="authorization-start" label={text.start}>
        <input id="authorization-start" name="start" type="datetime-local" />
      </Field>
      <Field id="authorization-end" label={text.end}>
        <input id="authorization-end" name="end" type="datetime-local" />
      </Field>
      <Field id="authorization-policy" label={text.policy}>
        <textarea id="authorization-policy" name="policy" rows={3} dir="ltr" />
      </Field>
      {error && <p role="alert">{error}</p>}
      <Button type="submit" disabled={saving}>{saving ? text.loading : text.create}</Button>
    </form>
  )
}

function EditPanel({ resource, item, locale, token, onSaved }: { resource: 'role-assignments'; item: AuthorizationItem; locale: Locale; token: string; onSaved: () => Promise<void> }) {
  const text = labels[locale]
  const [name, setName] = useState(item.name ?? '')
  const [status, setStatus] = useState(item.status ?? 'active')
  const [reason, setReason] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  async function submit(event: FormEvent) {
    event.preventDefault()
    setSaving(true)
    setError(null)
    try {
      const governed = isGoverned(resource)
      const statusChanged = status !== (item.status ?? '')
      const transition = authorizationTransitionForStatus(resource, item.status ?? '', status)
      if (governed && statusChanged && !transition) {
        setError(text.transitionUnavailable)
        return
      }
      if (transition && !reason.trim()) {
        setError(text.invalid)
        return
      }

      let lockVersion = item.lock_version
      const nameChanged = name !== (item.name ?? '')
      if (nameChanged || (!governed && statusChanged)) {
        const patch: AuthorizationAdminPatch = { name }
        if (!governed && statusChanged) patch.status = status as AuthorizationAdminPatch['status']
        const updated = await updateAuthorizationAdminResource(resource, String(item.id), patch, token, lockVersion)
        lockVersion = updated.lock_version ?? lockVersion
      }
      if (transition) {
        await transitionAuthorizationAdminResource(resource, String(item.id), transition, reason, token, lockVersion)
      }
      await onSaved()
    } catch (caught) {
      setError(caught instanceof ApiError && caught.status === 412 ? text.stale : caught instanceof ApiError && caught.status === 409 ? text.conflict : text.error)
    } finally {
      setSaving(false)
    }
  }

  return (
    <form className="inline-form" onSubmit={submit} aria-label={text.update}>
      <Field id="edit-name" label={text.name}>
        <input id="edit-name" value={name} onChange={(event) => setName(event.target.value)} />
      </Field>
      <Field id="edit-status" label={text.status}>
        <Select id="edit-status" value={status} onChange={setStatus} options={ADMIN_STATUS_OPTIONS} />
      </Field>
      {isGoverned(resource) && status !== (item.status ?? '') && <Field id="edit-reason" label={text.reason} required><textarea id="edit-reason" value={reason} onChange={(event) => setReason(event.target.value)} required rows={3} /></Field>}
      {error && <p role="alert">{error}</p>}
      <Button type="submit" disabled={saving}>{saving ? text.loading : text.update}</Button>
    </form>
  )
}

export function RoleCapabilityMatrix({ items, locale }: { items: AuthorizationItem[]; locale: Locale }) {
  const text = labels[locale]
  return <Panel id="role-capability-heading" title={text.roleMatrix} level={2}><p>{screenCopy[locale].readOnlyServerDataPolicy}</p><ItemTable items={items} locale={locale} /></Panel>
}
export function AuthorizationAdmin({ resource, capabilities }: { resource: AdminResource; capabilities: readonly string[] }) {
  const locale = useLocale()
  const token = useToken()
  const [state, setState] = useState<AdminState>('loading')
  const [items, setItems] = useState<AuthorizationItem[]>([])
  const [selected, setSelected] = useState<AuthorizationItem | null>(null)
  if (resource === 'delegations') {
    return (
      <div dir={directionForLocale(locale)} aria-labelledby="authorization-heading">
        <Page className="authorization-page">
          <PageHeader id="authorization-heading" title={labels[locale]['classification-policies']} description={labels[locale].intro} />
          <EmptyState icon={<FolderSearch />} title={labels[locale].empty} />
        </Page>
      </div>
    )
  }
  const load = useCallback(async () => {
    setState('loading')
    try {
      const result = resource === 'supervisory' ? await listSupervisoryRelationships(token) : await listAuthorization(resource, token)
      setItems(result)
      setSelected(null)
      setState(result.length ? 'ready' : 'empty')
    } catch (caught) {
      setState(stateFromError(caught) as AdminState)
    }
  }, [resource, token])
  useEffect(() => { void load() }, [load])
  const canMutate = canMutateAuthorizationResource(resource, capabilities)
  const text = labels[locale]
  const matrix = resource === 'roles' || resource === 'capabilities'
  const terminalState = state === 'loading' || state === 'empty' || state === 'forbidden' || state === 'not-found' || state === 'conflict' || state === 'stale' || state === 'error'
  const screen = (
    <Page className="authorization-page">
      <PageHeader id="authorization-heading" title={text[resource]} description={text.intro} />
      {canMutate && resource === 'role-assignments' ? (
        <Panel id="authorization-wizard-heading" title={text.wizard} level={2}>
          <AdminForm resource="role-assignments" locale={locale} token={token} onSaved={load} />
        </Panel>
      ) : null}
      {terminalState ? (
        <StatusPanel state={state} text={text} retry={() => void load()} />
      ) : matrix ? (
        <RoleCapabilityMatrix items={items} locale={locale} />
      ) : (
        <>
          <ItemTable items={items} locale={locale} onSelect={canMutate ? setSelected : undefined} />
          {selected && canMutate && resource === 'role-assignments' ? (
            <Panel id="authorization-edit-heading" title={text.update} level={2}>
              <EditPanel resource="role-assignments" item={selected} locale={locale} token={token} onSaved={load} />
            </Panel>
          ) : null}
        </>
      )}
    </Page>
  )
  return (
    <div dir={directionForLocale(locale)} aria-labelledby="authorization-heading">
      {screen}
    </div>
  )
}

export function AccessExplanation({ decisionId }: { decisionId?: string }) {
  const locale = useLocale()
  const token = useToken()
  const text = labels[locale]; const [id, setId] = useState(decisionId ?? ''); const [item, setItem] = useState<AccessDecision | null>(null); const [state, setState] = useState<'idle' | 'loading' | 'ready' | 'error'>('idle')
  async function load(event?: FormEvent) { event?.preventDefault(); if (!id.trim()) return setState('error'); setState('loading'); try { setItem(await explainAccessDecision(id.trim(), token)); setState('ready') } catch { setState('error') } }
  return <div dir={directionForLocale(locale)} aria-labelledby="explanation-heading"><Page className="authorization-page"><PageHeader id="explanation-heading" title={text.audit} description={text.intro} /><form className="inline-form" onSubmit={load}><Field id="decision-id" label={text.decisionId}><input id="decision-id" value={id} onChange={(event) => setId(event.target.value)} dir="ltr" /></Field><Button type="submit" disabled={state === 'loading'}>{state === 'loading' ? text.loading : text.loadExplanation}</Button></form>{state === 'error' && <InlineError message={id.trim() ? text.error : text.invalid} />}{state === 'ready' && item && <pre className="access-explanation" aria-live="polite" dir="ltr">{JSON.stringify(item, null, 2)}</pre>}</Page></div>;
}

export function AccessDecisionSimulator() {
  const locale = useLocale()
  const token = useToken()
  const text = labels[locale]; const [request, setRequest] = useState(''); const [result, setResult] = useState<AccessDecision | null>(null); const [error, setError] = useState<string | null>(null); const [loading, setLoading] = useState(false)
  async function submit(event: FormEvent) { event.preventDefault(); setError(null); try { const parsed = JSON.parse(request) as Parameters<typeof simulateAccessDecision>[0]; setLoading(true); setResult(await simulateAccessDecision(parsed, token)) } catch (caught) { setError(caught instanceof SyntaxError ? text.invalidJson : text.error) } finally { setLoading(false) } }
  return <div dir={directionForLocale(locale)} aria-labelledby="simulator-heading"><Page className="authorization-page"><PageHeader id="simulator-heading" title={text.simulator} description={text.intro} /><form className="resource-form" onSubmit={submit}><Field id="simulation-request" label={text.requestJson} required><textarea id="simulation-request" value={request} onChange={(event) => setRequest(event.target.value)} rows={10} dir="ltr" required aria-required="true" /></Field>{error && <p className="error-summary" role="alert">{error}</p>}<Button type="submit" disabled={loading}>{loading ? text.loading : text.simulate}</Button></form>{result && <pre className="access-explanation" aria-live="polite" dir="ltr">{JSON.stringify(result, null, 2)}</pre>}</Page></div>;
}

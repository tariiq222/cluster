import { useEffect, useState } from 'react'
import { ApiError } from '../../api'
import { explainAccessDecision, listAuthorization, listSupervisoryRelationships, type AuthorizationItem, type AuthorizationResource } from '../../api/w1-3/authorization'

type Locale = 'ar' | 'en'
const labels = {
  ar: { roles: 'الأدوار', capabilities: 'الصلاحيات', 'role-assignments': 'إسنادات الأدوار', delegations: 'التفويضات', supervisory: 'العلاقات الإشرافية', title: 'إدارة التفويض والوصول', intro: 'بيانات مصفاة من خادم التفويض. لا تُتخذ قرارات الصلاحية في المتصفح.', loading: 'جارٍ التحميل…', empty: 'لا توجد سجلات متاحة', forbidden: 'لا تملك صلاحية عرض هذه البيانات.', notFound: 'السجل غير موجود أو لم يعد متاحاً.', conflict: 'حدث تعارض. حدّث البيانات ثم أعد المحاولة.', stale: 'البيانات قديمة. أعد المحاولة.', error: 'تعذر تحميل البيانات.', retry: 'إعادة المحاولة', access: 'شرح قرار الوصول', decisionId: 'معرّف القرار', loadExplanation: 'عرض الشرح', invalidDecision: 'أدخل معرّف قرار صحيحاً.' },
  en: { roles: 'Roles', capabilities: 'Capabilities', 'role-assignments': 'Role assignments', delegations: 'Delegations', supervisory: 'Supervisory relationships', title: 'Authorization administration', intro: 'Data is filtered by the authorization service. The browser never makes access decisions.', loading: 'Loading…', empty: 'No records available', forbidden: 'You do not have permission to view this data.', notFound: 'The record was not found or is no longer available.', conflict: 'A conflict occurred. Refresh and try again.', stale: 'The data is stale. Try again.', error: 'We could not load the data.', retry: 'Try again', access: 'Access decision explanation', decisionId: 'Decision ID', loadExplanation: 'Show explanation', invalidDecision: 'Enter a valid decision ID.' },
} as const

function ItemTable({ items, locale }: { items: AuthorizationItem[]; locale: Locale }) {
  const text = labels[locale]
  return <div className="table-scroll"><table className="data-table"><thead><tr><th>{locale === 'ar' ? 'الاسم' : 'Name'}</th><th>{locale === 'ar' ? 'الرمز' : 'Code'}</th><th>{locale === 'ar' ? 'الحالة' : 'Status'}</th></tr></thead><tbody>{items.map((item, index) => <tr key={item.id ?? `${item.code ?? 'item'}-${index}`}><td>{typeof item.name === 'string' ? item.name : typeof item.id === 'string' ? item.id : text.empty}</td><td>{typeof item.code === 'string' ? item.code : '—'}</td><td>{typeof item.status === 'string' ? item.status : '—'}</td></tr>)}</tbody></table></div>
}

export function AuthorizationAdmin({ locale, token, resource, onSessionExpired }: { locale: Locale; token: string; resource: AuthorizationResource | 'supervisory'; onSessionExpired: () => void }) {
  const text = labels[locale]
  const [items, setItems] = useState<AuthorizationItem[]>([])
  const [state, setState] = useState<'loading' | 'ready' | 'empty' | 'forbidden' | 'not-found' | 'conflict' | 'stale' | 'error'>('loading')

  async function load() {
    setState('loading')
    try {
      const result = resource === 'supervisory' ? await listSupervisoryRelationships(token) : await listAuthorization(resource, token)
      setItems(result)
      setState(result.length ? 'ready' : 'empty')
    } catch (error) {
      if (error instanceof ApiError && error.status === 401) return onSessionExpired()
      if (error instanceof ApiError && error.status === 403) setState('forbidden')
      else if (error instanceof ApiError && error.status === 404) setState('not-found')
      else if (error instanceof ApiError && error.status === 409) setState('conflict')
      else if (error instanceof ApiError && error.status === 412) setState('stale')
      else setState('error')
    }
  }
  useEffect(() => { void load() }, [token, resource])
  const title = resource === 'supervisory' ? text.supervisory : text[resource]
  return <section className="organization-page authorization-page" aria-labelledby="authorization-heading"><div className="page-heading page-heading-copy"><div><h1 id="authorization-heading">{title}</h1><p>{text.intro}</p></div></div>{state === 'loading' && <div className="skeleton-list" aria-label={text.loading}>{[0, 1, 2].map((item) => <div className="skeleton-row" aria-hidden="true" key={item} />)}</div>}{state !== 'loading' && state !== 'ready' && state !== 'empty' && <div className="state-panel" role="alert"><p>{state === 'forbidden' ? text.forbidden : state === 'not-found' ? text.notFound : state === 'conflict' ? text.conflict : state === 'stale' ? text.stale : text.error}</p>{state !== 'forbidden' && state !== 'not-found' && <button type="button" className="secondary-button" onClick={() => void load()}>{text.retry}</button>}</div>}{state === 'empty' && <div className="state-panel"><p>{text.empty}</p></div>}{state === 'ready' && <ItemTable items={items} locale={locale} />}</section>
}

export function AccessExplanation({ locale, token, decisionId, onSessionExpired }: { locale: Locale; token: string; decisionId?: string; onSessionExpired: () => void }) {
  const text = labels[locale]; const [id, setId] = useState(decisionId ?? ''); const [item, setItem] = useState<AuthorizationItem | null>(null); const [state, setState] = useState<'idle' | 'loading' | 'ready' | 'error'>('idle')
  async function load(event?: React.FormEvent) { event?.preventDefault(); if (!id.trim()) return setState('error'); setState('loading'); try { setItem(await explainAccessDecision(id.trim(), token)); setState('ready') } catch (error) { if (error instanceof ApiError && error.status === 401) return onSessionExpired(); setState('error') } }
  return <section className="organization-page authorization-page" aria-labelledby="explanation-heading"><div className="page-heading page-heading-copy"><div><h1 id="explanation-heading">{text.access}</h1><p>{text.intro}</p></div></div><form className="inline-form" onSubmit={load}><label htmlFor="decision-id">{text.decisionId}</label><input id="decision-id" value={id} onChange={(event) => setId(event.target.value)} placeholder="uuidv7"/><button type="submit" className="primary-button" disabled={state === 'loading'}>{state === 'loading' ? text.loading : text.loadExplanation}</button></form>{state === 'error' && <div className="state-panel" role="alert"><p>{id.trim() ? text.error : text.invalidDecision}</p></div>}{state === 'ready' && item && <pre className="access-explanation" aria-live="polite">{JSON.stringify(item, null, 2)}</pre>}</section>
}

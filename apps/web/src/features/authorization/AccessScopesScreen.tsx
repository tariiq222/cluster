// @vitest-environment jsdom
import { useCallback, useEffect, useRef, useState } from 'react'
import { Network } from 'lucide-react'
import type { Locale } from '../../app/copy'
import { directionForLocale } from '../../app/copy'
import { useToken } from '../../app/session-context'
import { ApiError } from '../../api'
import { Button, EmptyState, InlineError, Page, PageHeader, Panel, SkeletonList } from '../../ui'
import { listAuthorization, type AuthorizationItem } from '../../api/r1'

const copy = {
  ar: {
    title: 'نطاقات الوصول',
    subtitle: 'قراءة مستقلة لإسنادات الأدوار وفترة سريانها دون إنشاء إدارة جديدة.',
    loading: 'جارٍ تحميل نطاقات الوصول…',
    error: 'تعذر تحميل نطاقات الوصول.',
    retry: 'إعادة المحاولة',
    empty: 'لا توجد إسنادات أدوار مع نطاقات ضمن هذه البيئة.',
    user: 'المستخدم',
    role: 'الدور',
    scope: 'النطاق',
    window: 'الفترة',
    openAssignments: 'فتح إسنادات الأدوار',
  },
  en: {
    title: 'Access scopes',
    subtitle: 'Read-only view of role assignments and their validity windows.',
    loading: 'Loading access scopes…',
    error: 'We could not load the access scopes.',
    retry: 'Try again',
    empty: 'No role assignments with scopes are available in this environment.',
    user: 'User',
    role: 'Role',
    scope: 'Scope',
    window: 'Window',
    openAssignments: 'Open role assignments',
  },
} as const satisfies Record<Locale, Record<string, string>>

function field(item: AuthorizationItem, key: string): string {
  const value = (item as unknown as Record<string, unknown>)[key]
  return typeof value === 'string' && value.trim() !== '' ? value : '—'
}

export function AccessScopesScreen({ locale, scopeReady, scopeEpoch }: { locale: Locale; scopeReady: boolean; scopeEpoch: number }) {
  const t = copy[locale]
  const token = useToken()
  const [items, setItems] = useState<AuthorizationItem[]>([])
  const [state, setState] = useState<'loading' | 'ready' | 'denied' | 'error'>('loading')
  const requestRef = useRef(0)

  const load = useCallback(async () => {
    const request = ++requestRef.current
    if (!scopeReady) {
      setItems([])
      setState('loading')
      return
    }
    setState('loading')
    try {
      const result = await listAuthorization('role-assignments', token)
      if (request !== requestRef.current) return
      setItems(result)
      setState('ready')
    } catch (error) {
      if (request !== requestRef.current) return
      setItems([])
      setState(error instanceof ApiError && error.status === 403 ? 'denied' : 'error')
    }
  }, [scopeReady, token])

  useEffect(() => {
    void load()
  }, [load, scopeEpoch])

  return (
    <div dir={directionForLocale(locale)}>
      <Page aria-labelledby="access-scopes-heading">
        <PageHeader
          id="access-scopes-heading"
          title={t.title}
          description={t.subtitle}
          actions={<Button variant="secondary" onClick={() => { window.location.href = '/admin/authorization/role-assignments' }}>{t.openAssignments}</Button>}
        />
        {state === 'loading' ? <SkeletonList label={t.loading} /> : null}
        {state === 'denied' ? <Panel id="access-scopes-denied" title="403" level={2}><p>{t.error}</p></Panel> : null}
        {state === 'error' ? <InlineError message={t.error} retryLabel={t.retry} onRetry={() => void load()} /> : null}
        {state === 'ready' && items.length === 0 ? <EmptyState icon={<Network aria-hidden="true" />} title={t.empty} /> : null}
        {state === 'ready' && items.length > 0 ? (
          <Panel id="access-scopes-list-panel" title={t.title} level={2}>
            <div className="table-scroll">
              <table className="data-table">
                <caption className="visually-hidden">{t.title}</caption>
                <thead>
                  <tr>
                    <th>{t.user}</th>
                    <th>{t.role}</th>
                    <th>{t.scope}</th>
                    <th>{t.window}</th>
                  </tr>
                </thead>
                <tbody>
                  {items.map((item, index) => (
                    <tr key={typeof item.id === 'string' ? item.id : `row-${index}`}>
                      <td dir="ltr">{field(item, 'subject_id')}</td>
                      <td dir="ltr">{field(item, 'role_code')}</td>
                      <td dir="ltr">{field(item, 'scope_type')}:{field(item, 'scope_id')}</td>
                      <td dir="ltr">{field(item, 'starts_at')} → {field(item, 'ends_at')}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </Panel>
        ) : null}
      </Page>
    </div>
  )
}

// @vitest-environment jsdom
import { useCallback, useEffect, useState } from 'react'
import { ShieldCheck } from 'lucide-react'
import type { Locale } from '../../app/copy'
import { directionForLocale } from '../../app/copy'
import { WorkspaceTabs } from '../../app/WorkspaceTabs'
import { useToken } from '../../app/session-context'
import { ApiError } from '../../api'
import { EmptyState, InlineError, Page, PageHeader, Panel, SkeletonList } from '../../ui'
import { listAuthorization, type AuthorizationItem } from '../../api/r1'

const copy = {
  ar: {
    title: 'الأدوار والصلاحيات',
    subtitle: 'تصفح مصفوفة الأدوار والقدرات دون التنقل في تبويبات إضافية.',
    loading: 'جارٍ تحميل الأدوار…',
    error: 'تعذر تحميل الأدوار.',
    retry: 'إعادة المحاولة',
    capabilitiesTitle: 'الصلاحيات',
    empty: 'لا توجد أدوار متاحة.',
  },
  en: {
    title: 'Roles and capabilities',
    subtitle: 'Browse the role and capability matrix without extra tabs.',
    loading: 'Loading roles…',
    error: 'We could not load the roles.',
    retry: 'Try again',
    capabilitiesTitle: 'Capabilities',
    empty: 'No roles are available.',
  },
} as const satisfies Record<Locale, Record<string, string>>

export function RolesCapabilitiesWorkspace({ locale, activeResource, navigate, capabilities }: { locale: Locale; activeResource: 'roles' | 'capabilities'; navigate: (path: string) => void; capabilities: readonly string[] | null }) {
  const t = copy[locale]
  const token = useToken()
  const visibleResources = (['roles', 'capabilities'] as const).filter((resource) =>
    capabilities?.includes(resource === 'roles' ? 'authorization.role.read' : 'authorization.capability.read'),
  )
  const visibleResource = visibleResources.includes(activeResource) ? activeResource : visibleResources[0] ?? activeResource
  const [items, setItems] = useState<AuthorizationItem[]>([])
  const [state, setState] = useState<'loading' | 'ready' | 'denied' | 'error'>('loading')

  const load = useCallback(async () => {
    setState('loading')
    try {
      const result = await listAuthorization(visibleResource, token)
      setItems(result)
      setState('ready')
    } catch (error) {
      setItems([])
      setState(error instanceof ApiError && error.status === 403 ? 'denied' : 'error')
    }
  }, [token, visibleResource])

  useEffect(() => {
    void load()
  }, [load])

  return (
    <div dir={directionForLocale(locale)}>
      <Page aria-labelledby="roles-capabilities-heading">
        <PageHeader id="roles-capabilities-heading" title={t.title} description={t.subtitle} />
        <WorkspaceTabs
          label={t.title}
          onNavigate={navigate}
          tabs={visibleResources.map((resource) => ({
            key: resource,
            label: resource === 'roles' ? (t.title.split(' ')[0] ?? t.title) : t.capabilitiesTitle,
            path: resource === 'roles' ? '/admin/authorization/roles' : '/admin/authorization/capabilities',
            active: resource === visibleResource,
          }))}
        />
        {state === 'loading' ? <SkeletonList label={t.loading} /> : null}
        {state === 'denied' ? <Panel id="roles-capabilities-denied" title="403" level={2}><p>{t.error}</p></Panel> : null}
        {state === 'error' ? <InlineError message={t.error} retryLabel={t.retry} onRetry={() => void load()} /> : null}
        {state === 'ready' && items.length === 0 ? <EmptyState icon={<ShieldCheck aria-hidden="true" />} title={t.empty} /> : null}
        {state === 'ready' && items.length > 0 ? (
          <Panel id="roles-capabilities-list-panel" title={visibleResource === 'roles' ? t.title : t.capabilitiesTitle} level={2}>
            <ul className="data-list">
              {items.map((item, index) => (
                <li key={typeof item.id === 'string' ? item.id : `item-${index}`}>
                  <span>{item.name ?? item.code ?? String(item.id ?? index)}</span>
                  {item.code ? <code dir="ltr">{item.code}</code> : null}
                </li>
              ))}
            </ul>
          </Panel>
        ) : null}
      </Page>
    </div>
  )
}

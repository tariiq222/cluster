// @vitest-environment jsdom
import { useCallback, useEffect, useState } from 'react'
import { ShieldCheck } from 'lucide-react'
import type { Locale } from '../../app/copy'
import { directionForLocale } from '../../app/copy'
import { useToken } from '../../app/session-context'
import { ApiError } from '../../api'
import { EmptyState, InlineError, Page, PageHeader, Panel, SkeletonList } from '../../ui'
import { listAuthorization, type AuthorizationItem } from '../../api/r1'

const copy = {
  ar: {
    title: 'الأدوار والصلاحيات',
    subtitle: 'تصفح مصفوفة الأدوار والقدرات دون التنقل في تبويبات إضافية.',
    loadingRoles: 'جارٍ تحميل الأدوار…',
    loadingCapabilities: 'جارٍ تحميل الصلاحيات…',
    errorRoles: 'تعذر تحميل الأدوار.',
    errorCapabilities: 'تعذر تحميل الصلاحيات.',
    retry: 'إعادة المحاولة',
    capabilitiesTitle: 'الصلاحيات',
    rolesTitle: 'الأدوار',
    empty: 'لا توجد أدوار متاحة.',
  },
  en: {
    title: 'Roles and capabilities',
    subtitle: 'Browse the role and capability matrix without extra tabs.',
    loadingRoles: 'Loading roles…',
    loadingCapabilities: 'Loading capabilities…',
    errorRoles: 'We could not load the roles.',
    errorCapabilities: 'We could not load the capabilities.',
    retry: 'Try again',
    capabilitiesTitle: 'Capabilities',
    rolesTitle: 'Roles',
    empty: 'No roles are available.',
  },
} as const satisfies Record<Locale, Record<string, string>>

type VisibleResource = 'roles' | 'capabilities'
type PanelState = 'loading' | 'ready' | 'denied' | 'error'

export function RolesCapabilitiesWorkspace({ locale, capabilities }: { locale: Locale; capabilities: readonly string[] | null }) {
  const t = copy[locale]
  const token = useToken()
  const visibleResources: VisibleResource[] = (['roles', 'capabilities'] as const).filter((resource) =>
    capabilities?.includes(resource === 'roles' ? 'authorization.role.read' : 'authorization.capability.read'),
  )
  return (
    <div dir={directionForLocale(locale)}>
      <Page aria-labelledby="roles-capabilities-heading">
        <PageHeader id="roles-capabilities-heading" title={t.title} description={t.subtitle} />
        {visibleResources.length === 0 ? (
          <EmptyState icon={<ShieldCheck aria-hidden="true" />} title={t.empty} />
        ) : (
          visibleResources.map((resource) => (
            <ResourcePanel key={resource} resource={resource} locale={locale} token={token} />
          ))
        )}
      </Page>
    </div>
  )
}

function ResourcePanel({ resource, locale, token }: { resource: VisibleResource; locale: Locale; token: string }) {
  const t = copy[locale]
  const [items, setItems] = useState<AuthorizationItem[]>([])
  const [state, setState] = useState<PanelState>('loading')
  const load = useCallback(async () => {
    setState('loading')
    try {
      const result = await listAuthorization(resource, token)
      setItems(result)
      setState('ready')
    } catch (error) {
      setItems([])
      setState(error instanceof ApiError && error.status === 403 ? 'denied' : 'error')
    }
  }, [resource, token])
  useEffect(() => {
    void load()
  }, [load])
  const title = resource === 'roles' ? t.rolesTitle : t.capabilitiesTitle
  const loadingLabel = resource === 'roles' ? t.loadingRoles : t.loadingCapabilities
  const errorMessage = resource === 'roles' ? t.errorRoles : t.errorCapabilities
  return (
    <Panel id={`roles-capabilities-${resource}-panel`} title={title} level={2}>
      {state === 'loading' ? <SkeletonList label={loadingLabel} /> : null}
      {state === 'denied' ? <p>{errorMessage}</p> : null}
      {state === 'error' ? <InlineError message={errorMessage} retryLabel={t.retry} onRetry={() => void load()} /> : null}
      {state === 'ready' && items.length === 0 ? <EmptyState icon={<ShieldCheck aria-hidden="true" />} title={t.empty} /> : null}
      {state === 'ready' && items.length > 0 ? (
        <ul className="data-list">
          {items.map((item, index) => (
            <li key={typeof item.id === 'string' ? item.id : `item-${index}`}>
              <span>{item.name ?? item.code ?? String(item.id ?? index)}</span>
              {item.code ? <code dir="ltr">{item.code}</code> : null}
            </li>
          ))}
        </ul>
      ) : null}
    </Panel>
  )
}

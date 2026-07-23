// @vitest-environment jsdom
import { useCallback, useEffect, useRef, useState } from 'react'
import { LayoutDashboard } from 'lucide-react'
import type { Locale } from '../../app/copy'
import { directionForLocale } from '../../app/copy'
import { useToken } from '../../app/session-context'
import { ApiError } from '../../api'
import { EmptyState, InlineError, Page, PageHeader, Panel, SkeletonList, StatusBadge } from '../../ui'
import { getDashboard, listDashboards, type R1Collection, type R1Entity } from '../../api/r1'

const copy = {
  ar: {
    title: 'لوحات المؤشرات',
    subtitle: 'اللوحات المنشورة والمصرح بها ضمن نطاقك.',
    loading: 'جارٍ تحميل اللوحات…',
    error: 'تعذر تحميل اللوحات. أعد المحاولة.',
    denied: 'لا تملك صلاحية عرض هذه اللوحة.',
    retry: 'إعادة المحاولة',
    empty: 'لا توجد لوحات منشورة ضمن نطاقك.',
    detailTitle: 'تفاصيل اللوحة',
    rows: 'عدد الصفوف',
    source: 'المصدر',
  },
  en: {
    title: 'Dashboards',
    subtitle: 'Published dashboards authorized within your scope.',
    loading: 'Loading dashboards…',
    error: 'We could not load the dashboards. Try again.',
    denied: 'You do not have access to this dashboard.',
    retry: 'Try again',
    empty: 'No published dashboards are available in your scope.',
    detailTitle: 'Dashboard details',
    rows: 'Row count',
    source: 'Source',
  },
} as const satisfies Record<Locale, Record<string, string>>

function cardTitle(definition: R1Entity, fallback: string): string {
  const title = definition.title ?? definition.name ?? definition.code
  return typeof title === 'string' && title.trim() !== '' ? title : fallback
}

function cardTotal(content: R1Collection): number {
  return typeof content.total === 'number' ? content.total : (content.items?.length ?? 0)
}

export function DashboardsScreen({ locale, dashboardId, scopeId, revision = 0 }: { locale: Locale; dashboardId?: string; scopeId?: string | null; revision?: number }) {
  const t = copy[locale]
  const token = useToken()
  const [items, setItems] = useState<R1Entity[]>([])
  const [state, setState] = useState<'loading' | 'ready' | 'denied' | 'error'>('loading')
  const [detail, setDetail] = useState<R1Collection | null>(null)
  const [detailState, setDetailState] = useState<'idle' | 'loading' | 'ready' | 'denied' | 'error'>('idle')
  const requestRevision = useRef(0)

  const load = useCallback(async () => {
    const request = ++requestRevision.current
    setState('loading')
    setItems([])
    setDetail(null)
    setDetailState(dashboardId ? 'loading' : 'idle')
    try {
      const list = await listDashboards(token)
      if (request !== requestRevision.current) return
      setItems(list.items ?? [])
      setState('ready')
      if (dashboardId) {
        try {
          const content = await getDashboard(token, dashboardId, scopeId ?? undefined)
          if (request !== requestRevision.current) return
          setDetail(content)
          setDetailState('ready')
        } catch (error) {
          if (request !== requestRevision.current) return
          setDetail(null)
          setDetailState(error instanceof ApiError && error.status === 403 ? 'denied' : 'error')
        }
      }
    } catch (error) {
      if (request !== requestRevision.current) return
      setItems([])
      setState(error instanceof ApiError && error.status === 403 ? 'denied' : 'error')
      setDetailState('idle')
    }
  }, [token, dashboardId, scopeId])

  useEffect(() => {
    void load()
  }, [load, revision])

  return (
    <div dir={directionForLocale(locale)}>
      <Page aria-labelledby="dashboards-heading">
        <PageHeader id="dashboards-heading" title={t.title} description={t.subtitle} />
        {state === 'loading' ? <SkeletonList label={t.loading} /> : null}
        {state === 'denied' ? <Panel id="dashboards-denied" title="403" level={2}><p>{t.error}</p></Panel> : null}
        {state === 'error' ? <InlineError message={t.error} retryLabel={t.retry} onRetry={() => void load()} /> : null}
        {state === 'ready' && items.length === 0 ? <EmptyState icon={<LayoutDashboard aria-hidden="true" />} title={t.empty} /> : null}
        {state === 'ready' && items.length > 0 ? (
          <Panel id="dashboards-list-panel" title={t.title} level={2}>
            <ul className="data-list">
              {items.map((item) => (
                <li key={String(item.id)}>
                  <a href={`/dashboards/${String(item.id)}`}>{cardTitle(item, String(item.id))}</a>
                  <StatusBadge>{String(item.state ?? 'ready')}</StatusBadge>
                </li>
              ))}
            </ul>
          </Panel>
        ) : null}
        {detailState === 'loading' ? <SkeletonList label={t.loading} /> : null}
        {detailState === 'denied' ? <Panel id="dashboard-detail-denied" title={t.denied} level={2}><p>{t.denied}</p></Panel> : null}
        {detailState === 'error' ? <InlineError message={t.error} retryLabel={t.retry} onRetry={() => void load()} /> : null}
        {detailState === 'ready' && detail ? (
          <Panel id="dashboards-detail-panel" title={t.detailTitle} level={2}>
            <dl className="record-summary">
              <div><dt>{t.rows}</dt><dd dir="ltr">{cardTotal(detail)}</dd></div>
            </dl>
          </Panel>
        ) : null}
      </Page>
    </div>
  )
}

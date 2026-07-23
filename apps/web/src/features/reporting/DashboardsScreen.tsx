// @vitest-environment jsdom
import { useCallback, useEffect, useRef, useState } from 'react'
import { LayoutDashboard } from 'lucide-react'
import type { Locale } from '../../app/copy'
import { directionForLocale } from '../../app/copy'
import { useToken } from '../../app/session-context'
import { ApiError } from '../../api'
import { DataFreshness, EmptyState, InlineError, MetricTile, Page, PageHeader, Panel, SkeletonList, StatusBadge, type StatusBadgeVariant } from '../../ui'
import { DashboardChart, type DashboardChartSummaryRow } from '../../charts/DashboardChart'
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
    notFound: 'لم نعثر على هذه اللوحة.',
    notFoundBody: 'قد تكون حُذفت أو نُقلت. ارجع إلى قائمة اللوحات أو حدّث الصفحة.',
    detailTitle: 'تفاصيل اللوحة',
    rows: 'عدد الصفوف',
    source: 'المصدر',
    period: 'الفترة',
    lastUpdated: 'آخر تحديث',
    itemsInScope: 'العناصر ضمن النطاق',
    updatedAt: 'وقت التحديث',
    status: 'الحالة',
  },
  en: {
    title: 'Dashboards',
    subtitle: 'Published dashboards authorized within your scope.',
    loading: 'Loading dashboards…',
    error: 'We could not load the dashboards. Try again.',
    denied: 'You do not have access to this dashboard.',
    retry: 'Try again',
    empty: 'No published dashboards are available in your scope.',
    notFound: 'We could not find this dashboard.',
    notFoundBody: 'It may have been removed or moved. Return to the dashboards list or refresh.',
    detailTitle: 'Dashboard details',
    rows: 'Row count',
    source: 'Source',
    period: 'Period',
    lastUpdated: 'Last updated',
    itemsInScope: 'Items in scope',
    updatedAt: 'Updated at',
    status: 'Status',
  },
} as const satisfies Record<Locale, Record<string, string>>

const STATUS_BADGE_MAP: Record<string, StatusBadgeVariant> = {
  ready: 'success',
  published: 'success',
  active: 'success',
  approved: 'success',
  draft: 'info',
  pending: 'warning',
  waiting: 'warning',
  in_review: 'warning',
  stale: 'warning',
  archived: 'neutral',
  failed: 'danger',
  error: 'danger',
  cancelled: 'danger',
  removed: 'danger',
}

function statusBadgeVariant(value: string | undefined): StatusBadgeVariant {
  if (!value) return 'neutral'
  return STATUS_BADGE_MAP[value.toLowerCase()] ?? 'neutral'
}

function cardTitle(definition: R1Entity, fallback: string): string {
  const title = definition.title ?? definition.name ?? definition.code
  return typeof title === 'string' && title.trim() !== '' ? title : fallback
}

function cardTotal(content: R1Collection): number {
  return typeof content.total === 'number' ? content.total : (content.items?.length ?? 0)
}

function pickString(value: unknown): string | undefined {
  return typeof value === 'string' && value.trim() ? value : undefined
}

function buildDashboardSummary(
  t: (typeof copy)[Locale],
  status: string | undefined,
  period: string | undefined,
  source: string | undefined,
): DashboardChartSummaryRow[] {
  return [
    { label: t.status, value: status ?? '—' },
    ...(period ? [{ label: t.period, value: period }] : []),
    ...(source ? [{ label: t.source, value: source }] : []),
  ]
}

export function DashboardsScreen({ locale, dashboardId, scopeId, revision = 0 }: { locale: Locale; dashboardId?: string; scopeId?: string | null; revision?: number }) {
  const t = copy[locale]
  const token = useToken()
  const [items, setItems] = useState<R1Entity[]>([])
  const [state, setState] = useState<'loading' | 'ready' | 'denied' | 'error'>('loading')
  const [detail, setDetail] = useState<R1Collection | null>(null)
  const [detailState, setDetailState] = useState<'idle' | 'loading' | 'ready' | 'denied' | 'not-found' | 'error'>('idle')
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
          if (error instanceof ApiError && error.status === 403) setDetailState('denied')
          else if (error instanceof ApiError && error.status === 404) setDetailState('not-found')
          else setDetailState('error')
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

  const detailAsEntity = detail as R1Entity | undefined
  const detailStatus = pickString(detailAsEntity?.status) ?? pickString(detailAsEntity?.state)
  const detailPeriod = pickString(detailAsEntity?.period)
  const detailSource = pickString(detailAsEntity?.source) ?? pickString(detailAsEntity?.source_label)
  const detailUpdatedAt = pickString(detailAsEntity?.updated_at) ?? pickString(detailAsEntity?.updatedAt)

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
              {items.map((item) => {
                const status = pickString(item.status) ?? pickString(item.state)
                return (
                  <li key={String(item.id)}>
                    <a href={`/dashboards/${String(item.id)}`}>{cardTitle(item, String(item.id))}</a>
                    <StatusBadge variant={statusBadgeVariant(status)}>{status ?? 'ready'}</StatusBadge>
                  </li>
                )
              })}
            </ul>
          </Panel>
        ) : null}
        {detailState === 'loading' ? <SkeletonList label={t.loading} /> : null}
        {detailState === 'denied' ? <Panel id="dashboard-detail-denied" title={t.denied} level={2}><p>{t.denied}</p></Panel> : null}
        {detailState === 'not-found' ? (
          <Panel id="dashboard-detail-not-found" title={t.notFound} level={2}>
            <p>{t.notFoundBody}</p>
          </Panel>
        ) : null}
        {detailState === 'error' ? <InlineError message={t.error} retryLabel={t.retry} onRetry={() => void load()} /> : null}
        {detailState === 'ready' && detail ? (
          <Panel id="dashboards-detail-panel" title={t.detailTitle} level={2}>
            <div className="dashboard-kpi-grid" role="group" aria-label={t.detailTitle}>
              <MetricTile
                label={t.itemsInScope}
                value={cardTotal(detail)}
                variant={cardTotal(detail) === 0 ? 'empty' : 'ready'}
              />
              <MetricTile
                label={t.status}
                value={detailStatus ?? '—'}
              />
              {detailPeriod ? <MetricTile label={t.period} value={detailPeriod} /> : null}
              {detailSource ? <MetricTile label={t.source} value={detailSource} /> : null}
            </div>
            <DashboardChart
              caption={t.detailTitle}
              height={180}
              option={{
                grid: { left: 16, right: 16, top: 16, bottom: 24, containLabel: true },
                tooltip: { trigger: 'item' },
                xAxis: {
                  type: 'category',
                  data: [t.itemsInScope, t.status, t.period, t.source],
                },
                yAxis: { type: 'value' },
                series: [
                  {
                    type: 'bar',
                    data: [
                      { value: cardTotal(detail), itemStyle: { color: '#2563eb' } },
                      { value: detailStatus ? 1 : 0, itemStyle: { color: '#16a34a' } },
                      { value: detailPeriod ? 1 : 0, itemStyle: { color: '#f59e0b' } },
                      { value: detailSource ? 1 : 0, itemStyle: { color: '#7c3aed' } },
                    ],
                  },
                ],
              }}
              tabularSummary={buildDashboardSummary(t, detailStatus, detailPeriod, detailSource)}
            />
            <DataFreshness
              updatedAt={detailUpdatedAt ?? t.lastUpdated}
              period={detailPeriod}
              state={detailUpdatedAt ? 'fresh' : 'unknown'}
            />
          </Panel>
        ) : null}
      </Page>
    </div>
  )
}

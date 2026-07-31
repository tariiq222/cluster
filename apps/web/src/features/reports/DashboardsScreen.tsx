import { useCallback, useEffect, useRef, useState } from 'react'
import * as generated from '../../api/generated/cluster'
import { getListDashboardsUrl } from '../../api/generated/cluster'
import type { CollectionResponse, DomainResource, Entity } from '../../api/generated/cluster'
import { ApiError, requestInit, unwrap } from '../../api/http'
import { useApiQuery } from '../../api/query'
import { usePrincipal } from '../../app/principal-context'
import { useLocale, useSessionToken } from '../../app/session-context'
import { formatDate, formatNumber, statusLabel } from '../../i18n'
import { EmptyState, Field, InlineError, Page, PageHeader, Panel, PanelGrid, Select, SkeletonList, StatusBadge } from '../../ui'

const copy = {
  ar: {
    title: 'لوحات المؤشرات',
    description: 'اللوحات المنشورة والمصرح بها ضمن نطاقك.',
    loading: 'جارٍ تحميل لوحات المؤشرات…',
    failed: 'تعذر تحميل لوحات المؤشرات.',
    empty: 'لا توجد لوحات منشورة ضمن نطاقك.',
    selectDashboard: 'اختر لوحة',
    dashboardDetail: 'تفاصيل اللوحة',
    values: 'القيم',
    status: 'الحالة',
    classification: 'التصنيف',
    version: 'الإصدار',
    updated: 'آخر تحديث',
    denied: 'غير مصرح لك بالوصول إلى هذه اللوحة.',
    notFound: 'لم نعثر على هذه اللوحة.',
    retry: 'إعادة المحاولة',
    notAvailable: 'غير متاح',
  },
  en: {
    title: 'Dashboards',
    description: 'Published dashboards authorized within your scope.',
    loading: 'Loading dashboards…',
    failed: 'Dashboards could not be loaded.',
    empty: 'No published dashboards are available in your scope.',
    selectDashboard: 'Select a dashboard',
    dashboardDetail: 'Dashboard details',
    values: 'Values',
    status: 'Status',
    classification: 'Classification',
    version: 'Version',
    updated: 'Updated',
    denied: 'You are not authorized to view this dashboard.',
    notFound: 'We could not find this dashboard.',
    retry: 'Retry',
    notAvailable: 'Not available',
  },
} as const

function isDomainResource(entity: Entity): entity is DomainResource {
  return 'resource_type' in entity
}

function dashboardTitle(entity: Entity): string {
  if (isDomainResource(entity)) {
    const title = entity.title ?? entity.name ?? entity.code
    if (title) return String(title)
  }
  if ('record_number' in entity) return entity.record_number
  return entity.id
}

function isScalar(value: unknown): value is string | number | boolean {
  return typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean'
}

export function DashboardsScreen() {
  const locale = useLocale()
  const csrfToken = useSessionToken()
  const principal = usePrincipal()
  const t = copy[locale]
  const scopeId = principal.effectiveScope?.scopeId
  const scopeEpoch = principal.scopeEpoch

  const [selectedId, setSelectedId] = useState('')
  const [detail, setDetail] = useState<DomainResource | null>(null)
  const [detailState, setDetailState] = useState<'idle' | 'loading' | 'ready' | 'forbidden' | 'not-found' | 'error'>('idle')
  const requestRevision = useRef(0)

  const dashboardsQuery = useApiQuery<CollectionResponse>(['dashboards'], getListDashboardsUrl({ limit: 50 }))
  const items = dashboardsQuery.data?.items ?? []
  const state: 'loading' | 'ready' | 'empty' | 'forbidden' | 'error' = dashboardsQuery.isLoading
    ? 'loading'
    : dashboardsQuery.isError
      ? dashboardsQuery.error instanceof ApiError && dashboardsQuery.error.status === 403
        ? 'forbidden'
        : 'error'
      : items.length > 0
        ? 'ready'
        : 'empty'

  const prevScopeEpoch = useRef(scopeEpoch)
  useEffect(() => {
    if (prevScopeEpoch.current !== scopeEpoch) {
      prevScopeEpoch.current = scopeEpoch
      void dashboardsQuery.refetch()
    }
  }, [scopeEpoch, dashboardsQuery])

  const loadDetail = useCallback(
    async (dashboardId: string) => {
      const request = ++requestRevision.current
      setDetail(null)
      setDetailState('loading')
      try {
        const entity = unwrap<Entity>(
          await generated.getDashboard(
            dashboardId,
            scopeId ? { scope_id: scopeId } : undefined,
            requestInit(csrfToken),
          ),
        )
        if (request !== requestRevision.current) return
        if (!isDomainResource(entity)) {
          setDetailState('error')
          return
        }
        setDetail(entity)
        setDetailState('ready')
      } catch (error) {
        if (request !== requestRevision.current) return
        setDetail(null)
        if (error instanceof ApiError && error.status === 403) setDetailState('forbidden')
        else if (error instanceof ApiError && error.status === 404) setDetailState('not-found')
        else setDetailState('error')
      }
    },
    [csrfToken, scopeId],
  )

  useEffect(() => {
    if (!selectedId) return
    void loadDetail(selectedId)
  }, [loadDetail, selectedId, scopeEpoch])

  const numericValues = detail
    ? Object.entries(detail.values ?? {}).filter((entry): entry is [string, number] => typeof entry[1] === 'number')
    : []
  const scalarValues = detail
    ? Object.entries(detail.values ?? {}).filter((entry): entry is [string, string | number | boolean] => isScalar(entry[1]))
    : []

  return (
    <Page aria-labelledby="dashboards-title">
      <PageHeader id="dashboards-title" title={t.title} description={t.description} />

      {state === 'loading' ? <SkeletonList rows={4} /> : null}
      {state === 'forbidden' ? <EmptyState title={t.denied} /> : null}
      {state === 'error' ? (
        <InlineError message={t.failed} retryLabel={t.retry} onRetry={() => void dashboardsQuery.refetch()} />
      ) : null}
      {state === 'empty' ? <EmptyState title={t.empty} /> : null}

      {state === 'ready' ? (
        <Panel id="dashboards-list-panel" title={t.selectDashboard} level={2}>
          <Field id="dashboards-select" label={t.selectDashboard}>
            <Select
              id="dashboards-select"
              value={selectedId}
              onChange={(value) => setSelectedId(value)}
              options={items.map((item) => ({ value: item.id, label: dashboardTitle(item) }))}
              placeholder={t.selectDashboard}
            />
          </Field>
        </Panel>
      ) : null}

      {detailState === 'loading' ? <SkeletonList rows={3} /> : null}
      {detailState === 'forbidden' ? <EmptyState title={t.denied} /> : null}
      {detailState === 'not-found' ? <EmptyState title={t.notFound} /> : null}
      {detailState === 'error' ? (
        <InlineError message={t.failed} retryLabel={t.retry} onRetry={() => void loadDetail(selectedId)} />
      ) : null}

      {detailState === 'ready' && detail ? (
        <PanelGrid>
          <Panel id="dashboard-detail-panel" title={t.dashboardDetail} level={2}>
            <div className="metric-grid" role="group" aria-label={t.dashboardDetail}>
              <div className="metric-tile">
                <span className="metric-tile__value">{statusLabel(String(detail.status ?? ''), locale)}</span>
                <span className="metric-tile__label">{t.status}</span>
              </div>
              <div className="metric-tile">
                <span className="metric-tile__value">{statusLabel(detail.classification, locale)}</span>
                <span className="metric-tile__label">{t.classification}</span>
              </div>
              {typeof detail.version_number === 'number' ? (
                <div className="metric-tile">
                  <span className="metric-tile__value">{formatNumber(detail.version_number, locale)}</span>
                  <span className="metric-tile__label">{t.version}</span>
                </div>
              ) : null}
              <div className="metric-tile">
                <span className="metric-tile__value">{formatDate(detail.updated_at, locale)}</span>
                <span className="metric-tile__label">{t.updated}</span>
              </div>
            </div>
            {numericValues.length > 0 ? (
              <div className="metric-grid" role="group" aria-label={t.values}>
                {numericValues.map(([key, value]) => (
                  <div key={key} className="metric-tile metric-tile--success">
                    <span className="metric-tile__value">{formatNumber(value, locale)}</span>
                    <span className="metric-tile__label">{key}</span>
                  </div>
                ))}
              </div>
            ) : null}
            {scalarValues.length > 0 ? (
              <section aria-labelledby="dashboard-values-title">
                <h3 className="panel__heading" id="dashboard-values-title">
                  {t.values}
                </h3>
                <div className="table-scroll">
                  <table className="entity-table">
                    <thead>
                      <tr>
                        <th scope="col">{t.values}</th>
                        <th scope="col">{t.status}</th>
                      </tr>
                    </thead>
                    <tbody>
                      {scalarValues.map(([key, value]) => (
                        <tr key={key}>
                          <td>{key}</td>
                          <td>{String(value)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </section>
            ) : null}
            <p className="status-message">
              <StatusBadge>{statusLabel(String(detail.status ?? ''), locale)}</StatusBadge>{' '}
              {detail.description ?? t.notAvailable}
            </p>
          </Panel>
        </PanelGrid>
      ) : null}
    </Page>
  )
}

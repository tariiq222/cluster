import { useCallback, useEffect, useRef, useState, type CSSProperties } from 'react'
import * as generated from '../../api/generated/cluster'
import { getListDashboardsUrl } from '../../api/generated/cluster'
import type { CollectionResponse, DomainResource, Entity } from '../../api/generated/cluster'
import { ApiError, requestInit, unwrap } from '../../api/http'
import { useApiQuery } from '../../api/query'
import { usePrincipal } from '../../app/principal-context'
import { useLocale, useSessionToken } from '../../app/session-context'
import { formatDate, formatNumber, statusLabel } from '../../i18n'
import { DeniedState, EmptyState, ErrorState, LoadingState } from '@/components/states'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Bar, BarChart, CartesianGrid, Cell, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { reportsCopy } from './reports-copy'

type DetailState = 'idle' | 'loading' | 'ready' | 'forbidden' | 'not-found' | 'error'

/*
 * Chart series colors come exclusively from the theme's chart tokens
 * (DESIGN-RULES §1.2): `var(--chart-1)` … `var(--chart-5)`. No literal
 * colors and no hand-written `dark:` overrides — both modes resolve through
 * the CSS variables in `src/styles/theme.css`.
 */
const CHART_COLORS = [
  'var(--chart-1)',
  'var(--chart-2)',
  'var(--chart-3)',
  'var(--chart-4)',
  'var(--chart-5)',
] as const

const TOOLTIP_CONTENT_STYLE: CSSProperties = {
  backgroundColor: 'var(--color-popover)',
  border: '1px solid var(--color-border)',
  borderRadius: 'var(--radius-md)',
  color: 'var(--color-popover-foreground)',
  fontFamily: 'var(--font-sans)',
}

function isDomainResource(entity: Entity): entity is DomainResource {
  return 'resource_type' in entity
}

function dashboardTitle(entity: Entity): string {
  if (isDomainResource(entity)) {
    const title = entity.title ?? entity.name ?? entity.code
    if (title) return String(title)
  }
  return entity.id
}

/*
 * Dashboards tab: a dashboard selector in an accessible toolbar above the
 * selected dashboard's read-only detail Card (stacked vertically, responsive
 * on small screens). Numeric values render as a recharts bar chart colored
 * from the chart tokens; non-numeric scalars stay in a shadcn Table. 403 and
 * 404 collapse into the shared non-disclosing DeniedState.
 */
export function DashboardsScreen() {
  const locale = useLocale()
  const csrfToken = useSessionToken()
  const principal = usePrincipal()
  const t = reportsCopy[locale]
  const scopeId = principal.effectiveScope?.scopeId
  const scopeEpoch = principal.scopeEpoch

  const [selectedId, setSelectedId] = useState('')
  const [detail, setDetail] = useState<DomainResource | null>(null)
  const [detailState, setDetailState] = useState<DetailState>('idle')
  const requestRevision = useRef(0)

  const dashboardsQuery = useApiQuery<CollectionResponse>(['dashboards', scopeEpoch], getListDashboardsUrl({ limit: 50 }))
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
  // Numeric values render only in the chart; the scalar table keeps the
  // non-numeric scalars (strings/booleans) so nothing is duplicated.
  const scalarValues = detail
    ? Object.entries(detail.values ?? {}).filter(
        (entry): entry is [string, string | boolean] =>
          typeof entry[1] === 'string' || typeof entry[1] === 'boolean',
      )
    : []
  const chartData = numericValues.map(([name, value], index) => ({
    name,
    value,
    fill: CHART_COLORS[index % CHART_COLORS.length] ?? CHART_COLORS[0],
  }))

  return (
    <div className="space-y-4">
      <h2 className="text-xl font-semibold tracking-tight">{t.dashboardsTitle}</h2>
      <p className="text-muted-foreground text-sm">{t.dashboardsDescription}</p>

      {state === 'loading' ? <LoadingState rows={4} /> : null}
      {state === 'forbidden' ? <DeniedState locale={locale} /> : null}
      {state === 'error' ? (
        <ErrorState locale={locale} onRetry={() => void dashboardsQuery.refetch()} />
      ) : null}
      {state === 'empty' ? <EmptyState title={t.dashboardsEmpty} /> : null}

      {state === 'ready' ? (
        <div
          role="toolbar"
          aria-label={t.selectDashboard}
          className="flex flex-wrap items-center gap-3"
        >
          <Label htmlFor="dashboard-select">{t.selectDashboard}</Label>
          <Select value={selectedId} onValueChange={(value) => setSelectedId(value)}>
            <SelectTrigger id="dashboard-select" className="w-fit">
              <SelectValue placeholder={t.selectDashboard} />
            </SelectTrigger>
            <SelectContent>
              {items.map((item) => (
                <SelectItem key={item.id} value={item.id}>
                  {dashboardTitle(item)}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      ) : null}

      {detailState === 'loading' ? <LoadingState rows={3} /> : null}
      {detailState === 'forbidden' || detailState === 'not-found' ? (
        <DeniedState locale={locale} />
      ) : null}
      {detailState === 'error' ? (
        <ErrorState locale={locale} onRetry={() => void loadDetail(selectedId)} />
      ) : null}

      {detailState === 'ready' && detail ? (
        <Card>
          <CardHeader>
            <CardTitle>{dashboardTitle(detail)}</CardTitle>
            <CardDescription>{t.dashboardDetail}</CardDescription>
          </CardHeader>
          <CardContent className="space-y-6">
            <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
              <div className="grid gap-1">
                <span className="text-muted-foreground text-xs">{t.status}</span>
                <span className="font-medium">{statusLabel(String(detail.status ?? ''), locale)}</span>
              </div>
              <div className="grid gap-1">
                <span className="text-muted-foreground text-xs">{t.classification}</span>
                <span className="font-medium">{statusLabel(detail.classification, locale)}</span>
              </div>
              {typeof detail.version_number === 'number' ? (
                <div className="grid gap-1">
                  <span className="text-muted-foreground text-xs">{t.version}</span>
                  <span className="font-medium">{formatNumber(detail.version_number, locale)}</span>
                </div>
              ) : null}
              <div className="grid gap-1">
                <span className="text-muted-foreground text-xs">{t.updated}</span>
                <span className="font-medium">{formatDate(detail.updated_at, locale)}</span>
              </div>
            </div>

            {numericValues.length > 0 ? (
              <section aria-labelledby="dashboard-chart-title">
                <h3 className="text-base font-semibold" id="dashboard-chart-title">
                  {t.dashboardChart}
                </h3>
                <div
                  className="h-60 rounded-lg bg-muted/50 p-4"
                  role="img"
                  aria-label={t.dashboardChart}
                >
                  <ResponsiveContainer
                    width="100%"
                    height="100%"
                    initialDimension={{ width: 480, height: 216 }}
                  >
                    <BarChart data={chartData} margin={{ top: 8, right: 8, bottom: 8, left: 8 }}>
                      <CartesianGrid vertical={false} stroke="var(--color-border)" />
                      <XAxis
                        dataKey="name"
                        tickLine={false}
                        axisLine={false}
                        tick={{ fill: 'var(--color-muted-foreground)', fontSize: 12 }}
                      />
                      <YAxis
                        tickLine={false}
                        axisLine={false}
                        tick={{ fill: 'var(--color-muted-foreground)', fontSize: 12 }}
                      />
                      <Tooltip
                        cursor={{ fill: 'var(--color-muted)' }}
                        contentStyle={TOOLTIP_CONTENT_STYLE}
                        labelStyle={{ color: 'var(--color-muted-foreground)' }}
                        formatter={(value) => formatNumber(Number(value), locale)}
                      />
                      <Bar dataKey="value" radius={4} isAnimationActive={false}>
                        {chartData.map((entry) => (
                          <Cell key={entry.name} fill={entry.fill} />
                        ))}
                      </Bar>
                    </BarChart>
                  </ResponsiveContainer>
                </div>
              </section>
            ) : null}

            {scalarValues.length > 0 ? (
              <section aria-labelledby="dashboard-values-title">
                <h3 className="text-base font-semibold" id="dashboard-values-title">
                  {t.values}
                </h3>
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>{t.values}</TableHead>
                      <TableHead>{t.status}</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {scalarValues.map(([key, value]) => (
                      <TableRow key={key}>
                        <TableCell>{key}</TableCell>
                        <TableCell>{String(value)}</TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </section>
            ) : null}

            <div className="flex flex-wrap items-center gap-2">
              <Badge variant="outline">{statusLabel(String(detail.status ?? ''), locale)}</Badge>
              <p className="text-muted-foreground text-sm">{detail.description ?? t.notAvailable}</p>
            </div>
          </CardContent>
        </Card>
      ) : null}
    </div>
  )
}

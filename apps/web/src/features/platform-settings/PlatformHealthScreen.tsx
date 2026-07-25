import { formattingLocale, type Locale } from '../../app/copy'

import { Activity } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'

import { ApiError } from '../../api'
import {
  getPlatformHealth,
  listPlatformAlertPolicies,
  updatePlatformAlertPolicy,
} from '../../api/platform-settings'
import { Button, DataFreshness, Drawer, Field, Panel, StatusBadge } from '../../ui'
import {
  isAllowed,
  platformEntity,
  screenText,
  stateGate,
  type PlatformScreenProps,
} from './screen-support'

type AlertPolicy = {
  id: string
  lockVersion: number
  severity?: string
  status?: string
  channel?: string
  description?: string
}

type HealthCheck = {
  code: string
  status: 'healthy' | 'degraded' | 'unhealthy' | 'unknown'
  latency_ms: number | null
  message_code: string | null
}

export function PlatformHealthScreen({
  locale,
  state = 'success',
  allowedActions,
  resource,
  token,
}: PlatformScreenProps & { token?: string }) {
  const entity = platformEntity(resource)
  const checks = readChecks(resource)
  const [policyOpen, setPolicyOpen] = useState(false)
  const [policies, setPolicies] = useState<AlertPolicy[]>([])
  const [policyLoadError, setPolicyLoadError] = useState<string | null>(null)
  const [policyLoadBusy, setPolicyLoadBusy] = useState(false)
  const [editingPolicy, setEditingPolicy] = useState<AlertPolicy | null>(null)
  const [editStatus, setEditStatus] = useState('')
  const [editSeverity, setEditSeverity] = useState('')
  const [editChannel, setEditChannel] = useState('')
  const [editBusy, setEditBusy] = useState(false)
  const [editError, setEditError] = useState<string | null>(null)
  const [refreshError, setRefreshError] = useState<string | null>(null)
  const [refreshBusy, setRefreshBusy] = useState(false)
  /**
   * Track in-flight policy load and mutation requests so superseded calls
   * cannot overwrite the freshest snapshot. The unmount cleanup bumps the refs
   * so no async callback writes after the platform health screen is torn down.
   */
  const activeRef = useRef(true)
  const loadRequestRef = useRef(0)
  const mutationEpochRef = useRef(0)
  useEffect(() => () => {
    activeRef.current = false
    loadRequestRef.current += 1
    mutationEpochRef.current += 1
  }, [])

  useEffect(() => {
    setRefreshError(null)
  }, [token])

  useEffect(() => {
    if (token === undefined) return
    const epoch = ++loadRequestRef.current
    activeRef.current = true
    setPolicyLoadBusy(true)
    setPolicyLoadError(null)
    listPlatformAlertPolicies(token)
      .then((response) => {
        if (!activeRef.current || epoch !== loadRequestRef.current) return
        const items = Array.isArray((response as { items?: unknown }).items)
          ? ((response as { items: unknown[] }).items)
          : []
        const mapped: AlertPolicy[] = []
        for (const item of items) {
          if (typeof item !== 'object' || item === null) continue
          const record = item as Record<string, unknown>
          const id = typeof record.id === 'string' ? record.id : ''
          if (id === '') continue
          const lockVersion = typeof record.lock_version === 'number' ? record.lock_version : 0
          const values = (record.values && typeof record.values === 'object') ? record.values as Record<string, unknown> : {}
          mapped.push({
            id,
            lockVersion,
            severity: typeof values.severity === 'string' ? values.severity : undefined,
            status: typeof values.status === 'string' ? values.status : undefined,
            channel: typeof values.channel === 'string' ? values.channel : undefined,
            description: typeof values.description === 'string' ? values.description : undefined,
          })
        }
        if (!activeRef.current || epoch !== loadRequestRef.current) return
        setPolicies(mapped)
      })
      .catch((error: unknown) => {
        if (!activeRef.current || epoch !== loadRequestRef.current) return
        setPolicyLoadError(error instanceof ApiError ? error.problem.title : 'Policies could not be loaded')
      })
      .finally(() => {
        if (activeRef.current && epoch === loadRequestRef.current) setPolicyLoadBusy(false)
      })
  }, [token])

  function startEdit(policy: AlertPolicy): void {
    setEditingPolicy(policy)
    setEditStatus(policy.status ?? '')
    setEditSeverity(policy.severity ?? '')
    setEditChannel(policy.channel ?? '')
    setEditError(null)
  }

  async function saveEdit(): Promise<void> {
    if (editingPolicy === null || token === undefined || editBusy) return
    setEditBusy(true)
    setEditError(null)
    try {
      await updatePlatformAlertPolicy(
        token,
        editingPolicy.id,
        {
          status: editStatus === '' ? undefined : editStatus,
          severity: editSeverity === '' ? undefined : editSeverity,
          channel: editChannel === '' ? undefined : editChannel,
        },
        editingPolicy.lockVersion,
      )
      setEditingPolicy(null)
      const refreshed = await listPlatformAlertPolicies(token)
      const items = Array.isArray((refreshed as { items?: unknown }).items)
        ? ((refreshed as { items: unknown[] }).items)
        : []
      const mapped: AlertPolicy[] = []
      for (const item of items) {
        if (typeof item !== 'object' || item === null) continue
        const record = item as Record<string, unknown>
        const id = typeof record.id === 'string' ? record.id : ''
        if (id === '') continue
        const lockVersion = typeof record.lock_version === 'number' ? record.lock_version : 0
        const values = (record.values && typeof record.values === 'object') ? record.values as Record<string, unknown> : {}
        mapped.push({
          id,
          lockVersion,
          severity: typeof values.severity === 'string' ? values.severity : undefined,
          status: typeof values.status === 'string' ? values.status : undefined,
          channel: typeof values.channel === 'string' ? values.channel : undefined,
          description: typeof values.description === 'string' ? values.description : undefined,
        })
      }
      setPolicies(mapped)
    } catch (error) {
      setEditError(error instanceof ApiError ? error.problem.title : 'Policy update failed')
    } finally {
      setEditBusy(false)
    }
  }

  const gate = stateGate(
    locale,
    state,
    screenText(locale, 'لا توجد نتائج صحة', 'No health checks are available'),
  )
  if (gate) return gate
  const overall = readString(entity?.status) ?? 'unknown'
  const overallVariant: 'success' | 'warning' | 'danger' | 'neutral' =
    overall === 'healthy'
      ? 'success'
      : overall === 'degraded'
      ? 'warning'
      : overall === 'unhealthy'
      ? 'danger'
      : 'neutral'
  const overallLabel = screenText(
    locale,
    overall === 'healthy'
      ? 'سليم'
      : overall === 'degraded'
      ? 'متدهور'
      : overall === 'unhealthy'
      ? 'فشل'
      : 'غير معروف',
    overall === 'healthy'
      ? 'Healthy'
      : overall === 'degraded'
      ? 'Degraded'
      : overall === 'unhealthy'
      ? 'Failed'
      : 'Unknown',
  )

  async function refresh(): Promise<void> {
    if (token === undefined) return
    setRefreshBusy(true)
    setRefreshError(null)
    try {
      await getPlatformHealth(token)
    } catch (error) {
      setRefreshError(error instanceof ApiError ? error.problem.title : 'Refresh failed')
    } finally {
      setRefreshBusy(false)
    }
  }

  return (
    <div className="platform-screen">
      <DataFreshness
        state={state === 'stale' ? 'stale' : 'fresh'}
        updatedAt={
          <HealthCheckedStamp
            locale={locale}
            updatedAt={readString(entity?.updated_at)}
          />
        }
        staleAfterMinutes={15}
      />
      <Panel id="health-overall" title={screenText(locale, 'الحالة العامة', 'Overall status')}>
        <StatusBadge variant={overallVariant}>{overallLabel}</StatusBadge>
        {token !== undefined ? (
          <div className="platform-action-row">
            <Button variant="secondary" onClick={() => void refresh()} disabled={refreshBusy}>
              {screenText(locale, 'تحديث الفحص', 'Refresh check')}
            </Button>
          </div>
        ) : null}
        {refreshError !== null ? (
          <p role="alert" className="platform-error">
            {refreshError}
          </p>
        ) : null}
      </Panel>
      <Panel id="health-services" title={screenText(locale, 'الخدمات', 'Services')}>
        {checks.length === 0 ? (
          <p>{screenText(locale, 'لا توجد فحوصات متاحة.', 'No checks are available.')}</p>
        ) : (
          <ul className="platform-status-list">
            {checks.map((check) => (
              <li key={check.code}>
                <Activity aria-hidden="true" />
                <span>
                  {check.code}
                  {check.latency_ms !== null ? ` — ${check.latency_ms}ms` : ''}
                </span>
                <StatusBadge variant={variantFor(check.status)}>
                  {labelFor(check.status, locale)}
                </StatusBadge>
              </li>
            ))}
          </ul>
        )}
      </Panel>
      <Panel id="alert-policies" title={screenText(locale, 'سياسات التوجيه', 'Routing policies')}>
        {policyLoadError !== null ? (
          <p role="alert" className="platform-error">{policyLoadError}</p>
        ) : null}
        {policyLoadBusy ? (
          <p>{screenText(locale, 'جارٍ تحميل السياسات…', 'Loading policies…')}</p>
        ) : policies.length === 0 ? (
          <p>{screenText(locale, 'لا توجد سياسات توجيه معرفة.', 'No routing policies are defined.')}</p>
        ) : (
          <ul className="platform-status-list">
            {policies.map((policy) => (
              <li key={policy.id}>
                <Activity aria-hidden="true" />
                <span>
                  {policy.description ?? policy.id}
                  {policy.severity !== undefined ? ` — ${policy.severity}` : ''}
                </span>
                <StatusBadge variant={policy.status === 'active' ? 'success' : 'neutral'}>
                  {policy.status ?? 'unknown'}
                </StatusBadge>
                {isAllowed(allowedActions, 'platform_operations.alerts.manage') ? (
                  <Button variant="quiet" onClick={() => startEdit(policy)}>
                    {screenText(locale, 'تعديل', 'Edit')}
                  </Button>
                ) : null}
              </li>
            ))}
          </ul>
        )}
        {isAllowed(allowedActions, 'platform_operations.alerts.manage') && editingPolicy === null ? (
          <Button variant="secondary" onClick={() => setPolicyOpen(true)} disabled={policies.length === 0}>
            {screenText(locale, 'مراجعة سياسات التوجيه', 'Review routing policies')}
          </Button>
        ) : null}
      </Panel>
      <Drawer
        open={policyOpen || editingPolicy !== null}
        onClose={() => { setPolicyOpen(false); setEditingPolicy(null); setEditError(null) }}
        title={editingPolicy !== null
          ? screenText(locale, 'تعديل السياسة', 'Edit policy')
          : screenText(locale, 'سياسات التوجيه', 'Routing policies')}
      >
        {editingPolicy !== null ? (
          <div className="platform-form">
            <p>{editingPolicy.description ?? editingPolicy.id}</p>
            <Field id="alert-policy-status" label={screenText(locale, 'الحالة', 'Status')}>
              <input id="alert-policy-status" type="text" value={editStatus} onChange={(event) => setEditStatus(event.target.value)} />
            </Field>
            <Field id="alert-policy-severity" label={screenText(locale, 'الخطورة', 'Severity')}>
              <input id="alert-policy-severity" type="text" value={editSeverity} onChange={(event) => setEditSeverity(event.target.value)} />
            </Field>
            <Field id="alert-policy-channel" label={screenText(locale, 'القناة', 'Channel')}>
              <input id="alert-policy-channel" type="text" value={editChannel} onChange={(event) => setEditChannel(event.target.value)} />
            </Field>
            {editError !== null ? (
              <p role="alert" className="platform-error">{editError}</p>
            ) : null}
            <div className="platform-action-row">
              <Button onClick={() => void saveEdit()} disabled={editBusy}>
                {screenText(locale, 'حفظ', 'Save')}
              </Button>
              <Button variant="quiet" onClick={() => setEditingPolicy(null)} disabled={editBusy}>
                {screenText(locale, 'إلغاء', 'Cancel')}
              </Button>
            </div>
          </div>
        ) : (
          <>
            <p>
              {screenText(
                locale,
                'تغييرات التوجيه تخضع لحدود الأمان الثابتة وتحتاج نشرًا صريحًا.',
                'Routing changes stay within fixed security limits and require explicit publication.',
              )}
            </p>
            <div className="platform-action-row">
              <Button variant="quiet" onClick={() => setPolicyOpen(false)}>
                {screenText(locale, 'إغلاق', 'Close')}
              </Button>
            </div>
          </>
        )}
      </Drawer>
    </div>
  )
}

function readString(value: unknown): string | undefined {
  return typeof value === 'string' ? value : undefined
}

function readChecks(resource: PlatformScreenProps['resource']): HealthCheck[] {
  if (resource === undefined) return []
  const checks = (resource as { checks?: unknown }).checks
  if (!Array.isArray(checks)) return []
  const out: HealthCheck[] = []
  for (const entry of checks) {
    if (typeof entry !== 'object' || entry === null) continue
    const code = readString((entry as { code?: unknown }).code)
    const statusValue = readString((entry as { status?: unknown }).status)
    if (code === undefined || statusValue === undefined) continue
    out.push({
      code,
      status: statusValue as HealthCheck['status'],
      latency_ms: readNumber((entry as { latency_ms?: unknown }).latency_ms),
      message_code: readString((entry as { message_code?: unknown }).message_code) ?? null,
    })
  }
  return out
}

function readNumber(value: unknown): number | null {
  return typeof value === 'number' && Number.isFinite(value) ? value : null
}

function variantFor(status: HealthCheck['status']): 'success' | 'warning' | 'danger' | 'neutral' {
  if (status === 'healthy') return 'success'
  if (status === 'degraded') return 'warning'
  if (status === 'unhealthy') return 'danger'
  return 'neutral'
}

function labelFor(status: HealthCheck['status'], locale: 'ar' | 'en'): string {
  return screenText(
    locale,
    status === 'healthy'
      ? 'سليم'
      : status === 'degraded'
      ? 'متدهور'
      : status === 'unhealthy'
      ? 'فشل'
      : 'غير معروف',
    status === 'healthy'
      ? 'Healthy'
      : status === 'degraded'
      ? 'Degraded'
      : status === 'unhealthy'
      ? 'Failed'
      : 'Unknown',
  )
}

function HealthCheckedStamp({
  locale,
  updatedAt,
}: {
  locale: Locale
  updatedAt: string | undefined
}) {
  if (updatedAt === undefined) return null
  const formatted = new Intl.DateTimeFormat(formattingLocale(locale), {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(new Date(updatedAt))
  return (
    <>
      {screenText(locale, 'فُحصت', 'Checked')} <time dateTime={updatedAt}>{formatted}</time>
    </>
  )
}

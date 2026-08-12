import { useEffect, useState } from 'react'
import * as generated from '../../api/generated/cluster'
import { getListTasksUrl, getListWorkRecordsUrl } from '../../api/generated/cluster'
import type { CollectionResponse, WorkRecordCollection } from '../../api/generated/cluster'
import { requestInit, stateFromError, unwrap } from '../../api/http'
import { useNotificationsList } from '../../api/hooks'
import { useApiQuery, useScopeEpoch } from '../../api/query'
import { usePrincipal } from '../../app/principal-context'
import { useNavigate } from '../../app/navigation-context'
import { useLocale, useSessionToken } from '../../app/session-context'
import { formatDate, formatNumber, statusLabel } from '../../i18n'
import { PageHeader, PageLayout } from '@/components/page-layout'
import { EmptyState, ResourceBoundary } from '@/components/states'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader } from '@/components/ui/card'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { MapPin, SwatchBook } from 'lucide-react'

const copy = {
  ar: {
    title: 'الرئيسية',
    description: 'نظرة عامة على طلباتك ومهامك وإشعاراتك.',
    kpis: 'مؤشرات الأداء',
    activeRecords: 'السجلات النشطة',
    dueToday: 'مهام مستحقة اليوم',
    pendingApprovals: 'موافقات معلقة',
    myRequests: 'طلباتي',
    viewAll: 'عرض الكل',
    noRequests: 'لا توجد طلبات ضمن نطاقك.',
    tasks: 'مهامي',
    noTasks: 'لا توجد مهام مسندة إليك.',
    notifications: 'إشعارات غير مقروءة',
    noNotifications: 'لا توجد إشعارات.',
    scope: 'نطاق عملك الحالي',
    noScope: 'لا يوجد نطاق فعّال.',
    switchScope: 'تبديل',
    retry: 'إعادة المحاولة',
    tasksFailed: 'تعذر تحميل مهامك.',
    notificationsFailed: 'تعذر تحميل الإشعارات.',
  },
  en: {
    title: 'Home',
    description: 'An overview of your requests, tasks, and notifications.',
    kpis: 'Key performance indicators',
    activeRecords: 'Active records',
    dueToday: 'Tasks due today',
    pendingApprovals: 'Pending approvals',
    myRequests: 'My requests',
    viewAll: 'View all',
    noRequests: 'No requests are available in your scope.',
    tasks: 'My tasks',
    noTasks: 'No tasks are assigned to you.',
    notifications: 'Unread notifications',
    noNotifications: 'No notifications.',
    scope: 'Your current scope',
    noScope: 'No effective scope.',
    switchScope: 'Switch',
    retry: 'Retry',
    tasksFailed: 'Your tasks could not be loaded.',
    notificationsFailed: 'Notifications could not be loaded.',
  },
} as const

const INACTIVE_RECORD_STATUSES = new Set(['completed', 'cancelled', 'archived', 'rejected'])

function isDueToday(value: string | undefined): boolean {
  if (!value) return false
  const due = new Date(value)
  const now = new Date()
  if (Number.isNaN(due.getTime())) return false
  return due.getFullYear() === now.getFullYear() && due.getMonth() === now.getMonth() && due.getDate() === now.getDate()
}

function taskDueAt(entity: generated.Entity): string | undefined {
  return 'due_at' in entity ? entity.due_at : undefined
}

function taskTitle(entity: generated.Entity): string {
  if ('resource_type' in entity) {
    const title = entity.title ?? entity.name ?? entity.code
    if (title) return String(title)
  }
  return entity.id
}

function DashboardCard({
  title,
  action,
  state,
  onRetry,
  empty,
  children,
}: {
  title: string
  action?: React.ReactNode
  state: 'loading' | 'ready' | 'empty' | 'forbidden' | 'not-found' | 'conflict' | 'stale' | 'error'
  onRetry?: () => void
  empty?: React.ReactNode
  children: React.ReactNode
}) {
  const locale = useLocale()
  return (
    <Card>
      <CardHeader className="flex flex-row items-center justify-between gap-2">
        <h2 className="text-base font-medium leading-snug">{title}</h2>
        {action}
      </CardHeader>
      <CardContent>
        <ResourceBoundary state={state} locale={locale} onRetry={onRetry} rows={3} empty={empty}>
          {children}
        </ResourceBoundary>
      </CardContent>
    </Card>
  )
}

export function HomeDashboard() {
  const locale = useLocale()
  const csrfToken = useSessionToken()
  const principal = usePrincipal()
  const navigate = useNavigate()
  const t = copy[locale]

  const workManagement = principal.features?.work_management ?? false
  const tasksEnabled = principal.features?.tasks ?? false
  const canDecide = workManagement && (principal.capabilities?.includes('workflow.decide') ?? false)

  const scopeEpoch = useScopeEpoch()
  const recordsQuery = useApiQuery<WorkRecordCollection>(
    ['work-records', { limit: 100 }, scopeEpoch],
    getListWorkRecordsUrl({ limit: 100 }),
    { enabled: workManagement },
  )
  const tasksQuery = useApiQuery<CollectionResponse>(
    ['tasks', { limit: 100 }, scopeEpoch],
    getListTasksUrl({ limit: 100 }),
    { enabled: tasksEnabled },
  )
  const notificationsQuery = useNotificationsList(5)

  const records = recordsQuery.data?.items ?? []
  const tasks = tasksQuery.data?.items ?? []
  const notifications = (notificationsQuery.data as generated.NotificationCollection | undefined)?.items ?? []

  const [approvals, setApprovals] = useState<generated.WorkflowStepInboxItem[]>([])

  useEffect(() => {
    if (!canDecide) return
    let cancelled = false
    generated
      .listWorkflowStepsInbox(
        { limit: 100, state: 'active', assignee: 'me' },
        requestInit(csrfToken),
      )
      .then((response) => {
        if (cancelled) return
        setApprovals(unwrap<generated.WorkflowStepCollection>(response).items ?? [])
      })
      .catch(() => {
        // The KPI row degrades to zero on failure; the workspace is the
        // authoritative surface for approvals.
        if (!cancelled) setApprovals([])
      })
    return () => {
      cancelled = true
    }
  }, [canDecide, csrfToken])

  const activeRecordCount = records.filter((record) => !INACTIVE_RECORD_STATUSES.has(record.status)).length
  const dueTodayCount = tasks.filter((task) => isDueToday(taskDueAt(task))).length
  const pendingApprovalCount = approvals.length

  const tasksState = tasksQuery.isError
    ? stateFromError(tasksQuery.error)
    : tasksQuery.isLoading ? 'loading' : tasks.length === 0 ? 'empty' : 'ready'
  const notificationsState = notificationsQuery.isError
    ? stateFromError(notificationsQuery.error)
    : notificationsQuery.isLoading ? 'loading' : notifications.length === 0 ? 'empty' : 'ready'

  /*
   * The KPI group stays a semantic <dl> for screen-reader output, but each
   * term/value pair now renders through the shared Card surface so the
   * visual hierarchy matches every other surface. Children use the same
   * `data-slot="card"` so the test fixture keeps finding them.
   */
  return (
    <PageLayout>
      <PageHeader title={t.title} description={t.description} />

      <dl
        data-testid="dashboard-kpis"
        role="group"
        aria-label={t.kpis}
        className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3"
      >
        <Card>
          <CardContent className="flex flex-col-reverse gap-1">
            <dt className="text-muted-foreground text-sm">{t.activeRecords}</dt>
            <dd className="text-2xl font-semibold">{formatNumber(activeRecordCount, locale)}</dd>
          </CardContent>
        </Card>
        <Card>
          <CardContent className="flex flex-col-reverse gap-1">
            <dt className="text-muted-foreground text-sm">{t.dueToday}</dt>
            <dd className="text-2xl font-semibold">{formatNumber(dueTodayCount, locale)}</dd>
          </CardContent>
        </Card>
        {canDecide ? (
          <Card>
            <CardContent className="flex flex-col-reverse gap-1">
              <dt className="text-muted-foreground text-sm">{t.pendingApprovals}</dt>
              <dd className="text-2xl font-semibold">{formatNumber(pendingApprovalCount, locale)}</dd>
            </CardContent>
          </Card>
        ) : null}
      </dl>

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {tasksEnabled ? (
          <DashboardCard
            title={t.tasks}
            state={tasksState}
            onRetry={() => void tasksQuery.refetch()}
            empty={<EmptyState title={t.noTasks} />}
            action={
              <Button variant="ghost" size="sm" onClick={() => navigate('/tasks')}>
                {t.viewAll}
              </Button>
            }
          >
            <ul className="space-y-2">
              {tasks.slice(0, 5).map((task) => (
                <li key={task.id} className="flex items-center justify-between gap-2">
                  <span className="truncate text-sm">{taskTitle(task)}</span>
                  <Badge variant="outline">{statusLabel(task.status, locale)}</Badge>
                </li>
              ))}
            </ul>
          </DashboardCard>
        ) : null}

        <DashboardCard
          title={t.notifications}
          state={notificationsState}
          onRetry={() => void notificationsQuery.refetch()}
          empty={<EmptyState title={t.noNotifications} />}
          action={
            <Button variant="ghost" size="sm" onClick={() => navigate('/notifications')}>
              {t.viewAll}
            </Button>
          }
        >
          <ul className="space-y-2">
            {notifications.slice(0, 5).map((notification) => (
              <li key={notification.id} className="flex items-center justify-between gap-2">
                <span className="truncate text-sm">{notification.title}</span>
                <span className="text-muted-foreground text-xs">{formatDate(notification.created_at, locale)}</span>
              </li>
            ))}
          </ul>
        </DashboardCard>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between gap-2">
            <h2 className="text-base font-medium leading-snug">{t.scope}</h2>
            <MapPin aria-hidden="true" className="text-muted-foreground size-4" />
          </CardHeader>
          <CardContent>
            {principal.effectiveScope ? (
              <div className="space-y-3">
                <p className="text-sm font-medium">{principal.effectiveScope.label}</p>
                <DropdownMenu>
                  <DropdownMenuTrigger asChild>
                    <Button variant="outline" size="sm">
                      <SwatchBook aria-hidden="true" />
                      {t.switchScope}
                    </Button>
                  </DropdownMenuTrigger>
                  <DropdownMenuContent align="end">
                    {principal.availableScopes.map((scope) => (
                      <DropdownMenuItem
                        key={`${scope.scopeType}:${scope.scopeId}`}
                        onClick={() => {
                          void principal.selectScope(scope.scopeType, scope.scopeId).catch(() => undefined)
                        }}
                      >
                        {scope.label}
                      </DropdownMenuItem>
                    ))}
                  </DropdownMenuContent>
                </DropdownMenu>
              </div>
            ) : (
              <EmptyState icon={<MapPin aria-hidden="true" />} title={t.noScope} />
            )}
          </CardContent>
        </Card>
      </div>
    </PageLayout>
  )
}

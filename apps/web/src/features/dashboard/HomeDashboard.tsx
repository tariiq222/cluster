import * as generated from '../../api/generated/cluster'
import { stateFromError } from '../../api/http'
import { useNotificationsList, useTasksList } from '../../api/hooks'
import { usePrincipal } from '../../app/principal-context'
import { useNavigate } from '../../app/navigation-context'
import { useLocale } from '../../app/session-context'
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
    description: 'نظرة عامة على مهامك وإشعاراتك.',
    kpis: 'مؤشرات الأداء',
    activeTasks: 'المهام النشطة',
    dueToday: 'مهام مستحقة اليوم',
    viewAll: 'عرض الكل',
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
    description: 'An overview of your tasks and notifications.',
    kpis: 'Key performance indicators',
    activeTasks: 'Active tasks',
    dueToday: 'Tasks due today',
    viewAll: 'View all',
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

const INACTIVE_TASK_STATUSES = new Set(['completed', 'cancelled'])

function isDueToday(value: string | null | undefined): boolean {
  if (!value) return false
  const due = new Date(value)
  const now = new Date()
  if (Number.isNaN(due.getTime())) return false
  return due.getFullYear() === now.getFullYear() && due.getMonth() === now.getMonth() && due.getDate() === now.getDate()
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
  const principal = usePrincipal()
  const navigate = useNavigate()
  const t = copy[locale]

  const tasksEnabled = principal.features?.tasks ?? false

  const tasksQuery = useTasksList({ limit: 100, view: 'mine' }, tasksEnabled)
  const notificationsQuery = useNotificationsList(5)

  const tasks = tasksQuery.data?.items ?? []
  const notifications = (notificationsQuery.data as generated.NotificationCollection | undefined)?.items ?? []

  const activeTaskCount = tasks.filter((task) => !INACTIVE_TASK_STATUSES.has(task.state)).length
  const dueTodayCount = tasks.filter((task) => isDueToday(task.due_at)).length

  const tasksState = tasksQuery.isError
    ? stateFromError(tasksQuery.error)
    : tasksQuery.isPending ? 'loading' : tasks.length === 0 ? 'empty' : 'ready'
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
            <dt className="text-muted-foreground text-sm">{t.activeTasks}</dt>
            <dd className="text-2xl font-semibold">{formatNumber(activeTaskCount, locale)}</dd>
          </CardContent>
        </Card>
        <Card>
          <CardContent className="flex flex-col-reverse gap-1">
            <dt className="text-muted-foreground text-sm">{t.dueToday}</dt>
            <dd className="text-2xl font-semibold">{formatNumber(dueTodayCount, locale)}</dd>
          </CardContent>
        </Card>
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
                  <span className="truncate text-sm">{task.title}</span>
                  <Badge variant="outline">{statusLabel(task.state, locale)}</Badge>
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

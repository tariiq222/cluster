import { useEffect, useState } from 'react'
import * as generated from '../../../src/api/generated/cluster'
import type { Notification, WorkRecordSchema } from '../../../src/api/generated/cluster'
import { ApiError, requestInit, unwrap } from '../../api/http'
import { usePrincipal } from '../../app/principal-context'
import { useNavigate } from '../../app/navigation-context'
import { useLocale, useSessionToken } from '../../app/session-context'
import { formatDate, formatNumber, statusLabel } from '../../i18n'
import { Button, EmptyState, InlineError, Page, PageHeader, Panel, PanelGrid, SkeletonList, StatusBadge } from '../../ui'

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
    requestsFailed: 'تعذر تحميل طلباتك.',
    tasks: 'مهامي',
    noTasks: 'لا توجد مهام مسندة إليك.',
    tasksFailed: 'تعذر تحميل مهامك.',
    approvals: 'الموافقات المعلقة',
    approvalsBody: 'طلبات بانتظار قرارك ضمن سير العمل.',
    noApprovals: 'لا توجد موافقات معلقة لك.',
    approvalsFailed: 'تعذر تحميل الموافقات المعلقة.',
    notifications: 'الإشعارات',
    noNotifications: 'لا توجد إشعارات.',
    notificationsFailed: 'تعذر تحميل الإشعارات.',
    unread: 'غير مقروء',
    retry: 'إعادة المحاولة',
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
    requestsFailed: 'Your requests could not be loaded.',
    tasks: 'My tasks',
    noTasks: 'No tasks are assigned to you.',
    tasksFailed: 'Your tasks could not be loaded.',
    approvals: 'Pending approvals',
    approvalsBody: 'Requests awaiting your decision in the workflow.',
    noApprovals: 'No approvals are pending for you.',
    approvalsFailed: 'Pending approvals could not be loaded.',
    notifications: 'Notifications',
    noNotifications: 'No notifications.',
    notificationsFailed: 'Notifications could not be loaded.',
    unread: 'Unread',
    retry: 'Retry',
  },
} as const

const INACTIVE_RECORD_STATUSES = new Set(['completed', 'cancelled', 'archived', 'rejected'])

function apiMessage(error: unknown, fallback: string): string {
  return error instanceof ApiError ? (error.problem.detail ?? error.problem.title) : fallback
}

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

export function HomeDashboard() {
  const locale = useLocale()
  const csrfToken = useSessionToken()
  const principal = usePrincipal()
  const navigate = useNavigate()
  const t = copy[locale]

  const workManagement = principal.features?.work_management ?? false
  const tasksEnabled = principal.features?.tasks ?? false
  const canDecide = workManagement && (principal.capabilities?.includes('workflow.decide') ?? false)

  const [records, setRecords] = useState<WorkRecordSchema[]>([])
  const [recordsLoading, setRecordsLoading] = useState(false)
  const [recordsError, setRecordsError] = useState<string | null>(null)
  const [tasks, setTasks] = useState<generated.Entity[]>([])
  const [tasksLoading, setTasksLoading] = useState(false)
  const [tasksError, setTasksError] = useState<string | null>(null)
  const [approvals, setApprovals] = useState<generated.WorkflowStepInboxItem[]>([])
  const [approvalsLoading, setApprovalsLoading] = useState(false)
  const [approvalsError, setApprovalsError] = useState<string | null>(null)
  const [notifications, setNotifications] = useState<Notification[]>([])
  const [notificationsLoading, setNotificationsLoading] = useState(false)
  const [notificationsError, setNotificationsError] = useState<string | null>(null)
  const [reloadKey, setReloadKey] = useState(0)

  const retry = () => setReloadKey((key) => key + 1)

  useEffect(() => {
    if (!workManagement) return
    let cancelled = false
    setRecordsLoading(true)
    setRecordsError(null)
    generated
      .listWorkRecords({ limit: 100 }, requestInit(csrfToken))
      .then((response) => {
        if (cancelled) return
        setRecords(unwrap<generated.WorkRecordCollection>(response).items ?? [])
        setRecordsLoading(false)
      })
      .catch((error: unknown) => {
        if (cancelled) return
        setRecords([])
        setRecordsError(apiMessage(error, t.requestsFailed))
        setRecordsLoading(false)
      })
    return () => {
      cancelled = true
    }
  }, [workManagement, csrfToken, reloadKey, t.requestsFailed])

  useEffect(() => {
    if (!tasksEnabled) return
    let cancelled = false
    setTasksLoading(true)
    setTasksError(null)
    generated
      .listTasks({ limit: 100 }, requestInit(csrfToken))
      .then((response) => {
        if (cancelled) return
        setTasks(unwrap<generated.CollectionResponse>(response).items ?? [])
        setTasksLoading(false)
      })
      .catch((error: unknown) => {
        if (cancelled) return
        setTasks([])
        setTasksError(apiMessage(error, t.tasksFailed))
        setTasksLoading(false)
      })
    return () => {
      cancelled = true
    }
  }, [tasksEnabled, csrfToken, reloadKey, t.tasksFailed])

  useEffect(() => {
    if (!canDecide) return
    let cancelled = false
    setApprovalsLoading(true)
    setApprovalsError(null)
    generated
      .listWorkflowStepsInbox(
        { limit: 100, state: 'active', assignee: 'me' },
        requestInit(csrfToken),
      )
      .then((response) => {
        if (cancelled) return
        setApprovals(unwrap<generated.WorkflowStepCollection>(response).items ?? [])
        setApprovalsLoading(false)
      })
      .catch((error: unknown) => {
        if (cancelled) return
        setApprovals([])
        setApprovalsError(apiMessage(error, t.approvalsFailed))
        setApprovalsLoading(false)
      })
    return () => {
      cancelled = true
    }
  }, [canDecide, csrfToken, reloadKey, t.approvalsFailed])

  useEffect(() => {
    let cancelled = false
    setNotificationsLoading(true)
    setNotificationsError(null)
    generated
      .listMyNotifications({ limit: 5 }, requestInit(csrfToken))
      .then((response) => {
        if (cancelled) return
        setNotifications(unwrap<generated.NotificationCollection>(response).items ?? [])
        setNotificationsLoading(false)
      })
      .catch((error: unknown) => {
        if (cancelled) return
        setNotifications([])
        setNotificationsError(apiMessage(error, t.notificationsFailed))
        setNotificationsLoading(false)
      })
    return () => {
      cancelled = true
    }
  }, [csrfToken, reloadKey, t.notificationsFailed])

  const activeRecordCount = records.filter((record) => !INACTIVE_RECORD_STATUSES.has(record.status)).length
  const dueTodayCount = tasks.filter((task) => isDueToday(taskDueAt(task))).length
  const pendingApprovalCount = approvals.length

  return (
    <Page aria-labelledby="home-dashboard-title">
      <PageHeader id="home-dashboard-title" title={t.title} description={t.description} />

      <div className="metric-grid" role="group" aria-label={t.kpis}>
        <div className="metric-tile">
          <span className="metric-tile__value">{formatNumber(activeRecordCount, locale)}</span>
          <span className="metric-tile__label">{t.activeRecords}</span>
        </div>
        <div className="metric-tile">
          <span className="metric-tile__value">{formatNumber(dueTodayCount, locale)}</span>
          <span className="metric-tile__label">{t.dueToday}</span>
        </div>
        {canDecide ? (
          <div className="metric-tile metric-tile--warning">
            <span className="metric-tile__value">{formatNumber(pendingApprovalCount, locale)}</span>
            <span className="metric-tile__label">{t.pendingApprovals}</span>
          </div>
        ) : null}
      </div>

      <PanelGrid>
        {workManagement ? (
          <Panel
            id="home-my-requests"
            title={t.myRequests}
            level={2}
            actions={
              <Button variant="secondary" onClick={() => navigate('/search')}>
                {t.viewAll}
              </Button>
            }
          >
            {recordsLoading ? <SkeletonList rows={3} /> : null}
            {!recordsLoading && recordsError ? (
              <InlineError message={recordsError} retryLabel={t.retry} onRetry={retry} />
            ) : null}
            {!recordsLoading && !recordsError && records.length === 0 ? (
              <EmptyState title={t.noRequests} />
            ) : null}
            {!recordsLoading && !recordsError && records.length > 0 ? (
              <ul className="screen-list">
                {records.slice(0, 5).map((record) => (
                  <li key={record.id} className="screen-list__row">
                    <span className="screen-list__row-title">{record.record_number}</span>
                    <StatusBadge>{statusLabel(record.status, locale)}</StatusBadge>
                  </li>
                ))}
              </ul>
            ) : null}
          </Panel>
        ) : null}

        {tasksEnabled ? (
          <Panel
            id="home-tasks"
            title={t.tasks}
            level={2}
            actions={
              <Button variant="secondary" onClick={() => navigate('/tasks')}>
                {t.viewAll}
              </Button>
            }
          >
            {tasksLoading ? <SkeletonList rows={3} /> : null}
            {!tasksLoading && tasksError ? (
              <InlineError message={tasksError} retryLabel={t.retry} onRetry={retry} />
            ) : null}
            {!tasksLoading && !tasksError && tasks.length === 0 ? (
              <EmptyState title={t.noTasks} />
            ) : null}
            {!tasksLoading && !tasksError && tasks.length > 0 ? (
              <ul className="screen-list">
                {tasks.slice(0, 5).map((task) => (
                  <li key={task.id} className="screen-list__row">
                    <span className="screen-list__row-title">{taskTitle(task)}</span>
                    <StatusBadge>{statusLabel(task.status, locale)}</StatusBadge>
                  </li>
                ))}
              </ul>
            ) : null}
          </Panel>
        ) : null}

        {canDecide ? (
          <Panel id="home-approvals" title={t.approvals} level={2}>
            <p className="status-message">{t.approvalsBody}</p>
            {approvalsLoading ? <SkeletonList rows={2} /> : null}
            {!approvalsLoading && approvalsError ? (
              <InlineError message={approvalsError} retryLabel={t.retry} onRetry={retry} />
            ) : null}
            {!approvalsLoading && !approvalsError && approvals.length === 0 ? (
              <EmptyState title={t.noApprovals} />
            ) : null}
            {!approvalsLoading && !approvalsError && approvals.length > 0 ? (
              <ul className="screen-list">
                {approvals.map((step) => (
                  <li key={step.step_id} className="screen-list__row">
                    <span className="screen-list__row-title">{step.source_type}</span>
                    <StatusBadge variant="warning">{statusLabel(step.state, locale)}</StatusBadge>
                  </li>
                ))}
              </ul>
            ) : null}
          </Panel>
        ) : null}

        <Panel
          id="home-notifications"
          title={t.notifications}
          level={2}
          actions={
            <Button variant="secondary" onClick={() => navigate('/notifications')}>
              {t.viewAll}
            </Button>
          }
        >
          {notificationsLoading ? <SkeletonList rows={3} /> : null}
          {!notificationsLoading && notificationsError ? (
            <InlineError message={notificationsError} retryLabel={t.retry} onRetry={retry} />
          ) : null}
          {!notificationsLoading && !notificationsError && notifications.length === 0 ? (
            <EmptyState title={t.noNotifications} />
          ) : null}
          {!notificationsLoading && !notificationsError && notifications.length > 0 ? (
            <ul className="screen-list">
              {notifications.map((notification) => (
                <li key={notification.id} className="screen-list__row">
                  <span className="screen-list__row-title">{notification.title}</span>
                  <span className="screen-list__row-meta">{formatDate(notification.created_at, locale)}</span>
                  {!notification.is_read ? (
                    <StatusBadge variant="info">{t.unread}</StatusBadge>
                  ) : null}
                </li>
              ))}
            </ul>
          ) : null}
        </Panel>
      </PanelGrid>
    </Page>
  )
}

import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Inbox, Plus } from 'lucide-react'
import * as generated from '../../../src/api/generated/cluster'
import { requestInit, stateFromError, unwrap, type ResourceState } from '../../api/http'
import { useNavigate } from '../../app/navigation-context'
import { useLocale, useSessionToken } from '../../app/session-context'
import { formatDate, statusLabel } from '../../i18n'
import {
  Button,
  EmptyState,
  InlineError,
  Page,
  PageHeader,
  Panel,
  Select,
  type SelectOption,
  SkeletonList,
  StatusBadge,
} from '../../ui'

interface TaskSummary {
  id: string
  title: string
  description?: string
  state: string
  priority: string
  assignee_user_id?: string
  due_at?: string
}

type RelationshipFilter = 'all' | generated.ListTasksRelationship
type StateFilter = 'all' | generated.ListTasksState

const copy = {
  ar: {
    pageTitle: 'المهام',
    pageDescription: 'المهام المسندة والمنشأة والمشترك فيها ضمن التجمع الصحي',
    createTask: 'إنشاء مهمة',
    relationshipLabel: 'علاقة المهمة',
    relationshipAll: 'الكل',
    relationshipAssigned: 'مسندة إليّ',
    relationshipCreated: 'أنشأتها',
    relationshipParticipating: 'أشارك فيها',
    stateLabel: 'الحالة',
    stateAll: 'كل الحالات',
    emptyTitle: 'لا توجد مهام',
    emptyBody: 'ابدأ بإنشاء مهمة جديدة أو غيّر عوامل التصفية.',
    loadMore: 'عرض المزيد',
    loading: 'جارٍ تحميل المهام…',
    error: 'تعذر تحميل المهام. يرجى إعادة المحاولة.',
    forbidden: 'غير مصرح لك بعرض المهام.',
    retry: 'إعادة المحاولة',
    open: 'فتح المهمة',
    priority: 'الأولوية',
    priorityLow: 'منخفضة',
    priorityNormal: 'عادية',
    priorityHigh: 'عالية',
    priorityUrgent: 'عاجلة',
    assignee: 'المسند إليه',
    dueAt: 'الاستحقاق',
  },
  en: {
    pageTitle: 'Tasks',
    pageDescription: 'Tasks assigned to, created by, or involving you within the health cluster',
    createTask: 'Create task',
    relationshipLabel: 'Task relationship',
    relationshipAll: 'All',
    relationshipAssigned: 'Assigned to me',
    relationshipCreated: 'Created by me',
    relationshipParticipating: 'I participate in',
    stateLabel: 'State',
    stateAll: 'All states',
    emptyTitle: 'No tasks',
    emptyBody: 'Create a new task or adjust the filters.',
    loadMore: 'Load more',
    loading: 'Loading tasks…',
    error: 'Could not load tasks. Please try again.',
    forbidden: 'You are not authorized to view tasks.',
    retry: 'Retry',
    open: 'Open task',
    priority: 'Priority',
    priorityLow: 'Low',
    priorityNormal: 'Normal',
    priorityHigh: 'High',
    priorityUrgent: 'Urgent',
    assignee: 'Assignee',
    dueAt: 'Due at',
  },
} as const

export function TasksScreen() {
  const locale = useLocale()
  const csrfToken = useSessionToken()
  const navigate = useNavigate()
  const t = copy[locale]

  const [relationship, setRelationship] = useState<RelationshipFilter>('all')
  const [state, setState] = useState<StateFilter>('all')
  const [items, setItems] = useState<TaskSummary[] | null>(null)
  const [nextCursor, setNextCursor] = useState<string | null>(null)
  const [screenState, setScreenState] = useState<ResourceState>('loading')
  const [loadingMore, setLoadingMore] = useState(false)
  const requestRef = useRef(0)

  const load = useCallback(async () => {
    const request = ++requestRef.current
    setScreenState('loading')
    try {
      const params: generated.ListTasksParams = { limit: 50 }
      if (relationship !== 'all') params.relationship = relationship
      if (state !== 'all') params.state = state
      const collection = unwrap<generated.EntityCollection>(
        await generated.listTasks(params, requestInit(csrfToken)),
      )
      if (request !== requestRef.current) return
      const page = collection.items as unknown as TaskSummary[]
      setItems(page)
      setNextCursor(collection.next_cursor)
      setScreenState(page.length === 0 ? 'empty' : 'ready')
    } catch (error) {
      if (request !== requestRef.current) return
      setItems(null)
      setNextCursor(null)
      setScreenState(stateFromError(error) === 'forbidden' ? 'forbidden' : 'error')
    }
  }, [csrfToken, relationship, state])

  useEffect(() => {
    void load()
  }, [load])

  const loadMore = useCallback(async () => {
    if (!nextCursor || loadingMore) return
    const request = ++requestRef.current
    setLoadingMore(true)
    try {
      const params: generated.ListTasksParams = { limit: 50, cursor: nextCursor }
      if (relationship !== 'all') params.relationship = relationship
      if (state !== 'all') params.state = state
      const collection = unwrap<generated.EntityCollection>(
        await generated.listTasks(params, requestInit(csrfToken)),
      )
      if (request !== requestRef.current) return
      const page = collection.items as unknown as TaskSummary[]
      setItems((current) => [...(current ?? []), ...page])
      setNextCursor(collection.next_cursor)
    } catch {
      // keep the current page; the load-more button remains for a retry
    } finally {
      if (request === requestRef.current) setLoadingMore(false)
    }
  }, [csrfToken, loadingMore, nextCursor, relationship, state])

  const relationshipOptions = useMemo<SelectOption[]>(
    () => [
      { value: 'all', label: t.relationshipAll },
      { value: 'assigned', label: t.relationshipAssigned },
      { value: 'created', label: t.relationshipCreated },
      { value: 'participating', label: t.relationshipParticipating },
    ],
    [t],
  )

  const stateOptions = useMemo<SelectOption[]>(
    () => [
      { value: 'all', label: t.stateAll },
      { value: 'open', label: statusLabel('open', locale) },
      { value: 'in_progress', label: statusLabel('in_progress', locale) },
      { value: 'blocked', label: statusLabel('blocked', locale) },
      { value: 'completed', label: statusLabel('completed', locale) },
      { value: 'cancelled', label: statusLabel('cancelled', locale) },
    ],
    [locale, t],
  )

  return (
    <Page aria-labelledby="tasks-heading">
      <PageHeader
        id="tasks-heading"
        title={t.pageTitle}
        description={t.pageDescription}
        actions={
          <Button variant="primary" onClick={() => navigate('/tasks/new')}>
            <Plus aria-hidden="true" /> {t.createTask}
          </Button>
        }
      />

      <Panel id="tasks-filters" title={t.stateLabel} level={2}>
        <div
          role="group"
          aria-label={t.stateLabel}
          className="ui-form-grid"
        >
          <Select
            id="tasks-relationship-filter"
            value={relationship}
            onChange={(value) => setRelationship(value as RelationshipFilter)}
            options={relationshipOptions}
            ariaLabel={t.relationshipLabel}
          />
          <Select
            id="tasks-state-filter"
            value={state}
            onChange={(value) => setState(value as StateFilter)}
            options={stateOptions}
            ariaLabel={t.stateLabel}
          />
        </div>
      </Panel>

      <Panel id="tasks-results" title={t.pageTitle} level={2}>
        {screenState === 'loading' ? (
          <SkeletonList />
        ) : screenState === 'forbidden' ? (
          <EmptyState title={t.forbidden} />
        ) : screenState === 'empty' || !items || items.length === 0 ? (
          <EmptyState
            icon={<Inbox aria-hidden="true" />}
            title={t.emptyTitle}
            body={t.emptyBody}
            action={
              <Button variant="secondary" onClick={() => navigate('/tasks/new')}>
                {t.createTask}
              </Button>
            }
          />
        ) : (
          <>
            <ul className="ui-list" aria-label={t.pageTitle}>
              {items.map((task) => (
                <li key={task.id}>
                  <TaskRow task={task} onOpen={() => navigate(`/tasks/${task.id}`)} />
                </li>
              ))}
            </ul>
            {nextCursor ? (
              <div className="ui-pagination">
                <Button variant="secondary" disabled={loadingMore} onClick={() => void loadMore()}>
                  {loadingMore ? t.loading : t.loadMore}
                </Button>
              </div>
            ) : null}
          </>
        )}
        {screenState === 'error' ? (
          <InlineError message={t.error} retryLabel={t.retry} onRetry={() => void load()} />
        ) : null}
      </Panel>
    </Page>
  )
}

function TaskRow({ task, onOpen }: { task: TaskSummary; onOpen: () => void }) {
  const locale = useLocale()
  const t = copy[locale]
  const variant =
    task.state === 'completed'
      ? 'success'
      : task.state === 'cancelled'
        ? 'danger'
        : task.state === 'blocked'
          ? 'warning'
          : task.state === 'in_progress'
            ? 'info'
            : 'neutral'
  const priorityLabel =
    task.priority === 'low'
      ? t.priorityLow
      : task.priority === 'high'
        ? t.priorityHigh
        : task.priority === 'urgent'
          ? t.priorityUrgent
          : t.priorityNormal
  return (
    <article className="ui-panel" aria-labelledby={`task-row-${task.id}`}>
      <header className="ui-panel__header">
        <div>
          <h3 id={`task-row-${task.id}`}>{task.title}</h3>
          {task.description ? <p>{task.description}</p> : null}
        </div>
        <Button variant="quiet" onClick={onOpen}>
          {t.open}
        </Button>
      </header>
      <dl className="detail-list">
        <div>
          <dt>{t.stateLabel}</dt>
          <dd>
            <StatusBadge variant={variant}>{statusLabel(task.state, locale)}</StatusBadge>
          </dd>
        </div>
        <div>
          <dt>{t.priority}</dt>
          <dd>{priorityLabel}</dd>
        </div>
        {task.assignee_user_id ? (
          <div>
            <dt>{t.assignee}</dt>
            <dd>{task.assignee_user_id}</dd>
          </div>
        ) : null}
        {task.due_at ? (
          <div>
            <dt>{t.dueAt}</dt>
            <dd>
              <time dateTime={task.due_at}>{formatDate(task.due_at, locale)}</time>
            </dd>
          </div>
        ) : null}
      </dl>
    </article>
  )
}

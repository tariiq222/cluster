// @vitest-environment jsdom
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Inbox, Plus } from 'lucide-react'

import type { Locale } from '../../app/copy'
import { directionForLocale } from '../../app/copy'
import type { Session } from '../../api'
import { ApiError } from '../../api'
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

import {
  listTasks,
  type Task,
  type TaskRelationship,
  type TaskState as TaskStateFilter,
} from '../../api/tasks'
import { tasksCopy, type TasksCopy } from './tasks-copy'

type RelationshipFilter = TaskRelationship
type StateFilter = 'all' | TaskStateFilter

export interface TaskListScreenProps {
  locale: Locale
  session: Session
  onNavigate?: (path: string) => void
}

export function TaskListScreen({ locale, session, onNavigate }: TaskListScreenProps) {
  const copy = tasksCopy[locale]
  const [relationship, setRelationship] = useState<RelationshipFilter>('all')
  const [state, setState] = useState<StateFilter>('all')
  const [tasks, setTasks] = useState<Task[] | null>(null)
  const [status, setStatus] = useState<'loading' | 'ready' | 'empty' | 'forbidden' | 'error'>('loading')
  const requestRef = useRef(0)

  const load = useCallback(async () => {
    const request = ++requestRef.current
    setStatus('loading')
    try {
      const params: { relationship?: TaskRelationship; state?: TaskStateFilter } = {}
      if (relationship !== 'all') params.relationship = relationship
      if (state !== 'all') params.state = state
      const result = await listTasks(session.access_token, params)
      if (request !== requestRef.current) return
      setTasks(result.items as unknown as Task[])
      setStatus(result.items.length === 0 ? 'empty' : 'ready')
    } catch (error) {
      if (request !== requestRef.current) return
      if (error instanceof ApiError && error.status === 403) setStatus('forbidden')
      else setStatus('error')
      setTasks(null)
    }
  }, [relationship, session.access_token, state])

  useEffect(() => {
    void load()
  }, [load])

  const relationshipOptions = useMemo<SelectOption[]>(() => [
    { value: 'all', label: copy.filterAll },
    { value: 'assigned', label: copy.filterAssigned },
    { value: 'created', label: copy.filterCreated },
    { value: 'participating', label: copy.filterParticipating },
  ], [copy.filterAll, copy.filterAssigned, copy.filterCreated, copy.filterParticipating])

  const stateOptions = useMemo<SelectOption[]>(() => [
    { value: 'all', label: copy.filterStateAll },
    { value: 'open', label: copy.filterStateOpen },
    { value: 'in_progress', label: copy.filterStateInProgress },
    { value: 'blocked', label: copy.filterStateBlocked },
    { value: 'completed', label: copy.filterStateCompleted },
    { value: 'cancelled', label: copy.filterStateCancelled },
  ], [copy.filterStateAll, copy.filterStateOpen, copy.filterStateInProgress, copy.filterStateBlocked, copy.filterStateCompleted, copy.filterStateCancelled])

  const navigateToTask = (taskId: string) => onNavigate?.(`/tasks/${taskId}`)
  const navigateToCreate = () => onNavigate?.('/tasks/new')

  return (
    <div dir={directionForLocale(locale)}>
      <Page aria-labelledby="task-list-heading">
        <PageHeader
          id="task-list-heading"
          title={copy.listTitle}
          description={copy.listDescription}
          actions={
            <Button variant="primary" onClick={navigateToCreate}>
              <Plus aria-hidden="true" /> {copy.createTask}
            </Button>
          }
        />

        <Panel id="task-list-filters" title={copy.filterAll} level={2}>
          <div role="group" aria-label={copy.filterRelationshipLabel} style={{ display: 'grid', gap: '1rem', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))' }}>
            <Select
              id="task-relationship-filter"
              value={relationship}
              onChange={(value) => setRelationship(value as RelationshipFilter)}
              options={relationshipOptions}
              ariaLabel={copy.filterRelationshipLabel}
            />
            <Select
              id="task-state-filter"
              value={state}
              onChange={(value) => setState(value as StateFilter)}
              options={stateOptions}
              ariaLabel={copy.filterStateAll}
            />
          </div>
        </Panel>

        <Panel id="task-list-results" title={copy.listTitle} level={2}>
          {status === 'loading' ? (
            <SkeletonList label={copy.loading} />
          ) : status === 'forbidden' ? (
            <InlineError message={copy.forbiddenBody} retryLabel={copy.retry} onRetry={() => void load()} />
          ) : status === 'error' ? (
            <InlineError message={copy.errorTitle} retryLabel={copy.retry} onRetry={() => void load()} />
          ) : status === 'empty' || !tasks || tasks.length === 0 ? (
            <EmptyState
              icon={<Inbox aria-hidden="true" />}
              title={copy.emptyTitle}
              body={copy.emptyBody}
              action={
                <Button variant="secondary" onClick={navigateToCreate}>
                  {copy.createTask}
                </Button>
              }
            />
          ) : (
            <ul aria-label={copy.listTitle} style={{ listStyle: 'none', padding: 0, margin: 0, display: 'grid', gap: '0.75rem' }}>
              {tasks.map((task) => (
                <li key={task.id}>
                  <TaskListItem task={task} locale={locale} copy={copy} onOpen={() => navigateToTask(task.id)} />
                </li>
              ))}
            </ul>
          )}
        </Panel>
      </Page>
    </div>
  )
}

function TaskListItem({ task, locale, copy, onOpen }: { task: Task; locale: Locale; copy: TasksCopy; onOpen: () => void }) {
  const stateLabel = task.state === 'open'
    ? copy.stateOpen
    : task.state === 'in_progress'
      ? copy.stateInProgress
      : task.state === 'blocked'
        ? copy.stateBlocked
        : task.state === 'completed'
          ? copy.stateCompleted
          : copy.stateCancelled
  const priorityLabel = task.priority === 'low'
    ? copy.priorityLow
    : task.priority === 'normal'
      ? copy.priorityNormal
      : task.priority === 'high'
        ? copy.priorityHigh
        : copy.priorityUrgent
  const variant = task.state === 'completed'
    ? 'success'
    : task.state === 'cancelled'
      ? 'danger'
      : task.state === 'blocked'
        ? 'warning'
        : 'info'
  const dueAt = task.due_at
  return (
    <article aria-labelledby={`task-${task.id}-title`} className="ui-panel">
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: '0.75rem' }}>
        <div>
          <h3 id={`task-${task.id}-title`}>{task.title}</h3>
          {task.description ? <p>{task.description}</p> : null}
        </div>
        <Button variant="quiet" onClick={onOpen}>{copy.actionOpen}</Button>
      </header>
      <dl style={{ display: 'grid', gap: '0.5rem', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', marginTop: '0.75rem' }}>
        <div><dt>{copy.detailState}</dt><dd><StatusBadge variant={variant}>{stateLabel}</StatusBadge></dd></div>
        <div><dt>{copy.detailPriority}</dt><dd>{priorityLabel}</dd></div>
        {task.assignee_user_id ? <div><dt>{copy.detailAssignee}</dt><dd>{task.assignee_user_id}</dd></div> : null}
        {dueAt ? <div><dt>{copy.detailDueAt}</dt><dd><time dateTime={dueAt}>{new Date(dueAt).toLocaleString(locale === 'ar' ? 'ar-SA' : 'en-GB')}</time></dd></div> : null}
      </dl>
    </article>
  )
}

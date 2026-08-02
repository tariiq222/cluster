import { useCallback, useMemo, useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowRight, Ban, CheckCircle2, Pencil, Play, RotateCcw, ShieldOff } from 'lucide-react'
import * as generated from '../../api/generated/cluster'
import { useTask, useTaskMutations } from '../../api/hooks'
import { ApiError, requestInit, stateFromError, unwrap, type ResourceState } from '../../api/http'
import { useNavigate } from '../../app/navigation-context'
import { useScreenHelp } from '../../app/screen-help'
import { useLocale, useSessionToken } from '../../app/session-context'
import { PageHeader, PageLayout } from '@/components/page-layout'
import { WorkspaceTabs, type WorkspaceTabItem } from '@/components/workspace-tabs'
import { ResourceBoundary } from '@/components/states'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { statusLabel } from '../../i18n'
import { TaskDialogs, type TaskDialog, type TransitionAction } from './TaskDialogs'
import { tasksCopy } from './tasks-copy'
import { TaskDetailsTab } from './tabs/TaskDetailsTab'
import { TaskCommentsTab, type TaskComment } from './tabs/TaskCommentsTab'
import { TaskParticipantsTab } from './tabs/TaskParticipantsTab'

const EMPTY_ACTIONS: string[] = []

export function TaskDetailScreen({ taskId }: { taskId: string }) {
  const locale = useLocale()
  const csrfToken = useSessionToken()
  const navigate = useNavigate()
  const t = tasksCopy[locale]
  const queryClient = useQueryClient()

  const [dialog, setDialog] = useState<TaskDialog | null>(null)
  const [actionError, setActionError] = useState<string | null>(null)
  const [actionCorrelationId, setActionCorrelationId] = useState<string | null>(
    null,
  )
  const [activeTab, setActiveTab] = useState('details')

  const taskQuery = useTask(taskId)
  const task = taskQuery.data as generated.Task | undefined
  const { update, transition, addComment, addParticipant } = useTaskMutations()

  const commentsQuery = useQuery({
    queryKey: ['task-comments', taskId],
    queryFn: async () =>
      unwrap<generated.EntityCollection>(await generated.listTaskComments(taskId, { limit: 50 }, requestInit(null))),
  })
  const comments = (commentsQuery.data?.items as unknown as TaskComment[]) ?? []

  const screenState: ResourceState = taskQuery.isError
    ? stateFromError(taskQuery.error)
    : taskQuery.isPending
      ? 'loading'
      : 'ready'

  const busy = update.isPending || transition.isPending || addComment.isPending || addParticipant.isPending

  const handleStale = useCallback((correlationId: string | null) => {
    void queryClient.invalidateQueries({ queryKey: ['task', taskId] })
    void queryClient.invalidateQueries({ queryKey: ['task-comments', taskId] })
    setActionError(t.stale)
    setActionCorrelationId(correlationId)
  }, [queryClient, t.stale, taskId])

  const transitionDraft = useCallback(
    async (action: TransitionAction, input: generated.TaskTransitionRequest | undefined) => {
      if (!task) return
      setActionError(null)
      setActionCorrelationId(null)
      try {
        await transition.mutateAsync({ taskId: task.id, action, input, lockVersion: task.lock_version })
        setDialog(null)
      } catch (error) {
        if (error instanceof ApiError && error.status === 412) {
          setDialog(null)
          handleStale(error.correlationId)
          return
        }
        setActionError(errorMessage(error, t.actionError))
        setActionCorrelationId(
          error instanceof ApiError ? error.correlationId : null,
        )
      }
    },
    [handleStale, t.actionError, task, transition],
  )

  const submitEdit = useCallback(
    async (patch: generated.TaskPatch) => {
      if (!task) return
      setActionError(null)
      setActionCorrelationId(null)
      try {
        await update.mutateAsync({ taskId: task.id, input: patch, lockVersion: task.lock_version })
        setDialog(null)
      } catch (error) {
        if (error instanceof ApiError && error.status === 412) {
          setDialog(null)
          handleStale(error.correlationId)
          return
        }
        setActionError(errorMessage(error, t.actionError))
        setActionCorrelationId(
          error instanceof ApiError ? error.correlationId : null,
        )
      }
    },
    [handleStale, t.actionError, task, update],
  )

  const submitAttach = useCallback(
    async (documentId: string) => {
      if (!task) return
      setActionError(null)
      setActionCorrelationId(null)
      try {
        unwrap<generated.Task>(
          await generated.attachTaskDocument(
            task.id,
            { document_id: documentId },
            requestInit(csrfToken, { command: true, idempotency: 'task-attach-document' }),
          ),
        )
        void queryClient.invalidateQueries({ queryKey: ['task', taskId] })
        setDialog(null)
      } catch (error) {
        setActionError(errorMessage(error, t.actionError))
        setActionCorrelationId(
          error instanceof ApiError ? error.correlationId : null,
        )
      }
    },
    [csrfToken, queryClient, t.actionError, task, taskId],
  )

  const handleDialogSubmit = useCallback(
    async (payload: import('./TaskDialogs').TaskDialogSubmit) => {
      if (payload.type === 'transition') {
        const input: generated.TaskTransitionRequest | undefined =
          payload.action === 'block' || payload.action === 'cancel'
            ? { reason: payload.reason ?? '' }
            : payload.action === 'complete'
              ? { note: payload.note ?? '' }
              : undefined
        if (
          (payload.action === 'block' || payload.action === 'cancel') && !payload.reason?.trim()
        ) {
          setActionError(t.reasonRequired)
          setActionCorrelationId(null)
          return
        }
        if (payload.action === 'complete' && !payload.note?.trim()) {
          setActionError(t.noteRequired)
          setActionCorrelationId(null)
          return
        }
        await transitionDraft(payload.action, input)
        return
      }
      if (payload.type === 'edit') {
        if (!payload.title) {
          setActionError(t.titleRequired)
          setActionCorrelationId(null)
          return
        }
        await submitEdit({
          title: payload.title,
          description: payload.description,
          priority: payload.priority as generated.TaskPatchPriority,
        })
        return
      }
      await submitAttach(payload.documentId)
    },
    [submitAttach, submitEdit, t.noteRequired, t.reasonRequired, t.titleRequired, transitionDraft],
  )

  const allowed = task?.allowed_actions ?? EMPTY_ACTIONS
  const can = (action: string) => allowed.some((item) => item === action)
  const screenHelp = useMemo(() => {
    const actionLabels: Record<string, string> = {
      start: t.actionStart,
      block: t.actionBlock,
      unblock: t.actionUnblock,
      complete: t.actionComplete,
      cancel: t.actionCancel,
      edit: t.actionEdit,
      comment: t.actionComment,
      'attach-document': t.actionAttach,
      'add-participant': t.addParticipant,
    }
    const permittedNextAction = task
      ? (allowed.map((action) => actionLabels[action]).find(Boolean) ??
        t.noActions)
      : undefined

    return {
      currentState: task ? statusLabel(task.state, locale) : undefined,
      activeSection:
        activeTab === 'comments'
          ? t.commentsTab
          : activeTab === 'participants'
            ? t.participantsTab
            : t.detailsTab,
      permittedNextAction,
      recoveryGuidance: actionError ? [t.helpRecovery] : undefined,
      correlationId:
        actionCorrelationId ??
        (taskQuery.error instanceof ApiError
          ? taskQuery.error.correlationId
          : null),
    }
  }, [
    actionCorrelationId,
    actionError,
    activeTab,
    allowed,
    locale,
    t,
    task,
    taskQuery.error,
  ])
  useScreenHelp(screenHelp)

  return (
    <PageLayout>
      <div>
        <Button variant="ghost" size="sm" onClick={() => navigate('/tasks')} className="-ms-2">
          <ArrowRight aria-hidden="true" />
          {t.back}
        </Button>
      </div>

      <ResourceBoundary state={screenState} locale={locale} rows={5}>
        {task ? (
          <>
            <PageHeader
              title={task.title}
              meta={<Badge variant="outline">{statusLabel(task.state, locale)}</Badge>}
              actions={
                <div className="flex flex-wrap gap-2">
                  {can('start') ? (
                    <Button size="sm" disabled={busy} onClick={() => void transitionDraft('start', undefined)}>
                      <Play aria-hidden="true" />
                      {t.actionStart}
                    </Button>
                  ) : null}
                  {can('block') ? (
                    <Button size="sm" variant="outline" disabled={busy} onClick={() => setDialog({ kind: 'transition', action: 'block' })}>
                      <ShieldOff aria-hidden="true" />
                      {t.actionBlock}
                    </Button>
                  ) : null}
                  {can('unblock') ? (
                    <Button size="sm" variant="outline" disabled={busy} onClick={() => void transitionDraft('unblock', undefined)}>
                      <RotateCcw aria-hidden="true" />
                      {t.actionUnblock}
                    </Button>
                  ) : null}
                  {can('complete') ? (
                    <Button size="sm" disabled={busy} onClick={() => setDialog({ kind: 'transition', action: 'complete' })}>
                      <CheckCircle2 aria-hidden="true" />
                      {t.actionComplete}
                    </Button>
                  ) : null}
                  {can('cancel') ? (
                    <Button size="sm" variant="outline" disabled={busy} onClick={() => setDialog({ kind: 'transition', action: 'cancel' })}>
                      <Ban aria-hidden="true" />
                      {t.actionCancel}
                    </Button>
                  ) : null}
                  {can('edit') ? (
                    <Button size="sm" variant="outline" disabled={busy} onClick={() => setDialog({ kind: 'edit' })}>
                      <Pencil aria-hidden="true" />
                      {t.actionEdit}
                    </Button>
                  ) : null}
                  {can('attach-document') ? (
                    <Button size="sm" variant="outline" disabled={busy} onClick={() => setDialog({ kind: 'attach-document' })}>
                      {t.actionAttach}
                    </Button>
                  ) : null}
                </div>
              }
            />

            {actionError ? (
              <p className="text-destructive text-sm" role="alert">{actionError}</p>
            ) : null}

            <WorkspaceTabs
              label={t.metaTitle}
              value={activeTab}
              onValueChange={setActiveTab}
              items={[
                { value: 'details', label: t.detailsTab, content: <TaskDetailsTab task={task} /> },
                {
                  value: 'comments',
                  label: t.commentsTab,
                  content: (
                    <TaskCommentsTab
                      comments={comments}
                      canComment={can('comment')}
                      onAddComment={async (body, mentionedUserIds) => {
                        await addComment.mutateAsync({
                          taskId: task.id,
                          input: { body, ...(mentionedUserIds.length > 0 ? { mentioned_user_ids: mentionedUserIds } : {}) },
                        })
                        void queryClient.invalidateQueries({ queryKey: ['task-comments', taskId] })
                      }}
                    />
                  ),
                },
                {
                  value: 'participants',
                  label: t.participantsTab,
                  content: (
                    <TaskParticipantsTab
                      participantUserIds={task.participant_user_ids ?? []}
                      canAddParticipant={can('add-participant')}
                      onAddParticipant={async (userId) => {
                        await addParticipant.mutateAsync({ taskId: task.id, input: { user_id: userId }, lockVersion: task.lock_version })
                        void queryClient.invalidateQueries({ queryKey: ['task', taskId] })
                      }}
                    />
                  ),
                },
              ] satisfies WorkspaceTabItem[]}
            />
          </>
        ) : null}
      </ResourceBoundary>

      <TaskDialogs
        dialog={dialog}
        busy={busy}
        task={task ?? null}
        onSubmit={handleDialogSubmit}
        onClose={() => {
          setDialog(null)
          setActionError(null)
          setActionCorrelationId(null)
        }}
      />
    </PageLayout>
  )
}

function errorMessage(error: unknown, fallback: string): string {
  if (error instanceof ApiError) {
    return error.problem.detail ?? error.problem.title ?? fallback
  }
  if (error instanceof Error && error.message) return error.message
  return fallback
}

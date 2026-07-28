// @vitest-environment jsdom
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import {
  AlertTriangle,
  Ban,
  CheckCircle2,
  FileText,
  MessageSquare,
  Paperclip,
  Pencil,
  Play,
  RotateCcw,
  ShieldOff,
  UserPlus,
  UserRoundPen,
  XCircle,
} from 'lucide-react'
import type { Locale } from '../../app/copy'
import { directionForLocale } from '../../app/copy'
import type { Session } from '../../api'
import { ApiError, stateFromError, type ResourceState } from '../../api'
import {
  addTaskComment,
  addTaskParticipant,
  attachTaskDocument,
  getTask,
  listTaskComments,
  transitionTask,
  updateTask,
  type CommentCreate,
  type ParticipantCreate,
  type Task,
  type TaskAction,
} from '../../api/tasks'
import {
  Button,
  Drawer,
  EmptyState,
  Field,
  InlineError,
  Page,
  PageHeader,
  Panel,
  SkeletonList,
  StatusBadge,
} from '../../ui'
import { tasksCopy, type TasksCopy } from './tasks-copy'

type DialogKind =
  | { kind: 'block' }
  | { kind: 'cancel' }
  | { kind: 'complete' }
  | { kind: 'reassign' }
  | { kind: 'add-participant' }
  | { kind: 'comment' }
  | { kind: 'attach-document' }
  | { kind: 'edit' }

type ActionFailure =
  | { scope: 'transition'; action: TaskAction; message: string }
  | { scope: 'update'; message: string }
  | { scope: 'reassign'; message: string }
  | { scope: 'add-participant'; message: string }
  | { scope: 'comment'; message: string }
  | { scope: 'attach-document'; message: string }
  | null

function formatDetail(value: unknown, fallback = '—'): string {
  if (typeof value === 'string' && value.trim().length > 0) return value
  if (typeof value === 'number' && Number.isFinite(value)) return String(value)
  return fallback
}

function formatDateTime(value: unknown, locale: Locale): string {
  if (typeof value !== 'string' && typeof value !== 'number') return '—'
  const timestamp = typeof value === 'number' ? value : Date.parse(value)
  if (!Number.isFinite(timestamp)) return '—'
  const date = new Date(timestamp)
  try {
    return new Intl.DateTimeFormat(locale === 'ar' ? 'ar-SA' : 'en-GB', {
      dateStyle: 'medium',
      timeStyle: 'short',
    }).format(date)
  } catch {
    return date.toISOString()
  }
}

function stateLabel(state: unknown, copy: TasksCopy): string {
  switch (state) {
    case 'open':
      return copy.stateOpen
    case 'in_progress':
      return copy.stateInProgress
    case 'blocked':
      return copy.stateBlocked
    case 'completed':
      return copy.stateCompleted
    case 'cancelled':
      return copy.stateCancelled
    default:
      return '—'
  }
}

function variantForStatus(state: unknown): 'neutral' | 'success' | 'warning' | 'danger' | 'info' {
  switch (state) {
    case 'completed':
      return 'success'
    case 'cancelled':
      return 'danger'
    case 'blocked':
      return 'warning'
    case 'in_progress':
      return 'info'
    default:
      return 'neutral'
  }
}

function priorityLabel(value: unknown, copy: TasksCopy): string {
  switch (value) {
    case 'low':
      return copy.priorityLow
    case 'normal':
      return copy.priorityNormal
    case 'high':
      return copy.priorityHigh
    case 'urgent':
      return copy.priorityUrgent
    default:
      return formatDetail(value)
  }
}

function mentionsFromBody(body: string): string[] {
  const out: string[] = []
  const re = /@([\p{L}\p{N}_-]+)/gu
  let match: RegExpExecArray | null
  while ((match = re.exec(body)) !== null) {
    out.push(match[1]!)
  }
  return Array.from(new Set(out))
}

function errorMessageFor(error: unknown, fallback: string): string {
  if (error instanceof ApiError) {
    return error.problem.detail ?? error.problem.title ?? fallback
  }
  if (error instanceof Error && error.message) return error.message
  return fallback
}

export function TaskDetail({
  locale,
  session,
  taskId,
  scopeReady,
  scopeEpoch,
  onNavigate,
}: {
  locale: Locale
  session: Session
  taskId: string
  scopeReady: boolean
  scopeEpoch: number
  onNavigate?: (path: string) => void
}) {
  const copy = tasksCopy[locale]
  const [state, setState] = useState<ResourceState>('loading')
  const [task, setTask] = useState<Task | null>(null)
  const [comments, setComments] = useState<Array<Record<string, unknown>>>([])
  const [dialog, setDialog] = useState<DialogKind | null>(null)
  const [actionError, setActionError] = useState<ActionFailure>(null)
  const [busy, setBusy] = useState(false)
  const [reasonDraft, setReasonDraft] = useState('')
  const [noteDraft, setNoteDraft] = useState('')
  const [reassignDraft, setReassignDraft] = useState('')
  const [participantDraft, setParticipantDraft] = useState('')
  const [commentDraft, setCommentDraft] = useState('')
  const [documentDraft, setDocumentDraft] = useState('')
  const requestRef = useRef(0)
  const scopeEpochRef = useRef(scopeEpoch)
  scopeEpochRef.current = scopeEpoch

  const load = useCallback(async () => {
    const request = ++requestRef.current
    setState('loading')
    setTask(null)
    setComments([])
    setActionError(null)
    if (!scopeReady) return
    try {
      const [value, commentList] = await Promise.all([
        getTask(session.access_token, taskId),
        listTaskComments(session.access_token, taskId),
      ])
      if (request !== requestRef.current) return
      setTask(value)
      setComments(commentList.items ?? [])
      setState('ready')
    } catch (error) {
      if (request !== requestRef.current) return
      setState(stateFromError(error))
    }
  }, [scopeReady, session.access_token, taskId])

  useEffect(() => {
    void load()
  }, [load, scopeEpoch])

  const transition = useCallback(
    async (action: TaskAction, body?: { reason?: string; note?: string }) => {
      if (!task) return
      const callEpoch = scopeEpochRef.current
      const lockVersion = task.lock_version ?? 1
      setBusy(true)
      setActionError(null)
      try {
        const updated = await transitionTask(
          session.access_token,
          task.id,
          action,
          body && (body.reason || body.note) ? body : undefined,
          lockVersion,
        )
        if (scopeEpochRef.current !== callEpoch) return
        setTask(updated)
        setState('ready')
        if (action === 'block' || action === 'cancel') setReasonDraft('')
        if (action === 'complete') setNoteDraft('')
        setDialog(null)
      } catch (error) {
        if (scopeEpochRef.current !== callEpoch) return
        if (error instanceof ApiError && error.status === 412) {
          if (body?.reason) setReasonDraft(body.reason)
          if (body?.note) setNoteDraft(body.note)
          setDialog(null)
          setActionError({ scope: 'transition', action, message: copy.apiError })
          await load()
          return
        }
        setActionError({
          scope: 'transition',
          action,
          message: errorMessageFor(error, copy.apiError),
        })
      } finally {
        if (scopeEpochRef.current === callEpoch) setBusy(false)
      }
    },
    [task, session.access_token, copy.apiError, load],
  )

  const submitReassign = useCallback(async () => {
    if (!task) return
    const trimmed = reassignDraft.trim()
    if (trimmed === '') {
      setActionError({ scope: 'reassign', message: copy.dialogReassignTargetRequired })
      return
    }
    const callEpoch = scopeEpochRef.current
    const lockVersion = task.lock_version ?? 1
    setBusy(true)
    setActionError(null)
    try {
      const updated = await updateTask(
        session.access_token,
        task.id,
        { assignee_user_id: trimmed },
        lockVersion,
      )
      if (scopeEpochRef.current !== callEpoch) return
      setTask(updated)
      setReassignDraft('')
      setDialog(null)
    } catch (error) {
      if (scopeEpochRef.current !== callEpoch) return
      if (error instanceof ApiError && error.status === 412) {
        setReassignDraft(trimmed)
        setDialog(null)
        setActionError({ scope: 'reassign', message: copy.apiError })
        await load()
        return
      }
      setActionError({ scope: 'reassign', message: errorMessageFor(error, copy.apiError) })
    } finally {
      if (scopeEpochRef.current === callEpoch) setBusy(false)
    }
  }, [task, reassignDraft, session.access_token, copy.dialogReassignTargetRequired, copy.apiError, load])

  const submitAddParticipant = useCallback(async () => {
    if (!task) return
    const trimmed = participantDraft.trim()
    if (trimmed === '') {
      setActionError({ scope: 'add-participant', message: copy.dialogReassignTargetRequired })
      return
    }
    const input: ParticipantCreate = { user_id: trimmed }
    const callEpoch = scopeEpochRef.current
    setBusy(true)
    setActionError(null)
    try {
      const updated = await addTaskParticipant(session.access_token, task.id, input)
      if (scopeEpochRef.current !== callEpoch) return
      setTask(updated)
      setParticipantDraft('')
      setDialog(null)
    } catch (error) {
      if (scopeEpochRef.current !== callEpoch) return
      setActionError({ scope: 'add-participant', message: errorMessageFor(error, copy.apiError) })
    } finally {
      if (scopeEpochRef.current === callEpoch) setBusy(false)
    }
  }, [task, participantDraft, session.access_token, copy.dialogReassignTargetRequired, copy.apiError])

  const submitComment = useCallback(async () => {
    if (!task) return
    const trimmed = commentDraft.trim()
    if (trimmed === '') {
      setActionError({ scope: 'comment', message: copy.validationError })
      return
    }
    const input: CommentCreate = { body: trimmed }
    const callEpoch = scopeEpochRef.current
    setBusy(true)
    setActionError(null)
    try {
      const created = await addTaskComment(session.access_token, task.id, input)
      if (scopeEpochRef.current !== callEpoch) return
      setComments((current) => [...current, created as unknown as Record<string, unknown>])
      setCommentDraft('')
      setDialog(null)
    } catch (error) {
      if (scopeEpochRef.current !== callEpoch) return
      setActionError({ scope: 'comment', message: errorMessageFor(error, copy.apiError) })
    } finally {
      if (scopeEpochRef.current === callEpoch) setBusy(false)
    }
  }, [task, commentDraft, session.access_token, copy.validationError, copy.apiError])

  const submitAttachDocument = useCallback(async () => {
    if (!task) return
    const trimmed = documentDraft.trim()
    if (trimmed === '') {
      setActionError({ scope: 'attach-document', message: copy.validationError })
      return
    }
    const callEpoch = scopeEpochRef.current
    setBusy(true)
    setActionError(null)
    try {
      const updated = await attachTaskDocument(session.access_token, task.id, trimmed)
      if (scopeEpochRef.current !== callEpoch) return
      setTask(updated)
      setDocumentDraft('')
      setDialog(null)
    } catch (error) {
      if (scopeEpochRef.current !== callEpoch) return
      setActionError({ scope: 'attach-document', message: errorMessageFor(error, copy.apiError) })
    } finally {
      if (scopeEpochRef.current === callEpoch) setBusy(false)
    }
  }, [task, documentDraft, session.access_token, copy.validationError, copy.apiError])

  const allowed = task?.allowed_actions ?? []
  const canStart = allowed.includes('start')
  const canBlock = allowed.includes('block')
  const canUnblock = allowed.includes('unblock')
  const canComplete = allowed.includes('complete')
  const canCancel = allowed.includes('cancel')
  const canEdit = allowed.includes('edit')
  const canReassign = allowed.includes('reassign')
  const canAddParticipant = allowed.includes('add-participant')
  const canComment = allowed.includes('comment')
  const canAttachDocument = allowed.includes('attach-document')

  const summary = useMemo(() => {
    if (!task) return null
    const total = comments.length
    const openMentions = new Set<string>()
    for (const comment of comments) {
      const body = typeof comment.body === 'string' ? comment.body : ''
      for (const handle of mentionsFromBody(body)) openMentions.add(handle)
    }
    return {
      commentsTotal: total,
      participantsCount: Array.isArray(task.participant_user_ids) ? task.participant_user_ids.length : 0,
      attachmentsCount: Array.isArray(task.attachments) ? task.attachments.length : 0,
      mentionCount: openMentions.size,
    }
  }, [task, comments])

  const noActions =
    !canStart && !canBlock && !canUnblock && !canComplete && !canCancel &&
    !canEdit && !canReassign && !canAddParticipant && !canComment && !canAttachDocument

  if (state === 'loading' && !task) {
    return (
      <div dir={directionForLocale(locale)}>
        <Page aria-labelledby="task-detail-heading">
          <PageHeader id="task-detail-heading" title={copy.detailTitle} actions={backAction(copy, onNavigate)} />
          <SkeletonList label={copy.loading} />
        </Page>
      </div>
    )
  }

  if (state === 'forbidden' && !task) {
    return (
      <div dir={directionForLocale(locale)}>
        <Page aria-labelledby="task-detail-heading">
          <PageHeader id="task-detail-heading" title={copy.detailTitle} actions={backAction(copy, onNavigate)} />
          <Panel id="task-denied" title={copy.detailForbidden} level={2}>
            <p>{copy.detailForbiddenBody}</p>
          </Panel>
        </Page>
      </div>
    )
  }

  if (state === 'not-found' && !task) {
    return (
      <div dir={directionForLocale(locale)}>
        <Page aria-labelledby="task-detail-heading">
          <PageHeader id="task-detail-heading" title={copy.detailTitle} actions={backAction(copy, onNavigate)} />
          <EmptyState
            icon={<XCircle aria-hidden="true" />}
            title={copy.detailNotFound}
            body={copy.detailNotFoundBody}
          />
        </Page>
      </div>
    )
  }

  if (state === 'error' && !task) {
    return (
      <div dir={directionForLocale(locale)}>
        <Page aria-labelledby="task-detail-heading">
          <PageHeader id="task-detail-heading" title={copy.detailTitle} actions={backAction(copy, onNavigate)} />
          <InlineError message={copy.apiError} retryLabel={copy.retry} onRetry={() => void load()} />
        </Page>
      </div>
    )
  }

  if (!task) {
    return (
      <div dir={directionForLocale(locale)}>
        <Page aria-labelledby="task-detail-heading">
          <PageHeader id="task-detail-heading" title={copy.detailTitle} actions={backAction(copy, onNavigate)} />
        </Page>
      </div>
    )
  }

  const headerTitle = task.title ?? task.id
  const statusLabel = stateLabel(task.state, copy)
  const assigneeLabel = formatDetail(task.assignee_user_id)
  const creatorLabel = formatDetail(task.creator_user_id)
  const dueLabel = formatDateTime(task.due_at, locale)
  const prioLabel = priorityLabel(task.priority, copy)
  const transitionFailure = actionError && actionError.scope === 'transition' ? actionError : null
  const genericFailure =
    actionError && actionError.scope !== 'transition' ? actionError : null

  return (
    <div dir={directionForLocale(locale)}>
      <Page aria-labelledby="task-detail-heading">
        <PageHeader
          id="task-detail-heading"
          title={copy.detailTitle}
          description={headerTitle}
          actions={backAction(copy, onNavigate)}
        />

        {state === 'loading' ? <SkeletonList label={copy.loading} /> : null}

        {state === 'stale' ? (
          <InlineError message={copy.apiError} retryLabel={copy.retry} onRetry={() => void load()} />
        ) : null}

        {transitionFailure ? (
          <InlineError message={transitionFailure.message} retryLabel={copy.retry} onRetry={() => setActionError(null)} />
        ) : null}

        <Panel id="task-detail-panel" title={copy.detailTitle} level={2}>
          <dl className="detail-list">
            <div>
              <dt>{copy.detailState}</dt>
              <dd>
                <StatusBadge variant={variantForStatus(task.state)}>{statusLabel}</StatusBadge>
              </dd>
            </div>
            <div>
              <dt>{copy.detailCreator}</dt>
              <dd>{creatorLabel}</dd>
            </div>
            <div>
              <dt>{copy.detailAssignee}</dt>
              <dd>{assigneeLabel}</dd>
            </div>
            <div>
              <dt>{copy.detailDueAt}</dt>
              <dd>{dueLabel}</dd>
            </div>
            <div>
              <dt>{copy.detailPriority}</dt>
              <dd>{prioLabel}</dd>
            </div>
            <div>
              <dt>{copy.detailDescription}</dt>
              <dd>{task.description ?? copy.detailNoDescription}</dd>
            </div>
          </dl>
        </Panel>

        <Panel id="task-actions-panel" title={copy.detailAllowedActions} level={2}>
          <ul className="server-actions" aria-label={copy.detailAllowedActions}>
            {canStart ? (
              <li>
                <Button disabled={busy} onClick={() => void transition('start')}>
                  <Play aria-hidden="true" /> {busy ? copy.retryLoading : copy.actionStart}
                </Button>
              </li>
            ) : null}
            {canBlock ? (
              <li>
                <Button variant="secondary" disabled={busy} onClick={() => openDialog(setActionError, setDialog, { kind: 'block' })}>
                  <ShieldOff aria-hidden="true" /> {copy.actionBlock}
                </Button>
              </li>
            ) : null}
            {canUnblock ? (
              <li>
                <Button variant="secondary" disabled={busy} onClick={() => void transition('unblock')}>
                  <RotateCcw aria-hidden="true" /> {busy ? copy.retryLoading : copy.actionUnblock}
                </Button>
              </li>
            ) : null}
            {canComplete ? (
              <li>
                <Button disabled={busy} onClick={() => openDialog(setActionError, setDialog, { kind: 'complete' })}>
                  <CheckCircle2 aria-hidden="true" /> {copy.actionComplete}
                </Button>
              </li>
            ) : null}
            {canCancel ? (
              <li>
                <Button variant="secondary" disabled={busy} onClick={() => openDialog(setActionError, setDialog, { kind: 'cancel' })}>
                  <Ban aria-hidden="true" /> {copy.actionCancel}
                </Button>
              </li>
            ) : null}
            {canEdit ? (
              <li>
                <Button variant="secondary" disabled={busy} onClick={() => openDialog(setActionError, setDialog, { kind: 'edit' })}>
                  <Pencil aria-hidden="true" /> {copy.actionEdit}
                </Button>
              </li>
            ) : null}
            {canReassign ? (
              <li>
                <Button variant="secondary" disabled={busy} onClick={() => openDialog(setActionError, setDialog, { kind: 'reassign' })}>
                  <UserRoundPen aria-hidden="true" /> {copy.actionReassign}
                </Button>
              </li>
            ) : null}
            {canAddParticipant ? (
              <li>
                <Button variant="secondary" disabled={busy} onClick={() => openDialog(setActionError, setDialog, { kind: 'add-participant' })}>
                  <UserPlus aria-hidden="true" /> {copy.actionAddParticipant}
                </Button>
              </li>
            ) : null}
            {canComment ? (
              <li>
                <Button variant="secondary" disabled={busy} onClick={() => openDialog(setActionError, setDialog, { kind: 'comment' })}>
                  <MessageSquare aria-hidden="true" /> {copy.actionComment}
                </Button>
              </li>
            ) : null}
            {canAttachDocument ? (
              <li>
                <Button variant="secondary" disabled={busy} onClick={() => openDialog(setActionError, setDialog, { kind: 'attach-document' })}>
                  <Paperclip aria-hidden="true" /> {copy.actionAttachDocument}
                </Button>
              </li>
            ) : null}
            {noActions ? (
              <li className="server-actions-empty">{copy.detailNoAttachments}</li>
            ) : null}
          </ul>
        </Panel>

        {summary ? (
          <Panel id="task-summary-panel" title={copy.detailTitle} level={2}>
            <dl className="detail-list">
              <div>
                <dt>{copy.detailAddComment}</dt>
                <dd>{summary.commentsTotal}</dd>
              </div>
              <div>
                <dt>{copy.detailParticipants}</dt>
                <dd>{summary.participantsCount}</dd>
              </div>
              <div>
                <dt>{copy.detailAttachments}</dt>
                <dd>{summary.attachmentsCount}</dd>
              </div>
              <div>
                <dt>{copy.columnAllowedActions}</dt>
                <dd>{summary.mentionCount}</dd>
              </div>
            </dl>
          </Panel>
        ) : null}

        <Panel id="task-participants-panel" title={copy.detailParticipants} level={2}>
          {Array.isArray(task.participant_user_ids) && task.participant_user_ids.length > 0 ? (
            <ul>
              {task.participant_user_ids.map((handle) => (
                <li key={String(handle)}>{String(handle)}</li>
              ))}
            </ul>
          ) : (
            <EmptyState icon={<UserPlus aria-hidden="true" />} title={copy.detailNoAttachments} />
          )}
        </Panel>

        <Panel id="task-attachments-panel" title={copy.detailAttachments} level={2}>
          {Array.isArray(task.attachments) && task.attachments.length > 0 ? (
            <ul>
              {task.attachments.map((attachment, index) => (
                <li key={String(attachment.document_id ?? index)}>
                  <FileText aria-hidden="true" /> {formatDetail(attachment.title, String(attachment.document_id))}
                </li>
              ))}
            </ul>
          ) : (
            <EmptyState icon={<Paperclip aria-hidden="true" />} title={copy.detailNoAttachments} />
          )}
        </Panel>

        <Panel id="task-comments-panel" title={copy.detailAddComment} level={2}>
          {comments.length > 0 ? (
            <ul>
              {comments.map((comment, index) => {
                const body = typeof comment.body === 'string' ? comment.body : ''
                const author = typeof comment.author_user_id === 'string' ? comment.author_user_id : ''
                const mentions = mentionsFromBody(body)
                return (
                  <li key={String(comment.id ?? index)}>
                    <strong>{author || '—'}</strong>
                    <span>{body}</span>
                    {mentions.length > 0 ? (
                      <small>
                        {copy.detailDescription}: {mentions.map((handle) => `@${handle}`).join(', ')}
                      </small>
                    ) : null}
                  </li>
                )
              })}
            </ul>
          ) : (
            <EmptyState icon={<MessageSquare aria-hidden="true" />} title={copy.detailNoComments} />
          )}
        </Panel>

        {genericFailure ? (
          <InlineError
            message={genericFailure.message}
            retryLabel={copy.retry}
            onRetry={() => setActionError(null)}
          />
        ) : null}
      </Page>

      {renderDialog({
        dialog,
        copy,
        busy,
        reasonDraft,
        noteDraft,
        reassignDraft,
        participantDraft,
        commentDraft,
        documentDraft,
        setReasonDraft,
        setNoteDraft,
        setReassignDraft,
        setParticipantDraft,
        setCommentDraft,
        setDocumentDraft,
        onClose: () => {
          setDialog(null)
          setActionError(null)
        },
        onSubmitBlock: () => {
          const trimmed = reasonDraft.trim()
          if (trimmed === '') {
            setActionError({ scope: 'transition', action: 'block', message: copy.dialogBlockReasonRequired })
            return
          }
          void transition('block', { reason: trimmed })
        },
        onSubmitCancel: () => {
          const trimmed = reasonDraft.trim()
          if (trimmed === '') {
            setActionError({ scope: 'transition', action: 'cancel', message: copy.dialogCancelReasonRequired })
            return
          }
          void transition('cancel', { reason: trimmed })
        },
        onSubmitComplete: () => {
          const trimmed = noteDraft.trim()
          if (trimmed === '') {
            setActionError({ scope: 'transition', action: 'complete', message: copy.dialogCompleteNoteRequired })
            return
          }
          void transition('complete', { note: trimmed })
        },
        onSubmitReassign: () => void submitReassign(),
        onSubmitAddParticipant: () => void submitAddParticipant(),
        onSubmitComment: () => void submitComment(),
        onSubmitAttachDocument: () => void submitAttachDocument(),
        onSubmitEdit: () => {
          setDialog(null)
        },
      })}
    </div>
  )
}

function backAction(copy: TasksCopy, onNavigate?: (path: string) => void) {
  return (
    <Button variant="secondary" onClick={() => onNavigate?.('/tasks')}>
      {copy.listTitle}
    </Button>
  )
}

function openDialog(
  reset: (value: ActionFailure) => void,
  setDialog: (value: DialogKind | null) => void,
  next: DialogKind,
): void {
  reset(null)
  setDialog(next)
}

type DialogProps = {
  dialog: DialogKind | null
  copy: TasksCopy
  busy: boolean
  reasonDraft: string
  noteDraft: string
  reassignDraft: string
  participantDraft: string
  commentDraft: string
  documentDraft: string
  setReasonDraft: (value: string) => void
  setNoteDraft: (value: string) => void
  setReassignDraft: (value: string) => void
  setParticipantDraft: (value: string) => void
  setCommentDraft: (value: string) => void
  setDocumentDraft: (value: string) => void
  onClose: () => void
  onSubmitBlock: () => void
  onSubmitCancel: () => void
  onSubmitComplete: () => void
  onSubmitReassign: () => void
  onSubmitAddParticipant: () => void
  onSubmitComment: () => void
  onSubmitAttachDocument: () => void
  onSubmitEdit: () => void
}

function renderDialog(props: DialogProps) {
  const { dialog, copy, busy, onClose } = props
  if (!dialog) return null
  switch (dialog.kind) {
    case 'block':
      return (
        <Drawer open onClose={onClose} title={copy.dialogBlockTitle} ariaLabelClose={copy.dialogCancel}>
          <p>{copy.dialogBlockDescription}</p>
          <Field id="task-block-reason" label={copy.dialogBlockReasonLabel} help={copy.dialogBlockReasonPlaceholder} required>
            <textarea
              id="task-block-reason"
              value={props.reasonDraft}
              onChange={(event) => props.setReasonDraft(event.target.value)}
              disabled={busy}
              aria-required="true"
            />
          </Field>
          <div className="dialog-actions">
            <Button variant="secondary" onClick={onClose} disabled={busy}>
              {copy.dialogCancel}
            </Button>
            <Button disabled={busy} onClick={props.onSubmitBlock}>
              {busy ? copy.retryLoading : copy.dialogBlockConfirm}
            </Button>
          </div>
        </Drawer>
      )
    case 'cancel':
      return (
        <Drawer open onClose={onClose} title={copy.dialogCancelTitle} ariaLabelClose={copy.dialogCancel}>
          <p>{copy.dialogCancelDescription}</p>
          <Field id="task-cancel-reason" label={copy.dialogCancelReasonLabel} help={copy.dialogCancelReasonPlaceholder} required>
            <textarea
              id="task-cancel-reason"
              value={props.reasonDraft}
              onChange={(event) => props.setReasonDraft(event.target.value)}
              disabled={busy}
              aria-required="true"
            />
          </Field>
          <div className="dialog-actions">
            <Button variant="secondary" onClick={onClose} disabled={busy}>
              {copy.dialogCancel}
            </Button>
            <Button disabled={busy} onClick={props.onSubmitCancel}>
              {busy ? copy.retryLoading : copy.dialogCancelConfirm}
            </Button>
          </div>
        </Drawer>
      )
    case 'complete':
      return (
        <Drawer open onClose={onClose} title={copy.dialogCompleteTitle} ariaLabelClose={copy.dialogCancel}>
          <p>{copy.dialogCompleteDescription}</p>
          <Field id="task-complete-note" label={copy.dialogCompleteNoteLabel} help={copy.dialogCompleteNotePlaceholder} required>
            <textarea
              id="task-complete-note"
              value={props.noteDraft}
              onChange={(event) => props.setNoteDraft(event.target.value)}
              disabled={busy}
              aria-required="true"
            />
          </Field>
          <div className="dialog-actions">
            <Button variant="secondary" onClick={onClose} disabled={busy}>
              {copy.dialogCancel}
            </Button>
            <Button disabled={busy} onClick={props.onSubmitComplete}>
              {busy ? copy.retryLoading : copy.dialogCompleteConfirm}
            </Button>
          </div>
        </Drawer>
      )
    case 'reassign':
      return (
        <Drawer open onClose={onClose} title={copy.dialogReassignTitle} ariaLabelClose={copy.dialogCancel}>
          <p>{copy.dialogReassignDescription}</p>
          <Field id="task-reassign-target" label={copy.dialogReassignTargetLabel} help={copy.dialogReassignTargetPlaceholder} required>
            <input
              id="task-reassign-target"
              value={props.reassignDraft}
              onChange={(event) => props.setReassignDraft(event.target.value)}
              disabled={busy}
              aria-required="true"
            />
          </Field>
          <div className="dialog-actions">
            <Button variant="secondary" onClick={onClose} disabled={busy}>
              {copy.dialogCancel}
            </Button>
            <Button disabled={busy} onClick={props.onSubmitReassign}>
              {busy ? copy.retryLoading : copy.dialogReassignConfirm}
            </Button>
          </div>
        </Drawer>
      )
    case 'add-participant':
      return (
        <Drawer open onClose={onClose} title={copy.detailAddParticipant} ariaLabelClose={copy.dialogCancel}>
          <Field id="task-add-participant" label={copy.dialogReassignTargetLabel} help={copy.dialogReassignTargetPlaceholder} required>
            <input
              id="task-add-participant"
              value={props.participantDraft}
              onChange={(event) => props.setParticipantDraft(event.target.value)}
              disabled={busy}
              aria-required="true"
            />
          </Field>
          <div className="dialog-actions">
            <Button variant="secondary" onClick={onClose} disabled={busy}>
              {copy.dialogCancel}
            </Button>
            <Button variant="quiet" disabled={busy} onClick={props.onSubmitAddParticipant}>
              {busy ? copy.retryLoading : copy.dialogSubmit}
            </Button>
          </div>
        </Drawer>
      )
    case 'comment':
      return (
        <Drawer open onClose={onClose} title={copy.detailAddComment} ariaLabelClose={copy.dialogCancel}>
          <Field id="task-add-comment" label={copy.detailAddComment} help={copy.detailCommentPlaceholder} required>
            <textarea
              id="task-add-comment"
              value={props.commentDraft}
              onChange={(event) => props.setCommentDraft(event.target.value)}
              disabled={busy}
              aria-required="true"
            />
          </Field>
          <div className="dialog-actions">
            <Button variant="secondary" onClick={onClose} disabled={busy}>
              {copy.dialogCancel}
            </Button>
            <Button variant="quiet" disabled={busy} onClick={props.onSubmitComment}>
              {busy ? copy.retryLoading : copy.dialogSubmit}
            </Button>
          </div>
        </Drawer>
      )
    case 'attach-document':
      return (
        <Drawer open onClose={onClose} title={copy.detailAttachDocument} ariaLabelClose={copy.dialogCancel}>
          <Field id="task-attach-document" label={copy.detailAttachDocument} help={copy.detailAttachDocument} required>
            <input
              id="task-attach-document"
              value={props.documentDraft}
              onChange={(event) => props.setDocumentDraft(event.target.value)}
              disabled={busy}
              aria-required="true"
            />
          </Field>
          <div className="dialog-actions">
            <Button variant="secondary" onClick={onClose} disabled={busy}>
              {copy.dialogCancel}
            </Button>
            <Button variant="quiet" disabled={busy} onClick={props.onSubmitAttachDocument}>
              {busy ? copy.retryLoading : copy.dialogSubmit}
            </Button>
          </div>
        </Drawer>
      )
    case 'edit':
      return (
        <Drawer open onClose={onClose} title={copy.actionEdit} ariaLabelClose={copy.dialogCancel}>
          <p>
            <AlertTriangle aria-hidden="true" /> {copy.dialogCancelDescription}
          </p>
          <div className="dialog-actions">
            <Button variant="secondary" onClick={onClose} disabled={busy}>
              {copy.dialogCancel}
            </Button>
            <Button disabled={busy} onClick={props.onSubmitEdit}>
              {copy.actionEdit}
            </Button>
          </div>
        </Drawer>
      )
    default:
      return null
  }
}
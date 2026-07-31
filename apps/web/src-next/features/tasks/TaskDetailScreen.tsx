import { useCallback, useEffect, useRef, useState } from 'react'
import { Ban, CheckCircle2, FileText, MessageSquare, Paperclip, Pencil, Play, RotateCcw, ShieldOff, XCircle } from 'lucide-react'
import * as generated from '../../../src/api/generated/cluster'
import { ApiError, requestInit, stateFromError, unwrap, type ResourceState } from '../../api/http'
import { useNavigate } from '../../app/navigation-context'
import { useLocale, useSessionToken } from '../../app/session-context'
import { formatDate, statusLabel } from '../../i18n'
import {
  Button,
  Drawer,
  EmptyState,
  Field,
  InlineError,
  Page,
  PageHeader,
  Panel,
  Select,
  type SelectOption,
  SkeletonList,
  StatusBadge,
} from '../../ui'

type TransitionAction = 'start' | 'block' | 'unblock' | 'complete' | 'cancel'

type DialogKind =
  | { kind: 'transition'; action: TransitionAction }
  | { kind: 'edit' }
  | { kind: 'comment' }
  | { kind: 'attach-document' }

interface TaskComment {
  id: string
  body: string
  author_user_id: string
  created_at: string
}

const copy = {
  ar: {
    pageTitle: 'المهام',
    back: 'عودة إلى المهام',
    loading: 'جارٍ تحميل المهمة…',
    notFound: 'المهمة غير موجودة أو لا يمكن الوصول إليها.',
    forbidden: 'غير مصرح لك بعرض هذه المهمة.',
    error: 'تعذر تحميل المهمة. يرجى إعادة المحاولة.',
    retry: 'إعادة المحاولة',
    metaTitle: 'تفاصيل المهمة',
    actionsTitle: 'الإجراءات المتاحة',
    state: 'الحالة',
    priority: 'الأولوية',
    priorityLow: 'منخفضة',
    priorityNormal: 'عادية',
    priorityHigh: 'عالية',
    priorityUrgent: 'عاجلة',
    classification: 'التصنيف',
    classificationPublic: 'عام',
    classificationInternal: 'داخلي',
    classificationConfidential: 'سري',
    classificationTopSecret: 'سري للغاية',
    assignee: 'المسند إليه',
    creator: 'المنشئ',
    dueAt: 'الاستحقاق',
    createdAt: 'تاريخ الإنشاء',
    updatedAt: 'آخر تحديث',
    description: 'الوصف',
    noDescription: 'لا يوجد وصف',
    participants: 'المشاركون',
    noParticipants: 'لا يوجد مشاركون',
    attachments: 'المستندات المرفقة',
    noAttachments: 'لا توجد مستندات مرفقة',
    comments: 'التعليقات',
    noComments: 'لا توجد تعليقات',
    actionStart: 'بدء',
    actionBlock: 'حظر',
    actionUnblock: 'رفع الحظر',
    actionComplete: 'إكمال',
    actionCancel: 'إلغاء',
    actionEdit: 'تعديل',
    actionComment: 'إضافة تعليق',
    actionAttach: 'إرفاق مستند',
    noActions: 'لا توجد إجراءات متاحة حالياً',
    dialogBlockTitle: 'حظر المهمة',
    dialogBlockDescription: 'يرجى توضيح سبب الحظر.',
    dialogCancelTitle: 'إلغاء المهمة',
    dialogCancelDescription: 'يرجى توضيح سبب الإلغاء.',
    dialogCompleteTitle: 'إكمال المهمة',
    dialogCompleteDescription: 'أضف ملاحظة حول إنجاز المهمة.',
    dialogReasonLabel: 'السبب',
    dialogReasonPlaceholder: 'اذكر سبباً واضحاً…',
    dialogNoteLabel: 'الملاحظة',
    dialogNotePlaceholder: 'ملاحظة الإكمال…',
    reasonRequired: 'السبب مطلوب.',
    noteRequired: 'الملاحظة مطلوبة.',
    editTitle: 'تعديل المهمة',
    editTitleLabel: 'العنوان',
    editDescriptionLabel: 'الوصف',
    editPriorityLabel: 'الأولوية',
    titleRequired: 'العنوان مطلوب.',
    titleTooLong: 'يجب ألا يتجاوز العنوان 255 حرفاً.',
    descriptionTooLong: 'يجب ألا يتجاوز الوصف 4000 حرف.',
    commentTitle: 'إضافة تعليق',
    commentBodyLabel: 'نص التعليق',
    commentBodyPlaceholder: 'اكتب تعليقك…',
    commentRequired: 'نص التعليق مطلوب.',
    mentionLabel: 'معرّفات المستخدمين المذكورين',
    mentionPlaceholder: 'معرّفات مفصولة بفواصل (اختياري)',
    attachTitle: 'إرفاق مستند',
    attachDocumentIdLabel: 'معرّف المستند',
    attachDocumentIdPlaceholder: 'أدخل معرّف المستند',
    documentIdRequired: 'معرّف المستند مطلوب.',
    confirm: 'تأكيد',
    cancel: 'إلغاء',
    save: 'حفظ',
    stale: 'تعارض في الإصدار: تم تحديث المهمة من جهة أخرى، تم إعادة تحميلها.',
    actionError: 'تعذر تنفيذ الإجراء. يرجى إعادة المحاولة.',
    close: 'إغلاق',
  },
  en: {
    pageTitle: 'Tasks',
    back: 'Back to tasks',
    loading: 'Loading task…',
    notFound: 'Task not found or not accessible.',
    forbidden: 'You are not authorized to view this task.',
    error: 'Could not load the task. Please try again.',
    retry: 'Retry',
    metaTitle: 'Task details',
    actionsTitle: 'Available actions',
    state: 'State',
    priority: 'Priority',
    priorityLow: 'Low',
    priorityNormal: 'Normal',
    priorityHigh: 'High',
    priorityUrgent: 'Urgent',
    classification: 'Classification',
    classificationPublic: 'Public',
    classificationInternal: 'Internal',
    classificationConfidential: 'Confidential',
    classificationTopSecret: 'Top secret',
    assignee: 'Assignee',
    creator: 'Creator',
    dueAt: 'Due at',
    createdAt: 'Created at',
    updatedAt: 'Updated at',
    description: 'Description',
    noDescription: 'No description',
    participants: 'Participants',
    noParticipants: 'No participants',
    attachments: 'Attached documents',
    noAttachments: 'No attached documents',
    comments: 'Comments',
    noComments: 'No comments',
    actionStart: 'Start',
    actionBlock: 'Block',
    actionUnblock: 'Unblock',
    actionComplete: 'Complete',
    actionCancel: 'Cancel',
    actionEdit: 'Edit',
    actionComment: 'Add comment',
    actionAttach: 'Attach document',
    noActions: 'No actions are currently available',
    dialogBlockTitle: 'Block task',
    dialogBlockDescription: 'Please explain why the task is blocked.',
    dialogCancelTitle: 'Cancel task',
    dialogCancelDescription: 'Please explain why the task is being cancelled.',
    dialogCompleteTitle: 'Complete task',
    dialogCompleteDescription: 'Add a note about completing the task.',
    dialogReasonLabel: 'Reason',
    dialogReasonPlaceholder: 'Provide a clear reason…',
    dialogNoteLabel: 'Note',
    dialogNotePlaceholder: 'Completion note…',
    reasonRequired: 'A reason is required.',
    noteRequired: 'A note is required.',
    editTitle: 'Edit task',
    editTitleLabel: 'Title',
    editDescriptionLabel: 'Description',
    editPriorityLabel: 'Priority',
    titleRequired: 'A title is required.',
    titleTooLong: 'The title must be at most 255 characters.',
    descriptionTooLong: 'The description must be at most 4000 characters.',
    commentTitle: 'Add comment',
    commentBodyLabel: 'Comment body',
    commentBodyPlaceholder: 'Write your comment…',
    commentRequired: 'A comment body is required.',
    mentionLabel: 'Mentioned user ids',
    mentionPlaceholder: 'Comma-separated ids (optional)',
    attachTitle: 'Attach document',
    attachDocumentIdLabel: 'Document id',
    attachDocumentIdPlaceholder: 'Enter the document id',
    documentIdRequired: 'A document id is required.',
    confirm: 'Confirm',
    cancel: 'Cancel',
    save: 'Save',
    stale: 'Version conflict: the task was updated elsewhere, it has been reloaded.',
    actionError: 'Could not perform the action. Please try again.',
    close: 'Close',
  },
} as const

type TasksCopy = (typeof copy)[keyof typeof copy]

export function TaskDetailScreen({ taskId }: { taskId: string }) {
  const locale = useLocale()
  const csrfToken = useSessionToken()
  const navigate = useNavigate()
  const t = copy[locale]

  const [state, setState] = useState<ResourceState>('loading')
  const [task, setTask] = useState<generated.Task | null>(null)
  const [comments, setComments] = useState<TaskComment[]>([])
  const [dialog, setDialog] = useState<DialogKind | null>(null)
  const [actionError, setActionError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [reasonDraft, setReasonDraft] = useState('')
  const [noteDraft, setNoteDraft] = useState('')
  const [editTitle, setEditTitle] = useState('')
  const [editDescription, setEditDescription] = useState('')
  const [editPriority, setEditPriority] = useState('normal')
  const [commentDraft, setCommentDraft] = useState('')
  const [mentionsDraft, setMentionsDraft] = useState('')
  const [documentDraft, setDocumentDraft] = useState('')
  const requestRef = useRef(0)

  const load = useCallback(async () => {
    const request = ++requestRef.current
    setState('loading')
    setActionError(null)
    try {
      const [taskValue, commentList] = await Promise.all([
        unwrap<generated.Task>(await generated.getTask(taskId, requestInit(csrfToken))),
        unwrap<generated.EntityCollection>(
          await generated.listTaskComments(taskId, { limit: 50 }, requestInit(csrfToken)),
        ),
      ])
      if (request !== requestRef.current) return
      setTask(taskValue)
      setComments(commentList.items as unknown as TaskComment[])
      setState('ready')
    } catch (error) {
      if (request !== requestRef.current) return
      setTask(null)
      setComments([])
      setState(stateFromError(error))
    }
  }, [csrfToken, taskId])

  useEffect(() => {
    void load()
  }, [load])

  const runTransition = useCallback(
    async (action: TransitionAction, body: generated.TaskTransitionRequest | undefined) => {
      if (!task) return
      const lockVersion = task.lock_version
      setBusy(true)
      setActionError(null)
      try {
        const updated = unwrap<generated.Task>(
          await generated.transitionTask(
            taskId,
            action,
            body,
            requestInit(csrfToken, { command: true, idempotency: `task-${action}`, lockVersion }),
          ),
        )
        setTask(updated)
        setDialog(null)
        setReasonDraft('')
        setNoteDraft('')
      } catch (error) {
        if (error instanceof ApiError && error.status === 412) {
          setDialog(null)
          await load()
          setActionError(t.stale)
          return
        }
        setActionError(errorMessage(error, t.actionError))
      } finally {
        setBusy(false)
      }
    },
    [csrfToken, load, t.actionError, t.stale, task, taskId],
  )

  const submitTransition = useCallback(
    async (action: TransitionAction) => {
      if (action === 'block' || action === 'cancel') {
        const reason = reasonDraft.trim()
        if (!reason) {
          setActionError(t.reasonRequired)
          return
        }
        await runTransition(action, { reason })
        return
      }
      if (action === 'complete') {
        const note = noteDraft.trim()
        if (!note) {
          setActionError(t.noteRequired)
          return
        }
        await runTransition(action, { note })
        return
      }
      await runTransition(action, undefined)
    },
    [noteDraft, reasonDraft, runTransition, t.noteRequired, t.reasonRequired],
  )

  const openEdit = useCallback(() => {
    if (!task) return
    setEditTitle(task.title)
    setEditDescription(task.description ?? '')
    setEditPriority(task.priority)
    setActionError(null)
    setDialog({ kind: 'edit' })
  }, [task])

  const submitEdit = useCallback(async () => {
    if (!task) return
    const title = editTitle.trim()
    if (!title) {
      setActionError(t.titleRequired)
      return
    }
    if (title.length > 255) {
      setActionError(t.titleTooLong)
      return
    }
    if (editDescription.length > 4000) {
      setActionError(t.descriptionTooLong)
      return
    }
    const patch: generated.TaskPatch = {
      title,
      description: editDescription,
      priority: editPriority as generated.TaskPatchPriority,
    }
    setBusy(true)
    setActionError(null)
    try {
      const updated = unwrap<generated.Task>(
        await generated.updateTask(
          task.id,
          patch,
          requestInit(csrfToken, { mutation: true, lockVersion: task.lock_version }),
        ),
      )
      setTask(updated)
      setDialog(null)
    } catch (error) {
      if (error instanceof ApiError && error.status === 412) {
        setDialog(null)
        await load()
        setActionError(t.stale)
        return
      }
      setActionError(errorMessage(error, t.actionError))
    } finally {
      setBusy(false)
    }
  }, [csrfToken, editDescription, editPriority, editTitle, load, t.actionError, t.descriptionTooLong, t.stale, t.titleRequired, t.titleTooLong, task])

  const submitComment = useCallback(async () => {
    if (!task) return
    const body = commentDraft.trim()
    if (!body) {
      setActionError(t.commentRequired)
      return
    }
    const mentioned = parseUserIds(mentionsDraft)
    const input: generated.CommentCreate = {
      body,
      ...(mentioned.length > 0 ? { mentioned_user_ids: mentioned } : {}),
    }
    setBusy(true)
    setActionError(null)
    try {
      const created = unwrap<generated.Entity>(
        await generated.addTaskComment(
          task.id,
          input,
          requestInit(csrfToken, { command: true, idempotency: 'task-comment' }),
        ),
      )
      setComments((current) => [...current, created as unknown as TaskComment])
      setCommentDraft('')
      setMentionsDraft('')
      setDialog(null)
    } catch (error) {
      setActionError(errorMessage(error, t.actionError))
    } finally {
      setBusy(false)
    }
  }, [commentDraft, csrfToken, mentionsDraft, t.actionError, t.commentRequired, task])

  const submitAttach = useCallback(async () => {
    if (!task) return
    const documentId = documentDraft.trim()
    if (!documentId) {
      setActionError(t.documentIdRequired)
      return
    }
    setBusy(true)
    setActionError(null)
    try {
      const updated = unwrap<generated.Task>(
        await generated.attachTaskDocument(
          task.id,
          { document_id: documentId },
          requestInit(csrfToken, { command: true, idempotency: 'task-attach-document' }),
        ),
      )
      setTask(updated)
      setDocumentDraft('')
      setDialog(null)
    } catch (error) {
      setActionError(errorMessage(error, t.actionError))
    } finally {
      setBusy(false)
    }
  }, [csrfToken, documentDraft, t.actionError, t.documentIdRequired, task])

  const backAction = (
    <Button variant="secondary" onClick={() => navigate('/tasks')}>
      {t.back}
    </Button>
  )

  if (state === 'loading' && !task) {
    return (
      <Page aria-labelledby="task-detail-heading">
        <PageHeader id="task-detail-heading" title={t.pageTitle} actions={backAction} />
        <SkeletonList />
      </Page>
    )
  }

  if (state === 'forbidden' && !task) {
    return (
      <Page aria-labelledby="task-detail-heading">
        <PageHeader id="task-detail-heading" title={t.pageTitle} actions={backAction} />
        <EmptyState title={t.forbidden} />
      </Page>
    )
  }

  if (state === 'not-found' && !task) {
    return (
      <Page aria-labelledby="task-detail-heading">
        <PageHeader id="task-detail-heading" title={t.pageTitle} actions={backAction} />
        <EmptyState icon={<XCircle aria-hidden="true" />} title={t.notFound} />
      </Page>
    )
  }

  if ((state === 'error' || state === 'conflict' || state === 'stale') && !task) {
    return (
      <Page aria-labelledby="task-detail-heading">
        <PageHeader id="task-detail-heading" title={t.pageTitle} actions={backAction} />
        <InlineError message={t.error} retryLabel={t.retry} onRetry={() => void load()} />
      </Page>
    )
  }

  if (!task) return null

  const allowed = task.allowed_actions ?? []
  const can = (action: string) => allowed.some((item) => item === action)

  return (
    <Page aria-labelledby="task-detail-heading">
      <PageHeader
        id="task-detail-heading"
        title={task.title}
        description={t.pageTitle}
        actions={backAction}
      />

      {state === 'stale' ? (
        <InlineError message={t.stale} retryLabel={t.retry} onRetry={() => void load()} />
      ) : null}
      {actionError ? (
        <InlineError message={actionError} retryLabel={t.retry} onRetry={() => setActionError(null)} />
      ) : null}

      <TaskMetaPanel task={task} />
      <TaskActionsPanel
        can={can}
        busy={busy}
        onStart={() => void submitTransition('start')}
        onBlock={() => openTransitionDialog(setDialog, setActionError, 'block')}
        onUnblock={() => void submitTransition('unblock')}
        onComplete={() => openTransitionDialog(setDialog, setActionError, 'complete')}
        onCancel={() => openTransitionDialog(setDialog, setActionError, 'cancel')}
        onEdit={openEdit}
        onComment={() => {
          setActionError(null)
          setDialog({ kind: 'comment' })
        }}
        onAttach={() => {
          setActionError(null)
          setDialog({ kind: 'attach-document' })
        }}
      />

      <Panel id="task-participants-panel" title={t.participants} level={2}>
        {task.participant_user_ids && task.participant_user_ids.length > 0 ? (
          <ul className="ui-list">
            {task.participant_user_ids.map((participant) => (
              <li key={participant}>{participant}</li>
            ))}
          </ul>
        ) : (
          <EmptyState title={t.noParticipants} />
        )}
      </Panel>

      <Panel id="task-attachments-panel" title={t.attachments} level={2}>
        {task.attachments && task.attachments.length > 0 ? (
          <ul className="ui-list">
            {task.attachments.map((attachment) => (
              <li key={attachment.document_id}>
                <FileText aria-hidden="true" /> {attachment.title ?? attachment.document_id}
              </li>
            ))}
          </ul>
        ) : (
          <EmptyState icon={<Paperclip aria-hidden="true" />} title={t.noAttachments} />
        )}
      </Panel>

      <TaskCommentsPanel comments={comments} />

      {renderDialog({
        dialog,
        t,
        busy,
        reasonDraft,
        noteDraft,
        editTitle,
        editDescription,
        editPriority,
        commentDraft,
        mentionsDraft,
        documentDraft,
        setReasonDraft,
        setNoteDraft,
        setEditTitle,
        setEditDescription,
        setEditPriority,
        setCommentDraft,
        setMentionsDraft,
        setDocumentDraft,
        onClose: () => {
          setDialog(null)
          setActionError(null)
        },
        onSubmitTransition: (action) => void submitTransition(action),
        onSubmitEdit: () => void submitEdit(),
        onSubmitComment: () => void submitComment(),
        onSubmitAttach: () => void submitAttach(),
      })}
    </Page>
  )
}

/* ---- Panels ---- */

function TaskMetaPanel({ task }: { task: generated.Task }) {
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
  const classificationLabel = classificationLabelFor(task.classification, t)
  return (
    <Panel id="task-meta-panel" title={t.metaTitle} level={2}>
      <dl className="detail-list">
        <div>
          <dt>{t.state}</dt>
          <dd>
            <StatusBadge variant={variant}>{statusLabel(task.state, locale)}</StatusBadge>
          </dd>
        </div>
        <div>
          <dt>{t.priority}</dt>
          <dd>{priorityLabel}</dd>
        </div>
        <div>
          <dt>{t.classification}</dt>
          <dd>{classificationLabel}</dd>
        </div>
        {task.assignee_user_id ? (
          <div>
            <dt>{t.assignee}</dt>
            <dd>{task.assignee_user_id}</dd>
          </div>
        ) : null}
        {task.creator_user_id ? (
          <div>
            <dt>{t.creator}</dt>
            <dd>{task.creator_user_id}</dd>
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
        <div>
          <dt>{t.description}</dt>
          <dd>{task.description || t.noDescription}</dd>
        </div>
        <div>
          <dt>{t.createdAt}</dt>
          <dd>
            <time dateTime={task.created_at}>{formatDate(task.created_at, locale)}</time>
          </dd>
        </div>
        <div>
          <dt>{t.updatedAt}</dt>
          <dd>
            <time dateTime={task.updated_at}>{formatDate(task.updated_at, locale)}</time>
          </dd>
        </div>
      </dl>
    </Panel>
  )
}

function TaskActionsPanel({
  can,
  busy,
  onStart,
  onBlock,
  onUnblock,
  onComplete,
  onCancel,
  onEdit,
  onComment,
  onAttach,
}: {
  can: (action: string) => boolean
  busy: boolean
  onStart: () => void
  onBlock: () => void
  onUnblock: () => void
  onComplete: () => void
  onCancel: () => void
  onEdit: () => void
  onComment: () => void
  onAttach: () => void
}) {
  const locale = useLocale()
  const t = copy[locale]
  return (
    <Panel id="task-actions-panel" title={t.actionsTitle} level={2}>
      <div className="server-actions" aria-label={t.actionsTitle}>
        {can('start') ? (
          <Button disabled={busy} onClick={onStart}>
            <Play aria-hidden="true" /> {t.actionStart}
          </Button>
        ) : null}
        {can('block') ? (
          <Button variant="secondary" disabled={busy} onClick={onBlock}>
            <ShieldOff aria-hidden="true" /> {t.actionBlock}
          </Button>
        ) : null}
        {can('unblock') ? (
          <Button variant="secondary" disabled={busy} onClick={onUnblock}>
            <RotateCcw aria-hidden="true" /> {t.actionUnblock}
          </Button>
        ) : null}
        {can('complete') ? (
          <Button disabled={busy} onClick={onComplete}>
            <CheckCircle2 aria-hidden="true" /> {t.actionComplete}
          </Button>
        ) : null}
        {can('cancel') ? (
          <Button variant="secondary" disabled={busy} onClick={onCancel}>
            <Ban aria-hidden="true" /> {t.actionCancel}
          </Button>
        ) : null}
        {can('edit') ? (
          <Button variant="secondary" disabled={busy} onClick={onEdit}>
            <Pencil aria-hidden="true" /> {t.actionEdit}
          </Button>
        ) : null}
        {can('comment') ? (
          <Button variant="secondary" disabled={busy} onClick={onComment}>
            <MessageSquare aria-hidden="true" /> {t.actionComment}
          </Button>
        ) : null}
        {can('attach-document') ? (
          <Button variant="secondary" disabled={busy} onClick={onAttach}>
            <Paperclip aria-hidden="true" /> {t.actionAttach}
          </Button>
        ) : null}
        {!can('start') &&
        !can('block') &&
        !can('unblock') &&
        !can('complete') &&
        !can('cancel') &&
        !can('edit') &&
        !can('comment') &&
        !can('attach-document') ? (
          <p className="ui-muted">{t.noActions}</p>
        ) : null}
      </div>
    </Panel>
  )
}

function TaskCommentsPanel({ comments }: { comments: TaskComment[] }) {
  const locale = useLocale()
  const t = copy[locale]
  return (
    <Panel id="task-comments-panel" title={t.comments} level={2}>
      {comments.length > 0 ? (
        <ul className="ui-list">
          {comments.map((comment) => (
            <li key={comment.id} className="ui-comment">
              <strong>{comment.author_user_id || '—'}</strong>
              <span>{comment.body}</span>
              <small>
                <time dateTime={comment.created_at}>{formatDate(comment.created_at, locale)}</time>
              </small>
            </li>
          ))}
        </ul>
      ) : (
        <EmptyState icon={<MessageSquare aria-hidden="true" />} title={t.noComments} />
      )}
    </Panel>
  )
}

/* ---- Dialog ---- */

function openTransitionDialog(
  setDialog: (value: DialogKind | null) => void,
  setActionError: (value: string | null) => void,
  action: TransitionAction,
): void {
  setActionError(null)
  setDialog({ kind: 'transition', action })
}

function renderDialog(props: {
  dialog: DialogKind | null
  t: TasksCopy
  busy: boolean
  reasonDraft: string
  noteDraft: string
  editTitle: string
  editDescription: string
  editPriority: string
  commentDraft: string
  mentionsDraft: string
  documentDraft: string
  setReasonDraft: (value: string) => void
  setNoteDraft: (value: string) => void
  setEditTitle: (value: string) => void
  setEditDescription: (value: string) => void
  setEditPriority: (value: string) => void
  setCommentDraft: (value: string) => void
  setMentionsDraft: (value: string) => void
  setDocumentDraft: (value: string) => void
  onClose: () => void
  onSubmitTransition: (action: TransitionAction) => void
  onSubmitEdit: () => void
  onSubmitComment: () => void
  onSubmitAttach: () => void
}) {
  const { dialog, t, busy, onClose } = props
  if (!dialog) return null
  switch (dialog.kind) {
    case 'transition':
      if (dialog.action === 'block' || dialog.action === 'cancel') {
        return (
          <Drawer open onClose={onClose} title={dialog.action === 'block' ? t.dialogBlockTitle : t.dialogCancelTitle}>
            <p>{dialog.action === 'block' ? t.dialogBlockDescription : t.dialogCancelDescription}</p>
            <Field id="task-transition-reason" label={t.dialogReasonLabel} required>
              <textarea
                id="task-transition-reason"
                className="field__control"
                value={props.reasonDraft}
                onChange={(event) => props.setReasonDraft(event.target.value)}
                disabled={busy}
                aria-required="true"
                placeholder={t.dialogReasonPlaceholder}
              />
            </Field>
            <div className="dialog-actions">
              <Button variant="secondary" onClick={onClose} disabled={busy}>
                {t.cancel}
              </Button>
              <Button disabled={busy} onClick={() => props.onSubmitTransition(dialog.action)}>
                {t.confirm}
              </Button>
            </div>
          </Drawer>
        )
      }
      return (
        <Drawer open onClose={onClose} title={t.dialogCompleteTitle}>
          <p>{t.dialogCompleteDescription}</p>
          <Field id="task-transition-note" label={t.dialogNoteLabel} required>
            <textarea
              id="task-transition-note"
              className="field__control"
              value={props.noteDraft}
              onChange={(event) => props.setNoteDraft(event.target.value)}
              disabled={busy}
              aria-required="true"
              placeholder={t.dialogNotePlaceholder}
            />
          </Field>
          <div className="dialog-actions">
            <Button variant="secondary" onClick={onClose} disabled={busy}>
              {t.cancel}
            </Button>
            <Button disabled={busy} onClick={() => props.onSubmitTransition('complete')}>
              {t.confirm}
            </Button>
          </div>
        </Drawer>
      )
    case 'edit':
      return (
        <Drawer open onClose={onClose} title={t.editTitle}>
          <Field id="task-edit-title" label={t.editTitleLabel} required>
            <input
              id="task-edit-title"
              className="field__control"
              value={props.editTitle}
              onChange={(event) => props.setEditTitle(event.target.value)}
              disabled={busy}
              maxLength={255}
              aria-required="true"
            />
          </Field>
          <Field id="task-edit-description" label={t.editDescriptionLabel}>
            <textarea
              id="task-edit-description"
              className="field__control"
              value={props.editDescription}
              onChange={(event) => props.setEditDescription(event.target.value)}
              disabled={busy}
              maxLength={4000}
            />
          </Field>
          <Field id="task-edit-priority" label={t.editPriorityLabel}>
            <Select
              id="task-edit-priority"
              value={props.editPriority}
              onChange={props.setEditPriority}
              options={priorityOptions(t)}
              ariaLabel={t.editPriorityLabel}
            />
          </Field>
          <div className="dialog-actions">
            <Button variant="secondary" onClick={onClose} disabled={busy}>
              {t.cancel}
            </Button>
            <Button disabled={busy} onClick={props.onSubmitEdit}>
              {t.save}
            </Button>
          </div>
        </Drawer>
      )
    case 'comment':
      return (
        <Drawer open onClose={onClose} title={t.commentTitle}>
          <Field id="task-comment-body" label={t.commentBodyLabel} required>
            <textarea
              id="task-comment-body"
              className="field__control"
              value={props.commentDraft}
              onChange={(event) => props.setCommentDraft(event.target.value)}
              disabled={busy}
              aria-required="true"
              placeholder={t.commentBodyPlaceholder}
            />
          </Field>
          <Field id="task-comment-mentions" label={t.mentionLabel} help={t.mentionPlaceholder}>
            <input
              id="task-comment-mentions"
              className="field__control"
              value={props.mentionsDraft}
              onChange={(event) => props.setMentionsDraft(event.target.value)}
              disabled={busy}
            />
          </Field>
          <div className="dialog-actions">
            <Button variant="secondary" onClick={onClose} disabled={busy}>
              {t.cancel}
            </Button>
            <Button disabled={busy} onClick={props.onSubmitComment}>
              {t.confirm}
            </Button>
          </div>
        </Drawer>
      )
    case 'attach-document':
      return (
        <Drawer open onClose={onClose} title={t.attachTitle}>
          <Field id="task-attach-document-id" label={t.attachDocumentIdLabel} required>
            <input
              id="task-attach-document-id"
              className="field__control"
              value={props.documentDraft}
              onChange={(event) => props.setDocumentDraft(event.target.value)}
              disabled={busy}
              aria-required="true"
              placeholder={t.attachDocumentIdPlaceholder}
            />
          </Field>
          <div className="dialog-actions">
            <Button variant="secondary" onClick={onClose} disabled={busy}>
              {t.cancel}
            </Button>
            <Button disabled={busy} onClick={props.onSubmitAttach}>
              {t.confirm}
            </Button>
          </div>
        </Drawer>
      )
    default:
      return null
  }
}

/* ---- Helpers ---- */

function priorityOptions(t: TasksCopy): SelectOption[] {
  return [
    { value: 'low', label: t.priorityLow },
    { value: 'normal', label: t.priorityNormal },
    { value: 'high', label: t.priorityHigh },
    { value: 'urgent', label: t.priorityUrgent },
  ]
}

function classificationLabelFor(classification: generated.Classification, t: TasksCopy): string {
  switch (classification) {
    case 'public':
      return t.classificationPublic
    case 'internal':
      return t.classificationInternal
    case 'confidential':
      return t.classificationConfidential
    case 'top_secret':
      return t.classificationTopSecret
    default:
      return classification
  }
}

function parseUserIds(raw: string): string[] {
  return raw
    .split(/[\s,]+/)
    .map((part) => part.trim())
    .filter((part) => part.length > 0)
}

function errorMessage(error: unknown, fallback: string): string {
  if (error instanceof ApiError) {
    return error.problem.detail ?? error.problem.title ?? fallback
  }
  if (error instanceof Error && error.message) return error.message
  return fallback
}

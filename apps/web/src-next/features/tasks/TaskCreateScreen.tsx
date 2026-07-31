import { useState, type FormEvent } from 'react'
import * as generated from '../../../src/api/generated/cluster'
import { ApiError, requestInit, unwrap } from '../../api/http'
import { useNavigate } from '../../app/navigation-context'
import { useLocale, useSession, useSessionToken } from '../../app/session-context'
import { Button, Field, InlineError, Page, PageHeader, Panel, Select, type SelectOption } from '../../ui'

const copy = {
  ar: {
    pageTitle: 'إنشاء مهمة',
    pageDescription: 'أنشئ مهمة جديدة ضمن التجمع الصحي',
    back: 'عودة إلى المهام',
    titleLabel: 'العنوان',
    titlePlaceholder: 'عنوان موجز وواضح…',
    descriptionLabel: 'الوصف',
    descriptionPlaceholder: 'تفاصيل إضافية عن المهمة (اختياري)',
    priorityLabel: 'الأولوية',
    priorityLow: 'منخفضة',
    priorityNormal: 'عادية',
    priorityHigh: 'عالية',
    priorityUrgent: 'عاجلة',
    assigneeLabel: 'المسند إليه',
    assigneeHelp: 'يُسند افتراضياً إلى المستخدم الحالي إذا تُرك فارغاً.',
    classificationLabel: 'التصنيف',
    classificationPublic: 'عام',
    classificationInternal: 'داخلي',
    classificationConfidential: 'سري',
    classificationTopSecret: 'سري للغاية',
    dueAtLabel: 'تاريخ الاستحقاق',
    dueAtHelp: 'تاريخ ووقت الاستحقاق (اختياري)',
    submit: 'إنشاء المهمة',
    titleRequired: 'العنوان مطلوب.',
    titleTooLong: 'يجب ألا يتجاوز العنوان 255 حرفاً.',
    descriptionTooLong: 'يجب ألا يتجاوز الوصف 4000 حرف.',
    invalidDueAt: 'صيغة تاريخ الاستحقاق غير صحيحة.',
    submitError: 'تعذر إنشاء المهمة. يرجى إعادة المحاولة.',
    forbidden: 'غير مصرح لك بإنشاء المهام.',
    conflict: 'تعارض في إنشاء المهمة، يرجى إعادة المحاولة.',
    loading: 'جارٍ الإنشاء…',
  },
  en: {
    pageTitle: 'Create task',
    pageDescription: 'Create a new task within the health cluster',
    back: 'Back to tasks',
    titleLabel: 'Title',
    titlePlaceholder: 'A short, clear title…',
    descriptionLabel: 'Description',
    descriptionPlaceholder: 'Additional details (optional)',
    priorityLabel: 'Priority',
    priorityLow: 'Low',
    priorityNormal: 'Normal',
    priorityHigh: 'High',
    priorityUrgent: 'Urgent',
    assigneeLabel: 'Assignee',
    assigneeHelp: 'Defaults to the current user when left empty.',
    classificationLabel: 'Classification',
    classificationPublic: 'Public',
    classificationInternal: 'Internal',
    classificationConfidential: 'Confidential',
    classificationTopSecret: 'Top secret',
    dueAtLabel: 'Due at',
    dueAtHelp: 'Due date and time (optional)',
    submit: 'Create task',
    titleRequired: 'A title is required.',
    titleTooLong: 'The title must be at most 255 characters.',
    descriptionTooLong: 'The description must be at most 4000 characters.',
    invalidDueAt: 'The due date format is invalid.',
    submitError: 'Could not create the task. Please try again.',
    forbidden: 'You are not authorized to create tasks.',
    conflict: 'Conflict while creating the task, please try again.',
    loading: 'Creating…',
  },
} as const

type CreateCopy = (typeof copy)[keyof typeof copy]

export function TaskCreateScreen() {
  const locale = useLocale()
  const csrfToken = useSessionToken()
  const session = useSession()
  const navigate = useNavigate()
  const t = copy[locale]

  const [title, setTitle] = useState('')
  const [description, setDescription] = useState('')
  const [priority, setPriority] = useState('normal')
  const [assignee, setAssignee] = useState(session.session.userId)
  const [classification, setClassification] = useState('internal')
  const [dueAt, setDueAt] = useState('')
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    const trimmedTitle = title.trim()
    if (!trimmedTitle) {
      setError(t.titleRequired)
      return
    }
    if (trimmedTitle.length > 255) {
      setError(t.titleTooLong)
      return
    }
    if (description.length > 4000) {
      setError(t.descriptionTooLong)
      return
    }
    let dueAtValue: string | undefined
    if (dueAt) {
      const parsed = new Date(dueAt)
      if (Number.isNaN(parsed.getTime())) {
        setError(t.invalidDueAt)
        return
      }
      dueAtValue = parsed.toISOString()
    }
    setSaving(true)
    setError(null)
    try {
      const input: generated.TaskCreate = {
        title: trimmedTitle,
        priority: priority as generated.TaskCreatePriority,
        classification: classification as generated.Classification,
        assignee_user_id: assignee.trim() || undefined,
        ...(description.trim() ? { description: description.trim() } : {}),
        ...(dueAtValue ? { due_at: dueAtValue } : {}),
      }
      const created = unwrap<generated.Task>(
        await generated.createTask(input, requestInit(csrfToken, { command: true, idempotency: 'task-create' })),
      )
      navigate(`/tasks/${created.id}`)
    } catch (cause) {
      if (cause instanceof ApiError && cause.status === 403) {
        setError(t.forbidden)
      } else if (cause instanceof ApiError && cause.status === 409) {
        setError(t.conflict)
      } else {
        setError(t.submitError)
      }
    } finally {
      setSaving(false)
    }
  }

  return (
    <Page aria-labelledby="task-create-heading">
      <PageHeader
        id="task-create-heading"
        title={t.pageTitle}
        description={t.pageDescription}
        actions={
          <Button variant="secondary" onClick={() => navigate('/tasks')}>
            {t.back}
          </Button>
        }
      />
      {error ? <InlineError message={error} /> : null}
      <Panel id="task-create-form" title={t.pageTitle} level={2}>
        <form className="ui-form-grid" onSubmit={(event) => void submit(event)}>
          <Field id="task-create-title" label={t.titleLabel} required>
            <input
              id="task-create-title"
              className="field__control"
              value={title}
              onChange={(event) => setTitle(event.target.value)}
              maxLength={255}
              disabled={saving}
              aria-required="true"
              placeholder={t.titlePlaceholder}
            />
          </Field>
          <Field id="task-create-description" label={t.descriptionLabel}>
            <textarea
              id="task-create-description"
              className="field__control"
              value={description}
              onChange={(event) => setDescription(event.target.value)}
              maxLength={4000}
              disabled={saving}
              placeholder={t.descriptionPlaceholder}
            />
          </Field>
          <Field id="task-create-priority" label={t.priorityLabel}>
            <Select
              id="task-create-priority"
              value={priority}
              onChange={setPriority}
              options={priorityOptions(t)}
              ariaLabel={t.priorityLabel}
            />
          </Field>
          <Field id="task-create-assignee" label={t.assigneeLabel} help={t.assigneeHelp}>
            <input
              id="task-create-assignee"
              className="field__control"
              value={assignee}
              onChange={(event) => setAssignee(event.target.value)}
              disabled={saving}
            />
          </Field>
          <Field id="task-create-classification" label={t.classificationLabel}>
            <Select
              id="task-create-classification"
              value={classification}
              onChange={setClassification}
              options={classificationOptions(t)}
              ariaLabel={t.classificationLabel}
            />
          </Field>
          <Field id="task-create-due-at" label={t.dueAtLabel} help={t.dueAtHelp}>
            <input
              id="task-create-due-at"
              className="field__control"
              type="datetime-local"
              value={dueAt}
              onChange={(event) => setDueAt(event.target.value)}
              disabled={saving}
            />
          </Field>
          <div className="dialog-actions">
            <Button type="submit" disabled={saving}>
              {saving ? t.loading : t.submit}
            </Button>
          </div>
        </form>
      </Panel>
    </Page>
  )
}

function priorityOptions(t: CreateCopy): SelectOption[] {
  return [
    { value: 'low', label: t.priorityLow },
    { value: 'normal', label: t.priorityNormal },
    { value: 'high', label: t.priorityHigh },
    { value: 'urgent', label: t.priorityUrgent },
  ]
}

function classificationOptions(t: CreateCopy): SelectOption[] {
  return [
    { value: 'public', label: t.classificationPublic },
    { value: 'internal', label: t.classificationInternal },
    { value: 'confidential', label: t.classificationConfidential },
    { value: 'top_secret', label: t.classificationTopSecret },
  ]
}

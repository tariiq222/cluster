// @vitest-environment jsdom
import { useCallback, useMemo, useRef, useState } from 'react'
import type { FormEvent } from 'react'
import { ArrowLeft, Plus } from 'lucide-react'

import type { Locale } from '../../app/copy'
import { directionForLocale } from '../../app/copy'
import type { Session } from '../../api'
import { ApiError, stateFromError } from '../../api'
import {
  Button,
  Field,
  InlineError,
  Page,
  PageHeader,
  Panel,
  Select,
  type SelectOption,
} from '../../ui'

import { createTask, type TaskPriority } from '../../api/tasks'
import { tasksCopy, type TasksCopy } from './tasks-copy'

export interface TaskCreateScreenProps {
  locale: Locale
  session: Session
  onNavigate?: (path: string) => void
  onCreated?: (taskId: string) => void
}

const PRIORITY_VALUES: readonly TaskPriority[] = ['low', 'normal', 'high', 'urgent']

export function TaskCreateScreen({ locale, session, onNavigate, onCreated }: TaskCreateScreenProps) {
  const copy = tasksCopy[locale]
  const [title, setTitle] = useState('')
  const [description, setDescription] = useState('')
  const [assignee, setAssignee] = useState('')
  const [priority, setPriority] = useState<TaskPriority>('normal')
  const [dueAt, setDueAt] = useState('')
  const [participants, setParticipants] = useState('')
  const [status, setStatus] = useState<'idle' | 'submitting' | 'error'>('idle')
  const [errorMessage, setErrorMessage] = useState<string | null>(null)
  const [fieldError, setFieldError] = useState<string | null>(null)
  const requestRef = useRef(0)

  const priorityOptions = useMemo<SelectOption[]>(() => buildPriorityOptions(copy), [copy])

  const submit = useCallback(async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    setFieldError(null)
    setErrorMessage(null)
    const trimmedTitle = title.trim()
    if (!trimmedTitle) {
      setFieldError(copy.createTitleRequired)
      return
    }
    const request = ++requestRef.current
    setStatus('submitting')
    try {
      const dueAtIso = normalizeDueAt(dueAt)
      const input = {
        title: trimmedTitle,
        description: description.trim() || undefined,
        assignee_user_id: assignee.trim() || undefined,
        priority,
        due_at: dueAtIso,
        participant_user_ids: parseParticipants(participants),
      }
      const created = await createTask(session.access_token, input)
      if (request !== requestRef.current) return
      onCreated?.(String(created.id))
      onNavigate?.(`/tasks/${created.id}`)
    } catch (error) {
      if (request !== requestRef.current) return
      const errorState = stateFromError(error)
      if (errorState === 'forbidden') {
        setErrorMessage(copy.forbiddenBody)
      } else if (error instanceof ApiError && error.status === 422) {
        setFieldError(copy.createTeamScopeError)
      } else {
        setErrorMessage(copy.apiError)
      }
      setStatus('error')
    }
  }, [assignee, copy, description, dueAt, onCreated, onNavigate, participants, priority, session.access_token, title])

  const goBack = () => onNavigate?.('/tasks')
  return (
    <div dir={directionForLocale(locale)}>
      <Page aria-labelledby="task-create-heading">
        <PageHeader
          id="task-create-heading"
          title={copy.createTitle}
          description={copy.createDescription}
          actions={
            <Button variant="quiet" onClick={goBack}>
              <ArrowLeft aria-hidden="true" /> {copy.listTitle}
            </Button>
          }
        />

        <Panel id="task-create-form-panel" title={copy.createTitle} level={2}>
          {errorMessage ? (
            <InlineError message={errorMessage} retryLabel={copy.retry} onRetry={() => setErrorMessage(null)} />
          ) : null}

          <form aria-describedby="task-create-live" noValidate onSubmit={submit}>
            <div role="status" aria-live="polite" id="task-create-live" className="visually-hidden">
              {status === 'submitting' ? copy.createSubmitting : copy.createSuccess}
            </div>

            <Field id="task-title" label={copy.createTitleLabel} required error={fieldError ?? undefined}>
              <input
                id="task-title"
                name="title"
                type="text"
                value={title}
                onChange={(event) => setTitle(event.target.value)}
                required
                maxLength={255}
                placeholder={copy.createTitlePlaceholder}
                aria-invalid={fieldError ? true : undefined}
              />
            </Field>

            <Field id="task-description" label={copy.createDescriptionLabel} help={copy.createDescriptionPlaceholder}>
              <textarea
                id="task-description"
                name="description"
                value={description}
                onChange={(event) => setDescription(event.target.value)}
                rows={4}
                maxLength={4000}
                placeholder={copy.createDescriptionPlaceholder}
              />
            </Field>

            <Field id="task-assignee" label={copy.createAssigneeLabel} help={copy.createAssigneeHelp}>
              <input
                id="task-assignee"
                name="assignee_user_id"
                type="text"
                value={assignee}
                onChange={(event) => setAssignee(event.target.value)}
                placeholder={copy.createAssigneePlaceholder}
              />
            </Field>

            <Field id="task-priority" label={copy.createPriorityLabel}>
              <Select
                id="task-priority"
                value={priority}
                onChange={(value) => setPriority(asPriority(value))}
                options={priorityOptions}
                ariaLabel={copy.createPriorityLabel}
              />
            </Field>

            <Field id="task-due-at" label={copy.createDueAtLabel}>
              <input
                id="task-due-at"
                name="due_at"
                type="datetime-local"
                value={dueAt}
                onChange={(event) => setDueAt(event.target.value)}
              />
            </Field>

            <Field id="task-participants" label={copy.createParticipantsLabel} help={copy.createParticipantsHelp}>
              <input
                id="task-participants"
                name="participant_user_ids"
                type="text"
                value={participants}
                onChange={(event) => setParticipants(event.target.value)}
                placeholder="018f3a1c-..., 018f3a1c-..."
              />
            </Field>

            <div style={{ display: 'flex', gap: '0.75rem', marginTop: '1rem' }}>
              <Button type="submit" variant="primary" disabled={status === 'submitting'}>
                <Plus aria-hidden="true" /> {status === 'submitting' ? copy.createSubmitting : copy.createSubmit}
              </Button>
              <Button type="button" variant="quiet" onClick={goBack} disabled={status === 'submitting'}>
                {copy.dialogCancel}
              </Button>
            </div>
          </form>
        </Panel>
      </Page>
    </div>
  )
}

function buildPriorityOptions(copy: TasksCopy): SelectOption[] {
  const labels: Record<TaskPriority, string> = {
    low: copy.priorityLow,
    normal: copy.priorityNormal,
    high: copy.priorityHigh,
    urgent: copy.priorityUrgent,
  }
  return PRIORITY_VALUES.map((value) => ({ value, label: labels[value] }))
}

function asPriority(value: string): TaskPriority {
  return PRIORITY_VALUES.includes(value as TaskPriority)
    ? (value as TaskPriority)
    : 'normal'
}

function normalizeDueAt(value: string): string | undefined {
  if (!value) return undefined
  const parsed = new Date(value)
  if (Number.isNaN(parsed.getTime())) return undefined
  return parsed.toISOString().replace(/\.\d{3}Z$/, 'Z')
}

function parseParticipants(input: string): string[] | undefined {
  const tokens = input
    .split(',')
    .map((token) => token.trim())
    .filter((token) => token.length > 0)
  return tokens.length > 0 ? tokens : undefined
}

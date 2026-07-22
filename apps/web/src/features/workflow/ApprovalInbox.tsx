// @vitest-environment jsdom
import { useCallback, useEffect, useRef, useState } from 'react'
import { CheckCircle2, Inbox, XCircle } from 'lucide-react'

import type { Locale } from '../../app/copy'
import type { Session } from '../../api'
import { ApiError } from '../../api'
import {
  Button,
  EmptyState,
  Field,
  InlineError,
  Page,
  PageHeader,
  Panel,
  PanelGrid,
  SkeletonList,
  StatusBadge,
} from '../../ui'
import { directionForWorkflow, formatAge, type WorkflowCopy, workflowCopy } from './workflow-copy'
import {
  getWorkflowInstance,
  listWorkflowInstances,
  recordWorkflowDecision,
  type WorkflowInstance,
  type WorkflowStep,
} from './workflow-api'

type ApprovalEntry = { instance: WorkflowInstance; step: WorkflowStep }
type Feedback = { kind: 'success' | 'error' | 'denied'; message: string }

function valueOf(record: Record<string, unknown>, key: string): string | null {
  const value = record[key]
  return typeof value === 'string' && value.trim() ? value : null
}

function lockVersion(step: WorkflowStep): number {
  return typeof step.lock_version === 'number' && step.lock_version > 0 ? step.lock_version : 1
}

function errorMessage(error: unknown, copy: WorkflowCopy): Feedback {
  if (error instanceof ApiError && error.status === 403) return { kind: 'denied', message: copy.reqDeniedBody }
  if (error instanceof ApiError && error.status === 409) return { kind: 'error', message: copy.conflict }
  if (error instanceof ApiError && error.status === 412) return { kind: 'error', message: copy.stale }
  return { kind: 'error', message: copy.reqError }
}

export function ApprovalInbox({ locale, session }: { locale: Locale; session: Session }) {
  const copy = workflowCopy[locale]
  const [loading, setLoading] = useState(true)
  const [entries, setEntries] = useState<ApprovalEntry[]>([])
  const [loadError, setLoadError] = useState(false)
  const [feedback, setFeedback] = useState<Feedback | null>(null)
  const [reasons, setReasons] = useState<Record<string, string>>({})
  const [busy, setBusy] = useState<string | null>(null)
  const feedbackRef = useRef<HTMLParagraphElement>(null)

  const load = useCallback(async () => {
    setLoading(true)
    setLoadError(false)
    setFeedback(null)
    try {
      const collection = await listWorkflowInstances(session.access_token)
      const details = await Promise.all(
        collection.items.map((instance) => getWorkflowInstance(session.access_token, instance.id)),
      )
      const next: ApprovalEntry[] = []
      for (const detail of details) {
        for (const step of detail.steps) {
          if (
            step.assignee_user_id === session.user_id
            && (step.state === 'waiting' || step.state === 'active')
          ) {
            next.push({ instance: detail.instance, step })
          }
        }
      }
      setEntries(next)
    } catch (error) {
      setEntries([])
      if (error instanceof ApiError && error.status === 403) setFeedback({ kind: 'denied', message: copy.reqDeniedBody })
      else setLoadError(true)
    } finally {
      setLoading(false)
    }
  }, [copy.reqDeniedBody, session.access_token, session.user_id])

  useEffect(() => { void load() }, [load])
  useEffect(() => {
    if (feedback) window.requestAnimationFrame(() => feedbackRef.current?.focus())
  }, [feedback])

  async function decide(entry: ApprovalEntry, decision: 'approve' | 'reject') {
    const stepId = entry.step.id
    const reason = (reasons[stepId] ?? '').trim()
    if (decision === 'reject' && reason.length < 10) {
      setFeedback({ kind: 'error', message: copy.reqReasonMin })
      return
    }
    setBusy(stepId)
    setFeedback(null)
    try {
      await recordWorkflowDecision(session.access_token, stepId, { decision, ...(reason ? { reason } : {}) }, lockVersion(entry.step))
      setEntries((current) => current.filter((item) => item.step.id !== stepId))
      setFeedback({ kind: 'success', message: copy.success })
    } catch (error) {
      setFeedback(errorMessage(error, copy))
    } finally {
      setBusy(null)
    }
  }

  return (
    <div dir={directionForWorkflow(locale)}>
      <Page aria-labelledby="approval-inbox-heading">
        <PageHeader
          id="approval-inbox-heading"
          title={copy.reqApprovalInbox}
          description={copy.reqApprovalInboxDescription}
          actions={<Button variant="secondary" onClick={() => void load()}>{copy.refresh}</Button>}
        />
        {feedback ? <p ref={feedbackRef} tabIndex={-1} role={feedback.kind === 'success' ? 'status' : 'alert'} className="status-message" aria-live="polite">{feedback.message}</p> : null}
        {loading ? <SkeletonList label={copy.reqLoading} /> : feedback?.kind === 'denied' ? (
          <Panel id="approval-inbox-denied" title={copy.reqDeniedTitle} level={2}><p>{copy.reqDeniedBody}</p></Panel>
        ) : loadError ? (
          <InlineError message={copy.reqError} retryLabel={copy.retry} onRetry={() => void load()} />
        ) : entries.length === 0 ? (
          <EmptyState icon={<Inbox aria-hidden="true" />} title={copy.reqEmptyApprovals} body={copy.reqEmptyApprovalsBody} />
        ) : (
          <PanelGrid>
            {entries.map(({ instance, step }) => {
              const stepId = step.id
              const subject = valueOf(instance, 'subject') ?? valueOf(instance, 'record_type') ?? instance.id
              const currentOwner = valueOf(instance, 'current_owner_user_id') ?? step.assignee_user_id ?? '—'
              const reason = reasons[stepId] ?? ''
              return (
                <Panel key={stepId} id={`approval-${stepId}`} title={subject} level={2} actions={<StatusBadge>{copy.workflowState(step.state ?? '')}</StatusBadge>}>
                  <dl className="definition-grid">
                    <div><dt>{copy.reqCurrentOwner}</dt><dd dir="ltr">{currentOwner}</dd></div>
                    <div><dt>{copy.age}</dt><dd>{formatAge(step.created_at ?? instance.created_at, locale)}</dd></div>
                    <div><dt>{copy.status}</dt><dd>{copy.workflowState(step.state ?? '')}</dd></div>
                  </dl>
                  <Field id={`${stepId}-reason`} label={copy.reason} help={copy.reasonHint}>
                    <textarea id={`${stepId}-reason`} value={reason} onChange={(event) => setReasons((current) => ({ ...current, [stepId]: event.target.value }))} aria-describedby={`${stepId}-reason-help`} />
                  </Field>
                  <div className="table-actions">
                    <Button type="button" disabled={busy === stepId} onClick={() => void decide({ instance, step }, 'approve')}><CheckCircle2 aria-hidden="true" /> {busy === stepId ? copy.reqDecisionPending : copy.approve}</Button>
                    <Button type="button" variant="secondary" disabled={busy === stepId} onClick={() => void decide({ instance, step }, 'reject')}><XCircle aria-hidden="true" /> {busy === stepId ? copy.reqDecisionPending : copy.reject}</Button>
                  </div>
                </Panel>
              )
            })}
          </PanelGrid>
        )}
      </Page>
    </div>
  )
}

// @vitest-environment jsdom
import { useCallback, useEffect, useRef, useState } from 'react'
import { ArrowUpRight, CheckCircle2, Inbox, RotateCcw, UserRoundPen, XCircle } from 'lucide-react'

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
import { directionForLocale } from '../../app/copy'
import { formatAge, type WorkflowCopy, workflowCopy } from './workflow-copy'
import {
  actOnWorkflowStep,
  listActionableWorkflowStepsInbox,
  recordWorkflowDecision,
  type WorkflowInboxItem,
} from './workflow-api'

type Feedback = { kind: 'success' | 'error' | 'denied'; message: string }

function lockVersion(step: WorkflowInboxItem): number {
  return typeof step.lock_version === 'number' && step.lock_version > 0 ? step.lock_version : 1
}

function errorMessage(error: unknown, copy: WorkflowCopy): Feedback {
  if (error instanceof ApiError && error.status === 403) return { kind: 'denied', message: copy.reqDeniedBody }
  if (error instanceof ApiError && error.status === 409) return { kind: 'error', message: copy.conflict }
  if (error instanceof ApiError && error.status === 412) return { kind: 'error', message: copy.stale }
  return { kind: 'error', message: copy.reqError }
}

export function ApprovalInbox({ locale, session, scopeReady, scopeEpoch }: { locale: Locale; session: Session; scopeReady: boolean; scopeEpoch: number }) {
  const copy = workflowCopy[locale]
  const [loading, setLoading] = useState(true)
  const [entries, setEntries] = useState<WorkflowInboxItem[]>([])
  const [loadError, setLoadError] = useState(false)
  const [feedback, setFeedback] = useState<Feedback | null>(null)
  const [reasons, setReasons] = useState<Record<string, string>>({})
  const [reassignmentTargets, setReassignmentTargets] = useState<Record<string, string>>({})
  const [busy, setBusy] = useState<string | null>(null)
  const feedbackRef = useRef<HTMLParagraphElement>(null)
  const requestRef = useRef(0)

  const load = useCallback(async () => {
    const request = ++requestRef.current
    if (!scopeReady) {
      setEntries([])
      setLoadError(false)
      setFeedback(null)
      setLoading(false)
      return
    }
    setLoading(true)
    setLoadError(false)
    setFeedback(null)
    try {
      const entries = await listActionableWorkflowStepsInbox(session.access_token)
      if (request !== requestRef.current) return
      setEntries(entries)
    } catch (error) {
      if (request !== requestRef.current) return
      setEntries([])
      if (error instanceof ApiError && error.status === 403) setFeedback({ kind: 'denied', message: copy.reqDeniedBody })
      else setLoadError(true)
    } finally {
      if (request === requestRef.current) setLoading(false)
    }
  }, [copy.reqDeniedBody, scopeReady, session.access_token])

  useEffect(() => { void load() }, [load, scopeEpoch])
  useEffect(() => {
    if (feedback) window.requestAnimationFrame(() => feedbackRef.current?.focus())
  }, [feedback])

  async function decide(step: WorkflowInboxItem, decision: 'approve' | 'reject' | 'return') {
    const stepId = step.id
    const reason = (reasons[stepId] ?? '').trim()
    if ((decision === 'reject' || decision === 'return') && reason.length < 10) {
      setFeedback({ kind: 'error', message: copy.reqReasonMin })
      return
    }
    setBusy(stepId)
    setFeedback(null)
    try {
      await recordWorkflowDecision(session.access_token, stepId, { decision, ...(reason ? { reason } : {}) }, lockVersion(step))
      setEntries((current) => current.filter((item) => item.id !== stepId))
      setFeedback({ kind: 'success', message: copy.success })
    } catch (error) {
      setFeedback(errorMessage(error, copy))
    } finally {
      setBusy(null)
    }
  }

  async function act(step: WorkflowInboxItem, action: 'reassign' | 'escalate') {
    const stepId = step.id
    const reason = (reasons[stepId] ?? '').trim()
    const targetUserId = (reassignmentTargets[stepId] ?? '').trim()
    if (reason === '') {
      setFeedback({ kind: 'error', message: copy.actionReasonRequired })
      return
    }
    if (action === 'reassign' && targetUserId === '') {
      setFeedback({ kind: 'error', message: copy.reassignmentTargetRequired })
      return
    }

    setBusy(stepId)
    setFeedback(null)
    try {
      await actOnWorkflowStep(
        session.access_token,
        stepId,
        action,
        { reason, ...(action === 'reassign' ? { target_user_id: targetUserId } : {}) },
        lockVersion(step),
      )
      setEntries((current) => current.filter((item) => item.id !== stepId))
      setFeedback({ kind: 'success', message: copy.success })
    } catch (error) {
      setFeedback(errorMessage(error, copy))
    } finally {
      setBusy(null)
    }
  }

  return (
    <div dir={directionForLocale(locale)}>
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
            {entries.map((step) => {
              const stepId = step.id
              const subject = step.source_type ?? step.source_id ?? stepId
              const currentOwner = step.assignee_user_id ?? '—'
              const reason = reasons[stepId] ?? ''
              const targetUserId = reassignmentTargets[stepId] ?? ''
              const needsReason = (step.allowed_actions ?? []).some((action) => action === 'reject' || action === 'return' || action === 'reassign' || action === 'escalate')
              return (
                <Panel key={stepId} id={`approval-${stepId}`} title={<a href={`/approvals/${stepId}`}>{subject}</a>} level={2} actions={<StatusBadge>{copy.workflowState(step.state ?? '')}</StatusBadge>}>
                  <dl className="definition-grid">
                    <div><dt>{copy.reqCurrentOwner}</dt><dd dir="ltr">{currentOwner}</dd></div>
                     <div><dt>{copy.age}</dt><dd>{formatAge(step.created_at, locale)}</dd></div>
                    <div><dt>{copy.status}</dt><dd>{copy.workflowState(step.state ?? '')}</dd></div>
                  </dl>
                  {needsReason ? (
                    <Field id={`${stepId}-reason`} label={copy.reason} help={copy.reasonHint}>
                      <textarea id={`${stepId}-reason`} value={reason} onChange={(event) => setReasons((current) => ({ ...current, [stepId]: event.target.value }))} aria-describedby={`${stepId}-reason-help`} />
                    </Field>
                  ) : null}
                  {(step.allowed_actions ?? []).includes('reassign') ? (
                    <Field id={`${stepId}-reassign-target`} label={copy.reassignmentTarget} help={copy.reassignmentTargetHelp}>
                      <input id={`${stepId}-reassign-target`} value={targetUserId} onChange={(event) => setReassignmentTargets((current) => ({ ...current, [stepId]: event.target.value }))} />
                    </Field>
                  ) : null}
                  <div className="table-actions">
                    {(step.allowed_actions ?? []).includes('approve') ? <Button type="button" disabled={busy === stepId} onClick={() => void decide(step, 'approve')}><CheckCircle2 aria-hidden="true" /> {busy === stepId ? copy.reqDecisionPending : copy.approve}</Button> : null}
                    {(step.allowed_actions ?? []).includes('reject') ? <Button type="button" variant="secondary" disabled={busy === stepId} onClick={() => void decide(step, 'reject')}><XCircle aria-hidden="true" /> {busy === stepId ? copy.reqDecisionPending : copy.reject}</Button> : null}
                    {(step.allowed_actions ?? []).includes('return') ? <Button type="button" variant="secondary" disabled={busy === stepId} onClick={() => void decide(step, 'return')}><RotateCcw aria-hidden="true" /> {busy === stepId ? copy.returning : copy.returnForCorrection}</Button> : null}
                    {(step.allowed_actions ?? []).includes('reassign') ? <Button type="button" variant="secondary" disabled={busy === stepId} onClick={() => void act(step, 'reassign')}><UserRoundPen aria-hidden="true" /> {busy === stepId ? copy.reassigning : copy.reassign}</Button> : null}
                    {(step.allowed_actions ?? []).includes('escalate') ? <Button type="button" variant="secondary" disabled={busy === stepId} onClick={() => void act(step, 'escalate')}><ArrowUpRight aria-hidden="true" /> {busy === stepId ? copy.escalating : copy.escalate}</Button> : null}
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

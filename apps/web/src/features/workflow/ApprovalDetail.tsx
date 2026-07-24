import { useCallback, useEffect, useRef, useState } from 'react'
import { ArrowUpRight, CheckCircle2, RotateCcw, UserRoundPen, XCircle } from 'lucide-react'

import type { Locale } from '../../app/copy'
import { directionForLocale } from '../../app/copy'
import type { Session } from '../../api'
import { stateFromError, type ResourceState } from '../../api'
import type { WorkflowStepDetail } from '../../api/generated/cluster'
import {
  Button,
  EmptyState,
  Field,
  InlineError,
  Page,
  PageHeader,
  Panel,
  SkeletonList,
  StatusBadge,
} from '../../ui'
import { workflowCopy } from './workflow-copy'
import { actOnWorkflowStep, getWorkflowStep, recordWorkflowDecision } from './workflow-api'

type SupportedDecision = 'approve' | 'reject' | 'return'

function isSupportedDecision(action: string): action is SupportedDecision {
  return action === 'approve' || action === 'reject' || action === 'return'
}

export function ApprovalDetail({
  locale,
  session,
  stepId,
  scopeReady,
  scopeEpoch,
  onNavigate,
}: {
  locale: Locale
  session: Session
  stepId: string
  scopeReady: boolean
  scopeEpoch: number
  onNavigate?: (path: string) => void
}) {
  const copy = workflowCopy[locale]
  const [state, setState] = useState<ResourceState>('loading')
  const [step, setStep] = useState<WorkflowStepDetail | null>(null)
  const [reason, setReason] = useState('')
  const [reassignmentTarget, setReassignmentTarget] = useState('')
  const [reasonError, setReasonError] = useState<string | null>(null)
  const [reassignmentTargetError, setReassignmentTargetError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const requestRef = useRef(0)
  const scopeEpochRef = useRef(scopeEpoch)
  scopeEpochRef.current = scopeEpoch

  const load = useCallback(async () => {
    const request = ++requestRef.current
    setState('loading')
    setStep(null)
    setBusy(false)
    if (!scopeReady) return
    try {
      const value = await getWorkflowStep(session.access_token, stepId)
      if (request !== requestRef.current) return
      setStep(value)
      setState('ready')
    } catch (error) {
      if (request !== requestRef.current) return
      setState(stateFromError(error))
    }
  }, [scopeReady, session.access_token, stepId])

  useEffect(() => {
    void load()
  }, [load, scopeEpoch])

  async function decide(decision: SupportedDecision) {
    const trimmedReason = reason.trim()
    if ((decision === 'reject' || decision === 'return') && trimmedReason.length < 10) {
      setReasonError(copy.reqReasonMin)
      return
    }

    if (!step) return
    const decisionScopeEpoch = scopeEpochRef.current
    setReasonError(null)
    setBusy(true)
    try {
      await recordWorkflowDecision(
        session.access_token,
        step.step_id,
        { decision, reason: trimmedReason || undefined },
        step.lock_version,
      )
      if (scopeEpochRef.current !== decisionScopeEpoch) return
      setReason('')
      await load()
    } catch (error) {
      if (scopeEpochRef.current !== decisionScopeEpoch) return
      setState(stateFromError(error))
    } finally {
      if (scopeEpochRef.current === decisionScopeEpoch) setBusy(false)
    }
  }

  async function act(action: 'reassign' | 'escalate') {
    const trimmedReason = reason.trim()
    const targetUserId = reassignmentTarget.trim()
    if (trimmedReason === '') {
      setReasonError(copy.actionReasonRequired)
      setReassignmentTargetError(null)
      return
    }
    if (action === 'reassign' && targetUserId === '') {
      setReasonError(null)
      setReassignmentTargetError(copy.reassignmentTargetRequired)
      return
    }
    if (!step) return

    const actionScopeEpoch = scopeEpochRef.current
    setReasonError(null)
    setReassignmentTargetError(null)
    setBusy(true)
    try {
      await actOnWorkflowStep(
        session.access_token,
        step.step_id,
        action,
        { reason: trimmedReason, ...(action === 'reassign' ? { target_user_id: targetUserId } : {}) },
        step.lock_version,
      )
      if (scopeEpochRef.current !== actionScopeEpoch) return
      setReason('')
      setReassignmentTarget('')
      await load()
    } catch (error) {
      if (scopeEpochRef.current !== actionScopeEpoch) return
      setState(stateFromError(error))
    } finally {
      if (scopeEpochRef.current === actionScopeEpoch) setBusy(false)
    }
  }

  const allowedDecisions = step?.allowed_actions.filter(isSupportedDecision) ?? []
  const canReassign = step?.allowed_actions.includes('reassign') ?? false
  const canEscalate = step?.allowed_actions.includes('escalate') ?? false
  const needsReason = allowedDecisions.some((action) => action === 'reject' || action === 'return') || canReassign || canEscalate
  const errorMessage = state === 'conflict'
    ? copy.conflict
    : state === 'stale'
      ? copy.stale
      : copy.reqError

  return (
    <div dir={directionForLocale(locale)}>
      <Page aria-labelledby="approval-detail-heading">
        <PageHeader
          id="approval-detail-heading"
          title={copy.detail}
          description={stepId}
          actions={(
            <Button variant="secondary" onClick={() => { onNavigate?.('/approvals') }}>
              {copy.backToList}
            </Button>
          )}
        />

        {state === 'loading' ? <SkeletonList label={copy.reqLoading} /> : null}
        {state === 'forbidden' ? (
          <Panel id="approval-denied" title={copy.deniedTitle} level={2}>
            <p>{copy.deniedBody}</p>
          </Panel>
        ) : null}
        {state === 'not-found' ? (
          <EmptyState icon={<XCircle aria-hidden="true" />} title={copy.noDetails} />
        ) : null}
        {state === 'error' || state === 'conflict' || state === 'stale' ? (
          <InlineError message={errorMessage} retryLabel={copy.retry} onRetry={() => void load()} />
        ) : null}

        {state === 'ready' && step ? (
          <Panel id="approval-detail-panel" title={step.workflow_instance.id} level={2}>
            <dl className="detail-list">
              <div>
                <dt>{copy.subject}</dt>
                <dd>{step.source_type} · {step.source_id}</dd>
              </div>
              <div>
                <dt>{copy.status}</dt>
                <dd><StatusBadge>{copy.workflowState(step.workflow_instance.state)}</StatusBadge></dd>
              </div>
              <div>
                <dt>{copy.stepHistory}</dt>
                <dd><StatusBadge>{copy.workflowState(step.state)}</StatusBadge></dd>
              </div>
            </dl>

            {allowedDecisions.length > 0 || canReassign || canEscalate ? (
              <>
                {needsReason ? (
                  <Field
                    id="approval-reason"
                    label={copy.reason}
                    help={copy.reasonHint}
                    error={reasonError}
                  >
                    <textarea
                      id="approval-reason"
                      value={reason}
                      onChange={(event) => setReason(event.target.value)}
                    />
                  </Field>
                  ) : null}
                {canReassign ? (
                  <Field
                    id="approval-reassign-target"
                    label={copy.reassignmentTarget}
                    help={copy.reassignmentTargetHelp}
                    error={reassignmentTargetError}
                  >
                    <input
                      id="approval-reassign-target"
                      value={reassignmentTarget}
                      onChange={(event) => setReassignmentTarget(event.target.value)}
                    />
                  </Field>
                ) : null}
                <div className="table-actions">
                  {allowedDecisions.includes('approve') ? (
                    <Button disabled={busy} onClick={() => void decide('approve')}>
                      <CheckCircle2 aria-hidden="true" />
                      {busy ? copy.reqDecisionPending : copy.approve}
                    </Button>
                  ) : null}
                  {allowedDecisions.includes('reject') ? (
                    <Button variant="secondary" disabled={busy} onClick={() => void decide('reject')}>
                      <XCircle aria-hidden="true" />
                      {busy ? copy.reqDecisionPending : copy.reject}
                    </Button>
                  ) : null}
                  {allowedDecisions.includes('return') ? (
                    <Button variant="secondary" disabled={busy} onClick={() => void decide('return')}>
                      <RotateCcw aria-hidden="true" />
                      {busy ? copy.reqDecisionPending : copy.returnForCorrection}
                    </Button>
                  ) : null}
                  {canReassign ? (
                    <Button variant="secondary" disabled={busy} onClick={() => void act('reassign')}>
                      <UserRoundPen aria-hidden="true" />
                      {busy ? copy.reassigning : copy.reassign}
                    </Button>
                  ) : null}
                  {canEscalate ? (
                    <Button variant="secondary" disabled={busy} onClick={() => void act('escalate')}>
                      <ArrowUpRight aria-hidden="true" />
                      {busy ? copy.escalating : copy.escalate}
                    </Button>
                  ) : null}
                </div>
              </>
            ) : null}
          </Panel>
        ) : null}
      </Page>
    </div>
  )
}

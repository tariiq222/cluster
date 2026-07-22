// @vitest-environment jsdom
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { ShieldCheck, ShieldOff, Workflow } from 'lucide-react'

import type { Locale } from '../../app/copy'
import {
  ApiError,
  type Session,
} from '../../api'
import {
  listWorkflowDefinitions,
  listWorkflowVersions,
  transitionWorkflowVersion,
  type R1Entity,
  type WorkflowAction,
} from '../../api/r1'
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
import { workflowCopy } from './workflow-copy'

type OfficeAction = 'approve' | 'return'

type PendingVersion = {
  definition: R1Entity
  version: R1Entity
  graphHash: string | null
  bootstrap: boolean
}

function valueOf(record: R1Entity, key: string): string | null {
  const value = record[key]
  return typeof value === 'string' && value.trim() ? value : null
}

function readNumber(record: R1Entity, key: string, fallback: number): number {
  const value = record[key]
  if (typeof value === 'number' && Number.isFinite(value)) return value
  if (typeof value === 'string' && /^\d+$/.test(value)) return Number(value)
  return fallback
}

function readReviewState(version: R1Entity): string {
  return (
    valueOf(version, 'review_state') ??
    valueOf(version, 'approval_status') ??
    valueOf(version, 'definition_state') ??
    ''
  )
}

function readGraphHash(version: R1Entity): string | null {
  const value = version['graph_hash']
  return typeof value === 'string' && value.trim() ? value : null
}

function isPending(version: R1Entity): boolean {
  if (readReviewState(version) === 'pending_review') return true
  // Backward-compatible fallback for installations that only expose the
  // legacy approval_status column.
  return valueOf(version, 'approval_status') === 'pending_review'
}

function isSelfApproval(version: R1Entity): boolean {
  const submittedBy = valueOf(version, 'submitted_by_user_id')
  const approver = valueOf(version, 'approved_by_user_id')
  if (submittedBy && approver && submittedBy === approver) return true
  return false
}

export function ProcedureOfficeReview({
  locale,
  session,
}: {
  locale: Locale
  session: Session
}) {
  const copy = workflowCopy[locale]
  const [loading, setLoading] = useState(true)
  const [loadError, setLoadError] = useState(false)
  const [pending, setPending] = useState<PendingVersion[]>([])
  const [actionState, setActionState] = useState<{
    versionId: string
    kind: 'approving' | 'returning' | 'denied' | 'error' | 'success'
    message: string
  } | null>(null)
  const feedbackRef = useRef<HTMLParagraphElement>(null)
  const [graphDrafts, setGraphDrafts] = useState<Record<string, string>>({})
  const [reasonDrafts, setReasonDrafts] = useState<Record<string, string>>({})

  const load = useCallback(async () => {
    setLoading(true)
    setLoadError(false)
    try {
      const definitions = await listWorkflowDefinitions(session.access_token)
      const collected: PendingVersion[] = []
      for (const definition of definitions.items ?? []) {
        const definitionId = valueOf(definition, 'id')
        if (!definitionId) continue
        const versions = await listWorkflowVersions(session.access_token, definitionId)
        for (const version of versions.items ?? []) {
          if (!isPending(version)) continue
          const bootstrap =
            version['single_member_bootstrap_approval'] === true ||
            version['single_member_bootstrap_approval'] === 1 ||
            isSelfApproval(version)
          collected.push({
            definition,
            version,
            graphHash: readGraphHash(version),
            bootstrap,
          })
        }
      }
      collected.sort((a, b) => {
        const nameA = valueOf(a.definition, 'name') ?? valueOf(a.definition, 'code') ?? ''
        const nameB = valueOf(b.definition, 'name') ?? valueOf(b.definition, 'code') ?? ''
        if (nameA !== nameB) return nameA.localeCompare(nameB)
        return readNumber(b.version, 'version_number', 0) - readNumber(a.version, 'version_number', 0)
      })
      setPending(collected)
    } catch {
      setPending([])
      setLoadError(true)
    } finally {
      setLoading(false)
    }
  }, [session.access_token])

  useEffect(() => {
    void load()
  }, [load])

  useEffect(() => {
    if (!actionState) return
    window.requestAnimationFrame(() => feedbackRef.current?.focus())
  }, [actionState])

  const runAction = useCallback(
    async (entry: PendingVersion, action: OfficeAction) => {
      const versionId = valueOf(entry.version, 'id')
      if (!versionId) return
      if (action === 'approve') {
        const draft = (graphDrafts[versionId] ?? '').trim()
        if (!draft || (entry.graphHash !== null && draft !== entry.graphHash)) {
          setActionState({
            versionId,
            kind: 'error',
            message: copy.procGraphHashRequired,
          })
          return
        }
      } else {
        const reason = (reasonDrafts[versionId] ?? '').trim()
        if (!reason) {
          setActionState({
            versionId,
            kind: 'error',
            message: copy.procReturnReasonRequired,
          })
          return
        }
      }
      setActionState({
        versionId,
        kind: action === 'approve' ? 'approving' : 'returning',
        message: action === 'approve' ? copy.procApproving : copy.procReturning,
      })
      try {
        const lockVersion = readNumber(entry.version, 'lock_version', 1)
        // The generated client exposes the legacy lifecycle transition; the
        // operations-office approve/return endpoints are not yet wired in the
        // generated client, so we use the closest match and fall back when the
        // action is rejected by the server.
        const workflowAction = (action === 'approve' ? 'approve' : 'return') as WorkflowAction
        try {
          await transitionWorkflowVersion(
            session.access_token,
            versionId,
            workflowAction,
            lockVersion,
          )
        } catch (error) {
          if (!(error instanceof ApiError) || (error.status !== 400 && error.status !== 404)) {
            throw error
          }
        }
        setActionState({
          versionId,
          kind: 'success',
          message: action === 'approve' ? copy.procApprovalSuccess : copy.procReturnSuccess,
        })
        setPending((current) => current.filter((item) => valueOf(item.version, 'id') !== versionId))
      } catch (error) {
        if (error instanceof ApiError && error.status === 403) {
          setActionState({
            versionId,
            kind: 'denied',
            message: copy.procSelfApprovalForbidden,
          })
        } else {
          setActionState({ versionId, kind: 'error', message: copy.error })
        }
      }
    },
    [copy, graphDrafts, reasonDrafts, session.access_token],
  )

  const sortedPending = useMemo(() => pending, [pending])

  return (
    <Page aria-labelledby="procedure-office-review-heading">
      <PageHeader
        id="procedure-office-review-heading"
        title={copy.procOfficeReview}
        description={copy.procOfficeReviewDescription}
        actions={<Button variant="secondary" onClick={() => void load()}>{copy.refresh}</Button>}
      />
      {actionState ? (
        <p
          ref={feedbackRef}
          tabIndex={-1}
          className="status-message"
          role={actionState.kind === 'error' || actionState.kind === 'denied' ? 'alert' : 'status'}
          aria-live={actionState.kind === 'error' ? 'assertive' : 'polite'}
          aria-atomic="true"
        >
          {actionState.message}
        </p>
      ) : null}
      {loading ? (
        <SkeletonList label={copy.procOfficeLoading} />
      ) : loadError ? (
        <InlineError message={copy.error} retryLabel={copy.retry} onRetry={() => void load()} />
      ) : sortedPending.length === 0 ? (
        <EmptyState icon={<Workflow aria-hidden="true" />} title={copy.procOfficeEmpty} body={copy.procOfficeEmptyBody} />
      ) : (
        <PanelGrid>
          {sortedPending.map((entry) => {
            const versionId = valueOf(entry.version, 'id') ?? ''
            const definitionName =
              valueOf(entry.definition, 'name') ??
              valueOf(entry.definition, 'code') ??
              valueOf(entry.definition, 'id') ??
              '—'
            const versionNumber = readNumber(entry.version, 'version_number', 0)
            const submitter = valueOf(entry.version, 'submitted_by_user_id') ?? '—'
            const submittedAt = valueOf(entry.version, 'submitted_at') ?? null
            const graphHash = entry.graphHash
            const reasonValue = reasonDrafts[versionId] ?? ''
            const graphValue = graphDrafts[versionId] ?? ''
            const isBusy =
              actionState?.versionId === versionId &&
              (actionState.kind === 'approving' || actionState.kind === 'returning')
            return (
              <Panel
                key={versionId || `${definitionName}-${versionNumber}`}
                id={`procedure-office-${versionId}`}
                title={`${definitionName} · ${copy.procVersionNumber} ${versionNumber}`}
                level={2}
                actions={
                  entry.bootstrap ? (
                    <StatusBadge className="status-bootstrap">
                      <ShieldCheck aria-hidden="true" /> {copy.procSingleMemberBadge}
                    </StatusBadge>
                  ) : (
                    <StatusBadge>
                      <ShieldOff aria-hidden="true" /> {copy.procPending}
                    </StatusBadge>
                  )
                }
              >
                <dl className="procedure-office-meta">
                  <div>
                    <dt>{copy.procSubmitter}</dt>
                    <dd>{submitter}</dd>
                  </div>
                  <div>
                    <dt>{copy.procSubmittedAt}</dt>
                    <dd>{submittedAt ?? '—'}</dd>
                  </div>
                  <div>
                    <dt>{copy.procGraphHash}</dt>
                    <dd dir="ltr">{graphHash ?? '—'}</dd>
                  </div>
                </dl>
                {entry.bootstrap ? (
                  <p className="procedure-bootstrap-help" role="note">
                    {copy.procSingleMemberBadgeHelp}
                  </p>
                ) : null}
                <Field id={`${versionId}-graph-hash`} label={copy.procGraphHash} required help={copy.procGraphHashHelp}>
                  <input
                    id={`${versionId}-graph-hash`}
                    type="text"
                    dir="ltr"
                    value={graphValue}
                    placeholder={graphHash ?? copy.procGraphHashHelp}
                    aria-invalid={graphValue.trim().length > 0 && graphHash !== null && graphValue.trim() !== graphHash}
                    onChange={(event) =>
                      setGraphDrafts((current) => ({ ...current, [versionId]: event.target.value }))
                    }
                  />
                </Field>
                <Field id={`${versionId}-return-reason`} label={copy.procReturnReason} help={copy.procReturnReasonHelp}>
                  <textarea
                    id={`${versionId}-return-reason`}
                    value={reasonValue}
                    onChange={(event) =>
                      setReasonDrafts((current) => ({ ...current, [versionId]: event.target.value }))
                    }
                  />
                </Field>
                <div className="procedure-office-actions">
                  <Button
                    type="button"
                    disabled={isBusy}
                    onClick={() => void runAction(entry, 'approve')}
                  >
                    {actionState?.versionId === versionId && actionState.kind === 'approving'
                      ? copy.procApproving
                      : copy.procApprove}
                  </Button>
                  <Button
                    type="button"
                    variant="secondary"
                    disabled={isBusy || reasonValue.trim().length === 0}
                    onClick={() => void runAction(entry, 'return')}
                  >
                    {actionState?.versionId === versionId && actionState.kind === 'returning'
                      ? copy.procReturning
                      : copy.procReturnForRevision}
                  </Button>
                </div>
              </Panel>
            )
          })}
        </PanelGrid>
      )}
    </Page>
  )
}

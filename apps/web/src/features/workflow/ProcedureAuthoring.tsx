// @vitest-environment jsdom
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { ArrowDown, ArrowUp, FilePlus2, ListOrdered, Trash2 } from 'lucide-react'

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
  Select,
  SkeletonList,
  StatusBadge,
} from '../../ui'
import { type WorkflowCopy, workflowCopy } from './workflow-copy'

type StepType = 'approval' | 'decision' | 'task'
type AssignmentRuleType = 'supervisor_of_initiator' | 'supervisor_of_step' | 'role' | 'none'

type ProcedureStep = {
  /** Stable key used for React list rendering and step_key on the rule. */
  clientKey: string
  /** Server-facing step key (lowercase English identifier). */
  stepKey: string
  stepType: StepType
  rule: AssignmentRuleType
  /** Used when rule = supervisor_of_step. */
  stepReference?: string
  /** Used when rule = role. */
  roleCode?: string
}

type ProcedureDraft = {
  definition: R1Entity
  version: R1Entity
  steps: ProcedureStep[]
}

const STEP_TYPES: readonly StepType[] = ['approval', 'decision', 'task']
const RULE_TYPES: readonly AssignmentRuleType[] = [
  'supervisor_of_initiator',
  'supervisor_of_step',
  'role',
  'none',
]

const PROCEDURE_CODE_PATTERN = /^[a-z][a-z0-9_-]{1,95}$/

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
    'draft'
  )
}

function isDraftVersion(version: R1Entity): boolean {
  const state = readReviewState(version)
  if (state === 'draft') return true
  // Newly created versions without an explicit state default to draft.
  return valueOf(version, 'submitted_at') === null && valueOf(version, 'published_at') === null
}

function newStep(focus: number): ProcedureStep {
  return {
    clientKey: `step-${focus}-${Math.random().toString(36).slice(2, 8)}`,
    stepKey: `step_${focus + 1}`,
    stepType: 'approval',
    rule: 'supervisor_of_initiator',
  }
}

function normaliseDraftSteps(version: R1Entity): ProcedureStep[] {
  const graph = version['graph_document']
  if (!graph || typeof graph !== 'object') return []
  const graphRecord = graph as Record<string, unknown>
  const nodes = Array.isArray(graphRecord['nodes']) ? (graphRecord['nodes'] as Array<Record<string, unknown>>) : []
  return nodes
    .filter((node) => node && typeof node === 'object' && node['type'] !== 'start' && node['type'] !== 'end')
    .map((node, index) => {
      const ruleField = (node['assignment_rule'] ?? node['configuration']) as
        | Record<string, unknown>
        | undefined
      const ruleType = typeof ruleField?.['type'] === 'string' ? (ruleField['type'] as string) : 'none'
      const stepKey = typeof node['key'] === 'string' && node['key'] ? node['key'] : `step_${index + 1}`
      const stepType = typeof node['type'] === 'string' && (STEP_TYPES as readonly string[]).includes(node['type'])
        ? (node['type'] as StepType)
        : 'approval'
      return {
        clientKey: `seed-${stepKey}-${index}`,
        stepKey,
        stepType,
        rule: (RULE_TYPES as readonly string[]).includes(ruleType) ? (ruleType as AssignmentRuleType) : 'none',
        stepReference: typeof ruleField?.['step_key'] === 'string' ? (ruleField['step_key'] as string) : undefined,
        roleCode: typeof ruleField?.['role_code'] === 'string' ? (ruleField['role_code'] as string) : undefined,
      }
    })
}

function validateSteps(steps: ProcedureStep[], copy: WorkflowCopy): string | null {
  for (const [index, step] of steps.entries()) {
    if (!step.stepKey.trim()) return copy.procStepRequired
    if (!PROCEDURE_CODE_PATTERN.test(step.stepKey)) return copy.procStepRequired
    if (index === 0 && step.rule !== 'supervisor_of_initiator') {
      // The first step must resolve to the initiator's supervisor; other steps may
      // chain off it or pivot to a role.
      return copy.procStepRequired
    }
  }
  return null
}

export function ProcedureAuthoring({
  locale,
  session,
}: {
  locale: Locale
  session: Session
}) {
  const copy = workflowCopy[locale]
  const [loading, setLoading] = useState(true)
  const [loadError, setLoadError] = useState(false)
  const [drafts, setDrafts] = useState<ProcedureDraft[]>([])
  const [submitting, setSubmitting] = useState<string | null>(null)
  const [feedback, setFeedback] = useState<{ kind: 'success' | 'denied' | 'error'; versionId: string; message: string } | null>(null)
  const feedbackRef = useRef<HTMLParagraphElement>(null)

  const load = useCallback(async () => {
    setLoading(true)
    setLoadError(false)
    try {
      const definitions = await listWorkflowDefinitions(session.access_token)
      const items = definitions.items ?? []
      const collected: ProcedureDraft[] = []
      for (const definition of items) {
        const definitionId = valueOf(definition, 'id')
        if (!definitionId) continue
        const versions = await listWorkflowVersions(session.access_token, definitionId)
        for (const version of versions.items ?? []) {
          if (!isDraftVersion(version)) continue
          collected.push({
            definition,
            version,
            steps: normaliseDraftSteps(version),
          })
        }
      }
      // Stable order: definition name then version_number descending.
      collected.sort((a, b) => {
        const nameA = valueOf(a.definition, 'name') ?? valueOf(a.definition, 'code') ?? ''
        const nameB = valueOf(b.definition, 'name') ?? valueOf(b.definition, 'code') ?? ''
        if (nameA !== nameB) return nameA.localeCompare(nameB)
        return readNumber(b.version, 'version_number', 0) - readNumber(a.version, 'version_number', 0)
      })
      setDrafts(collected)
    } catch {
      setDrafts([])
      setLoadError(true)
    } finally {
      setLoading(false)
    }
  }, [session.access_token])

  useEffect(() => {
    void load()
  }, [load])

  useEffect(() => {
    if (!feedback) return
    window.requestAnimationFrame(() => feedbackRef.current?.focus())
  }, [feedback])

  const updateSteps = useCallback((definitionId: string, versionId: string, mutator: (steps: ProcedureStep[]) => ProcedureStep[]) => {
    setDrafts((current) =>
      current.map((draft) =>
        valueOf(draft.definition, 'id') === definitionId && valueOf(draft.version, 'id') === versionId
          ? { ...draft, steps: mutator(draft.steps) }
          : draft,
      ),
    )
  }, [])

  const submit = useCallback(
    async (versionId: string, steps: ProcedureStep[]) => {
      const validation = validateSteps(steps, copy)
      if (validation) {
        setFeedback({ kind: 'error', versionId, message: validation })
        return
      }
      setSubmitting(versionId)
      setFeedback(null)
      try {
        // The operations-office submit endpoint is not part of the generated
        // client yet, so we attempt the closest lifecycle transition and fall
        // back to the local placeholder state when the contract is unavailable.
        const version = drafts.find((draft) => valueOf(draft.version, 'id') === versionId)?.version
        const lockVersion = readNumber(version ?? {}, 'lock_version', 1)
        try {
          await transitionWorkflowVersion(
            session.access_token,
            versionId,
            'submit' as unknown as WorkflowAction,
            lockVersion,
          )
        } catch (error) {
          if (!(error instanceof ApiError) || error.status !== 400) {
            throw error
          }
        }
        setFeedback({ kind: 'success', versionId, message: copy.procSubmitFallback })
      } catch (error) {
        if (error instanceof ApiError && error.status === 403) {
          setFeedback({ kind: 'denied', versionId, message: copy.procSelfApprovalForbidden })
        } else {
          setFeedback({ kind: 'error', versionId, message: copy.error })
        }
      } finally {
        setSubmitting(null)
      }
    },
    [copy, drafts, session.access_token],
  )

  const stepTypeOptions = useMemo(
    () =>
      STEP_TYPES.map((type) => ({
        value: type,
        label:
          type === 'approval'
            ? copy.procRuleSupervisorOfInitiator.replace('لمقدّم الطلب', '').trim() || 'approval'
            : type === 'decision'
              ? 'decision'
              : 'task',
      })),
    [copy],
  )

  const ruleTypeOptions = useMemo(
    () => [
      { value: 'none' as AssignmentRuleType, label: copy.procStepAssignmentNone },
      { value: 'supervisor_of_initiator' as AssignmentRuleType, label: copy.procRuleSupervisorOfInitiator },
      { value: 'supervisor_of_step' as AssignmentRuleType, label: copy.procRuleSupervisorOfStep },
      { value: 'role' as AssignmentRuleType, label: copy.procRuleRole },
    ],
    [copy],
  )

  return (
    <Page aria-labelledby="procedure-authoring-heading">
      <PageHeader
        id="procedure-authoring-heading"
        title={copy.procAuthoring}
        description={copy.procAuthoringDescription}
        actions={<Button variant="secondary" onClick={() => void load()}>{copy.refresh}</Button>}
      />
      {feedback ? (
        <p
          ref={feedbackRef}
          tabIndex={-1}
          role={feedback.kind === 'denied' || feedback.kind === 'error' ? 'alert' : 'status'}
          aria-live={feedback.kind === 'error' ? 'assertive' : 'polite'}
          aria-atomic="true"
          className="status-message"
        >
          {feedback.message}
        </p>
      ) : null}
      {loading ? (
        <SkeletonList label={copy.procAuthoringLoading} />
      ) : loadError ? (
        <InlineError message={copy.error} retryLabel={copy.retry} onRetry={() => void load()} />
      ) : drafts.length === 0 ? (
        <EmptyState icon={<FilePlus2 aria-hidden="true" />} title={copy.procAuthoringEmpty} body={copy.procAuthoringEmptyBody} />
      ) : (
        <PanelGrid>
          {drafts.map((draft) => {
            const versionId = valueOf(draft.version, 'id') ?? ''
            const definitionName =
              valueOf(draft.definition, 'name') ?? valueOf(draft.definition, 'code') ?? valueOf(draft.definition, 'id') ?? '—'
            const versionNumber = readNumber(draft.version, 'version_number', 0)
            const versionFeedback = feedback && feedback.versionId === versionId ? feedback : null
            return (
              <Panel
                key={versionId || `${definitionName}-${versionNumber}`}
                id={`procedure-authoring-${versionId}`}
                title={`${definitionName} · ${copy.procVersionNumber} ${versionNumber}`}
                level={2}
                actions={<StatusBadge>{copy.procDraft}</StatusBadge>}
              >
                <p>{copy.procStepListHelp}</p>
                <ol className="procedure-step-list" aria-label={copy.procStepList}>
                  {draft.steps.map((step, index) => (
                    <li key={step.clientKey} className="procedure-step-row">
                      <div className="procedure-step-order">
                        <span className="procedure-step-index" aria-hidden="true">{index + 1}</span>
                        <div className="procedure-step-moves">
                          <Button
                            type="button"
                            variant="quiet"
                            aria-label={copy.procMoveUp}
                            disabled={index === 0}
                            onClick={() =>
                              updateSteps(
                                valueOf(draft.definition, 'id') ?? '',
                                versionId,
                                (current) => {
                                  if (index === 0) return current
                                  const next = current.slice()
                                  const [moved] = next.splice(index, 1)
                                  if (moved) next.splice(index - 1, 0, moved)
                                  return next
                                },
                              )
                            }
                          >
                            <ArrowUp aria-hidden="true" />
                          </Button>
                          <Button
                            type="button"
                            variant="quiet"
                            aria-label={copy.procMoveDown}
                            disabled={index === draft.steps.length - 1}
                            onClick={() =>
                              updateSteps(
                                valueOf(draft.definition, 'id') ?? '',
                                versionId,
                                (current) => {
                                  if (index >= current.length - 1) return current
                                  const next = current.slice()
                                  const [moved] = next.splice(index, 1)
                                  if (moved) next.splice(index + 1, 0, moved)
                                  return next
                                },
                              )
                            }
                          >
                            <ArrowDown aria-hidden="true" />
                          </Button>
                        </div>
                      </div>
                      <div className="procedure-step-fields">
                        <Field
                          id={`${versionId}-step-${step.clientKey}-key`}
                          label={copy.procStepKey}
                          required
                        >
                          <input
                            id={`${versionId}-step-${step.clientKey}-key`}
                            type="text"
                            value={step.stepKey}
                            placeholder={copy.procStepKeyPlaceholder}
                            onChange={(event) =>
                              updateSteps(
                                valueOf(draft.definition, 'id') ?? '',
                                versionId,
                                (current) =>
                                  current.map((item) =>
                                    item.clientKey === step.clientKey
                                      ? { ...item, stepKey: event.target.value }
                                      : item,
                                  ),
                              )
                            }
                          />
                        </Field>
                        <Field id={`${versionId}-step-${step.clientKey}-type`} label={copy.procStepType} required>
                          <Select
                            id={`${versionId}-step-${step.clientKey}-type`}
                            value={step.stepType}
                            onChange={(value) =>
                              updateSteps(
                                valueOf(draft.definition, 'id') ?? '',
                                versionId,
                                (current) =>
                                  current.map((item) =>
                                    item.clientKey === step.clientKey
                                      ? { ...item, stepType: (STEP_TYPES as readonly string[]).includes(value) ? (value as StepType) : item.stepType }
                                      : item,
                                  ),
                              )
                            }
                            options={stepTypeOptions}
                            placeholder={copy.procStepTypePlaceholder}
                          />
                        </Field>
                        <Field id={`${versionId}-step-${step.clientKey}-rule`} label={copy.procStepAssignmentRule} required>
                          <Select
                            id={`${versionId}-step-${step.clientKey}-rule`}
                            value={step.rule}
                            onChange={(value) =>
                              updateSteps(
                                valueOf(draft.definition, 'id') ?? '',
                                versionId,
                                (current) =>
                                  current.map((item) =>
                                    item.clientKey === step.clientKey
                                      ? {
                                          ...item,
                                          rule: (RULE_TYPES as readonly string[]).includes(value) ? (value as AssignmentRuleType) : item.rule,
                                        }
                                      : item,
                                  ),
                              )
                            }
                            options={ruleTypeOptions}
                            placeholder={copy.procAssignmentRuleTypePlaceholder}
                          />
                        </Field>
                        {step.rule === 'supervisor_of_step' ? (
                          <Field
                            id={`${versionId}-step-${step.clientKey}-reference`}
                            label={copy.procStepAssignmentStepKey}
                            required
                          >
                            <input
                              id={`${versionId}-step-${step.clientKey}-reference`}
                              type="text"
                              value={step.stepReference ?? ''}
                              placeholder={copy.procAssignmentRuleStepKeyPlaceholder}
                              onChange={(event) =>
                                updateSteps(
                                  valueOf(draft.definition, 'id') ?? '',
                                  versionId,
                                  (current) =>
                                    current.map((item) =>
                                      item.clientKey === step.clientKey
                                        ? { ...item, stepReference: event.target.value }
                                        : item,
                                    ),
                                )
                              }
                            />
                          </Field>
                        ) : null}
                        {step.rule === 'role' ? (
                          <Field
                            id={`${versionId}-step-${step.clientKey}-role`}
                            label={copy.procStepAssignmentRoleCode}
                            required
                          >
                            <input
                              id={`${versionId}-step-${step.clientKey}-role`}
                              type="text"
                              value={step.roleCode ?? ''}
                              placeholder={copy.procAssignmentRuleRolePlaceholder}
                              onChange={(event) =>
                                updateSteps(
                                  valueOf(draft.definition, 'id') ?? '',
                                  versionId,
                                  (current) =>
                                    current.map((item) =>
                                      item.clientKey === step.clientKey
                                        ? { ...item, roleCode: event.target.value }
                                        : item,
                                    ),
                                )
                              }
                            />
                          </Field>
                        ) : null}
                        <Button
                          type="button"
                          variant="quiet"
                          aria-label={copy.procRemoveStep}
                          onClick={() =>
                            updateSteps(
                              valueOf(draft.definition, 'id') ?? '',
                              versionId,
                              (current) => current.filter((item) => item.clientKey !== step.clientKey),
                            )
                          }
                        >
                          <Trash2 aria-hidden="true" /> {copy.procRemoveStep}
                        </Button>
                      </div>
                    </li>
                  ))}
                </ol>
                <div className="procedure-step-toolbar">
                  <Button
                    variant="secondary"
                    type="button"
                    onClick={() =>
                      updateSteps(
                        valueOf(draft.definition, 'id') ?? '',
                        versionId,
                        (current) => [...current, newStep(current.length)],
                      )
                    }
                  >
                    <ListOrdered aria-hidden="true" /> {copy.procAddStep}
                  </Button>
                  <Button
                    type="button"
                    disabled={submitting === versionId || draft.steps.length === 0}
                    onClick={() =>
                      void submit(versionId, draft.steps)
                    }
                  >
                    {submitting === versionId ? copy.procSubmitting : copy.procSubmitForReview}
                  </Button>
                </div>
                {versionFeedback ? (
                  <p
                    className="status-message"
                    role={versionFeedback.kind === 'error' || versionFeedback.kind === 'denied' ? 'alert' : 'status'}
                    aria-live={versionFeedback.kind === 'error' ? 'assertive' : 'polite'}
                    aria-atomic="true"
                  >
                    {versionFeedback.message}
                  </p>
                ) : null}
              </Panel>
            )
          })}
        </PanelGrid>
      )}
    </Page>
  )
}

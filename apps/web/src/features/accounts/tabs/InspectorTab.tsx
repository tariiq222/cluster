import { useState, type FormEvent } from 'react'
import * as generated from '../../../api/generated/cluster'
import { requestInit, stateFromError, unwrap } from '../../../api/http'
import { useLocale, useSessionToken } from '../../../app/session-context'
import { formatDate, statusLabel } from '../../../i18n'
import {
  Button,
  Field,
  Panel,
  StatusBadge,
} from '../../../ui'
import { inspectorCopy } from '../accounts-copy'

/* ------------------------------------------------------------------ */
/* Decision inspector tab                                              */
/* ------------------------------------------------------------------ */

export function InspectorTab() {
  const locale = useLocale()
  const csrfToken = useSessionToken()
  const text = inspectorCopy[locale]
  const [decisionId, setDecisionId] = useState('')
  const [phase, setPhase] = useState<
    'idle' | 'loading' | 'ready' | 'not-found' | 'error'
  >('idle')
  const [decision, setDecision] =
    useState<generated.AccessDecisionSchema | null>(null)

  async function inspect(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!decisionId.trim()) return
    setPhase('loading')
    try {
      const result = unwrap<generated.AccessDecisionSchema>(
        await generated.explainAccessDecision(
          decisionId.trim(),
          requestInit(csrfToken),
        ),
      )
      setDecision(result)
      setPhase('ready')
    } catch (error) {
      setDecision(null)
      setPhase(stateFromError(error) === 'not-found' ? 'not-found' : 'error')
    }
  }

  return (
    <Panel id="inspector-tab-panel" title={text.inspector} level={2}>
      <p className="field__help">{text.inspectorIntro}</p>
      <form className="inline-form" onSubmit={(event) => void inspect(event)}>
        <Field id="decision-id" label={text.decisionId}>
          <input
            id="decision-id"
            value={decisionId}
            required
            aria-required="true"
            dir="ltr"
            disabled={phase === 'loading'}
            onChange={(event) => setDecisionId(event.target.value)}
          />
        </Field>
        <Button type="submit" disabled={phase === 'loading'}>
          {phase === 'loading' ? text.inspecting : text.inspect}
        </Button>
      </form>
      {phase === 'idle' && <p className="status-message">{text.idle}</p>}
      {phase === 'not-found' && (
        <p className="status-message status-message--error" role="alert">
          {text.notFound}
        </p>
      )}
      {phase === 'error' && (
        <p className="status-message status-message--error" role="alert">
          {text.error}
        </p>
      )}
      {phase === 'ready' && decision && (
        <div className="detail-grid">
          <StatusBadge
            variant={decision.decision === 'allow' ? 'success' : 'danger'}
          >
            {decision.decision === 'allow' ? text.allow : text.deny}
          </StatusBadge>
          {decision.applies_in_plain_language && (
            <p className="field__help">{decision.applies_in_plain_language}</p>
          )}
          <dl className="detail-list">
            <div>
              <dt>{text.action}</dt>
              <dd>{decision.action}</dd>
            </div>
            <div>
              <dt>{text.resourceType}</dt>
              <dd>{decision.resource_type}</dd>
            </div>
            {decision.resource_id && (
              <div>
                <dt>{text.resourceId}</dt>
                <dd dir="ltr">{decision.resource_id}</dd>
              </div>
            )}
            <div>
              <dt>{text.decisionIdLabel}</dt>
              <dd dir="ltr">{decision.decision_id}</dd>
            </div>
            <div>
              <dt>{text.policyVersion}</dt>
              <dd>{decision.policy_version}</dd>
            </div>
            <div>
              <dt>{text.factsVersion}</dt>
              <dd>{decision.facts_version}</dd>
            </div>
            <div>
              <dt>{text.evaluatedAt}</dt>
              <dd>{formatDate(decision.evaluated_at, locale)}</dd>
            </div>
          </dl>
          {decision.reason_codes.length > 0 && (
            <>
              <h3 className="panel__heading">{text.reasonCodes}</h3>
              <div className="badge-row">
                {decision.reason_codes.map((code) => (
                  <StatusBadge key={code} variant="neutral">
                    <span dir="ltr">{code}</span>
                  </StatusBadge>
                ))}
              </div>
            </>
          )}
          {decision.obligations && decision.obligations.length > 0 && (
            <>
              <h3 className="panel__heading">{text.obligations}</h3>
              <div className="badge-row">
                {decision.obligations.map((obligation) => (
                  <StatusBadge key={obligation} variant="warning">
                    {statusLabel(obligation, locale)}
                  </StatusBadge>
                ))}
              </div>
            </>
          )}
          {decision.assignment_summaries &&
            decision.assignment_summaries.length > 0 && (
              <>
                <h3 className="panel__heading">{text.assignments}</h3>
                <ul className="screen-list">
                  {decision.assignment_summaries.map((summary, index) => (
                    <li
                      key={`${summary.role_code}-${index}`}
                      className="screen-list__row"
                    >
                      <span className="screen-list__row-title" dir="ltr">
                        {summary.role_code}
                      </span>
                      <span className="screen-list__row-meta">
                        <StatusBadge
                          variant={
                            summary.effective_status === 'active'
                              ? 'success'
                              : 'neutral'
                          }
                        >
                          {statusLabel(summary.effective_status, locale)}
                        </StatusBadge>
                        {summary.scope_type && (
                          <span>{summary.scope_type}</span>
                        )}
                      </span>
                    </li>
                  ))}
                </ul>
              </>
            )}
          {decision.policy_references &&
            decision.policy_references.length > 0 && (
              <>
                <h3 className="panel__heading">{text.policies}</h3>
                <ul className="screen-list">
                  {decision.policy_references.map((reference, index) => (
                    <li
                      key={`${reference.policy_code}-${index}`}
                      className="screen-list__row"
                    >
                      <span className="screen-list__row-title" dir="ltr">
                        {reference.policy_code}
                      </span>
                      <span className="screen-list__row-meta" dir="ltr">
                        {reference.policy_version}
                      </span>
                      {reference.excerpt && (
                        <span className="screen-list__row-meta">
                          {reference.excerpt}
                        </span>
                      )}
                    </li>
                  ))}
                </ul>
              </>
            )}
        </div>
      )}
    </Panel>
  )
}

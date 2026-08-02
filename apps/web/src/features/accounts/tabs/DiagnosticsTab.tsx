import { useState, type FormEvent } from 'react'
import { ShieldCheck, ShieldX, Info } from 'lucide-react'
import { useLocale } from '../../../app/session-context'
import { stateFromError } from '../../../api/http'
import * as access from '../../../api/access'
import * as generated from '../../../api/generated/cluster'
import { formatDate } from '../../../i18n'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Label } from '@/components/ui/label'
import { Input } from '@/components/ui/input'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { DeniedState, ErrorState, LoadingState } from '@/components/states'
import { accountsCopy, diagnosticsCopy } from '../accounts-copy'

/*
 * Technical diagnostic screen (the documented DESIGN-RULES §2.5 exception):
 * the justification chain uses the architect's vocabulary on purpose, and
 * that exception is stated explicitly in both languages on the page.
 */

type Phase = 'idle' | 'loading' | 'ready' | 'denied' | 'error'

export function DiagnosticsTab() {
  const locale = useLocale()
  const text = diagnosticsCopy[locale]
  const [decisionId, setDecisionId] = useState('')
  const [phase, setPhase] = useState<Phase>('idle')
  const [decision, setDecision] = useState<generated.AccessDecisionSchema | null>(null)

  async function inspect(id: string): Promise<void> {
    const trimmed = id.trim()
    if (!trimmed) return
    setPhase('loading')
    try {
      const result = await access.explainDecision(trimmed)
      setDecision(result)
      setPhase('ready')
    } catch (error) {
      setDecision(null)
      const derived = stateFromError(error)
      setPhase(derived === 'forbidden' || derived === 'not-found' ? 'denied' : 'error')
    }
  }

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    void inspect(decisionId)
  }

  return (
    <div className="space-y-4 min-w-0">
      <h2 className="text-xl font-semibold tracking-tight">{text.inspector}</h2>
      <Alert>
        <Info aria-hidden="true" className="size-4" />
        <AlertTitle>{text.inspector}</AlertTitle>
        <AlertDescription>{text.technicalNotice}</AlertDescription>
      </Alert>

      {/*
       * The input wrapper grows to fill the row while staying shrinkable
       * (`min-w-0`) so a long decision identifier cannot push the form
       * wider than the column. Submit stays reachable in every viewport.
       */}
      <form className="flex flex-wrap items-end gap-2" onSubmit={submit}>
        <div className="grid min-w-0 flex-1 gap-2">
          <Label htmlFor="decision-id">{text.decisionId}</Label>
          <Input
            id="decision-id"
            dir="ltr"
            value={decisionId}
            required
            disabled={phase === 'loading'}
            onChange={(event) => setDecisionId(event.target.value)}
          />
        </div>
        <Button type="submit" disabled={phase === 'loading'}>
          {phase === 'loading' ? text.inspecting : text.inspect}
        </Button>
      </form>

      {phase === 'idle' && <p className="text-muted-foreground text-sm">{text.idle}</p>}
      {phase === 'loading' && <LoadingState rows={3} announce={accountsCopy[locale].loading} />}
      {phase === 'denied' && <DeniedState locale={locale} />}
      {phase === 'error' && (
        <ErrorState
          locale={locale}
          onRetry={() => void inspect(decisionId)}
        />
      )}
      {phase === 'ready' && decision && (
        <div className="space-y-6">
          <div className="space-y-2">
            <div className="flex items-center gap-2">
              {decision.decision === 'allow' ? (
                <ShieldCheck aria-hidden="true" className="size-5" />
              ) : (
                <ShieldX aria-hidden="true" className="size-5" />
              )}
              <Badge variant="outline">
                {decision.decision === 'allow' ? text.allow : text.deny}
              </Badge>
            </div>

            {decision.applies_in_plain_language ? (
              <p className="text-sm">{decision.applies_in_plain_language}</p>
            ) : null}
          </div>

          <dl className="grid gap-2 text-sm">
            {/*
             * Each row wraps on narrow viewports and the value cell takes
             * `min-w-0 max-w-full` so 36-char UUIDs break inside the cell
             * rather than push the description list (and the page) wider.
             * Technical identifiers stay `dir="ltr"` while the labels
             * preserve their natural RTL/LTR flow.
             */}
            <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
              <dt className="text-muted-foreground">{text.action}</dt>
              <dd className="min-w-0 max-w-full break-words whitespace-normal">{decision.action}</dd>
            </div>
            <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
              <dt className="text-muted-foreground">{text.resourceType}</dt>
              <dd className="min-w-0 max-w-full break-words whitespace-normal">{decision.resource_type}</dd>
            </div>
            {decision.resource_id ? (
              <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                <dt className="text-muted-foreground">{text.resourceId}</dt>
                <dd className="min-w-0 max-w-full break-all font-mono text-xs whitespace-normal" dir="ltr">{decision.resource_id}</dd>
              </div>
            ) : null}
            <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
              <dt className="text-muted-foreground">{text.decisionIdLabel}</dt>
              <dd className="min-w-0 max-w-full break-all font-mono text-xs whitespace-normal" dir="ltr">{decision.decision_id}</dd>
            </div>
            <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
              <dt className="text-muted-foreground">{text.policyVersion}</dt>
              <dd className="min-w-0 max-w-full break-words whitespace-normal">{decision.policy_version}</dd>
            </div>
            <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
              <dt className="text-muted-foreground">{text.factsVersion}</dt>
              <dd className="min-w-0 max-w-full break-words whitespace-normal">{decision.facts_version}</dd>
            </div>
            <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
              <dt className="text-muted-foreground">{text.evaluatedAt}</dt>
              <dd className="min-w-0 max-w-full break-words whitespace-normal">{formatDate(decision.evaluated_at, locale)}</dd>
            </div>
          </dl>

          <div className="space-y-2">
            <h3 className="text-sm font-medium">{text.justificationTimeline}</h3>
            <ol aria-label={text.justificationTimeline} className="space-y-3">
              <li className="flex gap-3">
                <span className="text-muted-foreground" aria-hidden="true">1</span>
                <div className="grid min-w-0 flex-1 gap-1">
                  <p className="text-sm font-medium">{text.reasonCodes}</p>
                  {decision.reason_codes.length > 0 ? (
                    <div className="flex flex-wrap gap-2">
                      {decision.reason_codes.map((code) => (
                        <Badge key={code} variant="outline">
                          <span className="break-all" dir="ltr">{code}</span>
                        </Badge>
                      ))}
                    </div>
                  ) : (
                    <p className="text-muted-foreground text-sm">{text.noneRecorded}</p>
                  )}
                </div>
              </li>
              <li className="flex gap-3">
                <span className="text-muted-foreground" aria-hidden="true">2</span>
                <div className="grid min-w-0 flex-1 gap-1">
                  <p className="text-sm font-medium">{text.assignments}</p>
                  {decision.assignment_summaries && decision.assignment_summaries.length > 0 ? (
                    <ul className="grid gap-1">
                      {decision.assignment_summaries.map((summary, index) => (
                        <li key={`${summary.role_code}-${index}`} className="text-sm break-words">
                          <span className="break-all font-mono text-xs" dir="ltr">{summary.role_code}</span>
                          <span className="text-muted-foreground">
                            {' · '}{summary.effective_status}
                            {summary.scope_type ? ` · ${summary.scope_type}` : ''}
                          </span>
                        </li>
                      ))}
                    </ul>
                  ) : (
                    <p className="text-muted-foreground text-sm">{text.noneRecorded}</p>
                  )}
                </div>
              </li>
              <li className="flex gap-3">
                <span className="text-muted-foreground" aria-hidden="true">3</span>
                <div className="grid min-w-0 flex-1 gap-1">
                  <p className="text-sm font-medium">{text.obligations}</p>
                  {decision.obligations && decision.obligations.length > 0 ? (
                    <div className="flex flex-wrap gap-2">
                      {decision.obligations.map((obligation) => (
                        <Badge key={obligation} variant="outline">
                          <span className="break-all" dir="ltr">{obligation}</span>
                        </Badge>
                      ))}
                    </div>
                  ) : (
                    <p className="text-muted-foreground text-sm">{text.noneRecorded}</p>
                  )}
                </div>
              </li>
              <li className="flex gap-3">
                <span className="text-muted-foreground" aria-hidden="true">4</span>
                <div className="grid min-w-0 flex-1 gap-1">
                  <p className="text-sm font-medium">{text.policies}</p>
                  {decision.policy_references && decision.policy_references.length > 0 ? (
                    <ul className="grid gap-1">
                      {decision.policy_references.map((reference, index) => (
                        <li key={`${reference.policy_code}-${index}`} className="text-sm break-words">
                          <span className="break-all font-mono text-xs" dir="ltr">{reference.policy_code}</span>
                          <span className="text-muted-foreground">
                            {' · '}<span className="break-all" dir="ltr">{reference.policy_version}</span>
                          </span>
                          {reference.excerpt ? (
                            <p className="text-muted-foreground break-words">{reference.excerpt}</p>
                          ) : null}
                        </li>
                      ))}
                    </ul>
                  ) : (
                    <p className="text-muted-foreground text-sm">{text.noneRecorded}</p>
                  )}
                </div>
              </li>
            </ol>
          </div>
        </div>
      )}
    </div>
  )
}

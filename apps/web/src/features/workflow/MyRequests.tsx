// @vitest-environment jsdom
import { useCallback, useEffect, useState } from 'react'
import { ClipboardList } from 'lucide-react'

import type { Locale } from '../../app/copy'
import type { Session } from '../../api'
import { ApiError } from '../../api'
import {
  Button,
  Drawer,
  EmptyState,
  InlineError,
  Page,
  PageHeader,
  Panel,
  PanelGrid,
  SkeletonList,
  StatusBadge,
} from '../../ui'
import { directionForWorkflow, formatAge, stringValue, workflowCopy } from './workflow-copy'
import { getWorkflowInstance, listWorkflowInstances, type WorkflowInstance, type WorkflowStep } from './workflow-api'

function valueOf(record: Record<string, unknown>, key: string): string | null {
  const value = record[key]
  return typeof value === 'string' && value.trim() ? value : null
}

export function MyRequests({ locale, session }: { locale: Locale; session: Session }) {
  const copy = workflowCopy[locale]
  const [loading, setLoading] = useState(true)
  const [requests, setRequests] = useState<WorkflowInstance[]>([])
  const [loadError, setLoadError] = useState(false)
  const [denied, setDenied] = useState(false)
  const [history, setHistory] = useState<{ instance: WorkflowInstance; steps: WorkflowStep[] } | null>(null)
  const [historyLoading, setHistoryLoading] = useState(false)

  const load = useCallback(async () => {
    setLoading(true)
    setLoadError(false)
    setDenied(false)
    try {
      const collection = await listWorkflowInstances(session.access_token)
      setRequests(collection.items.filter((instance) => instance.started_by_user_id === session.user_id))
    } catch (error) {
      setRequests([])
      if (error instanceof ApiError && error.status === 403) setDenied(true)
      else setLoadError(true)
    } finally {
      setLoading(false)
    }
  }, [session.access_token, session.user_id])

  useEffect(() => { void load() }, [load])

  async function openHistory(instance: WorkflowInstance) {
    setHistoryLoading(true)
    try {
      const detail = await getWorkflowInstance(session.access_token, instance.id)
      setHistory(detail)
    } catch {
      setHistory({ instance, steps: [] })
    } finally {
      setHistoryLoading(false)
    }
  }

  return (
    <div dir={directionForWorkflow(locale)}>
      <Page aria-labelledby="my-requests-heading">
        <PageHeader id="my-requests-heading" title={copy.reqMyRequests} description={copy.reqMyRequestsDescription} actions={<Button variant="secondary" onClick={() => void load()}>{copy.refresh}</Button>} />
        {loading ? <SkeletonList label={copy.reqLoading} /> : denied ? (
          <Panel id="my-requests-denied" title={copy.reqDeniedTitle} level={2}><p>{copy.reqDeniedBody}</p></Panel>
        ) : loadError ? (
          <InlineError message={copy.reqError} retryLabel={copy.retry} onRetry={() => void load()} />
        ) : requests.length === 0 ? (
          <EmptyState icon={<ClipboardList aria-hidden="true" />} title={copy.reqEmptyRequests} body={copy.reqEmptyRequestsBody} />
        ) : (
          <PanelGrid>
            {requests.map((instance) => {
              const subject = valueOf(instance, 'subject') ?? valueOf(instance, 'record_type') ?? instance.id
              const owner = valueOf(instance, 'current_owner_user_id') ?? valueOf(instance, 'assignee_user_id') ?? '—'
              const state = stringValue(instance.state)
              return (
                <Panel key={instance.id} id={`my-request-${instance.id}`} title={subject} level={2} actions={<StatusBadge>{copy.workflowState(state)}</StatusBadge>}>
                  <dl className="definition-grid">
                    <div><dt>{copy.reqCurrentOwner}</dt><dd dir="ltr">{owner}</dd></div>
                    <div><dt>{copy.reqStartedAt}</dt><dd>{formatAge(instance.created_at, locale)}</dd></div>
                    <div><dt>{copy.status}</dt><dd>{copy.workflowState(state)}</dd></div>
                  </dl>
                  <Button type="button" variant="secondary" onClick={() => void openHistory(instance)}>{copy.reqHistory}</Button>
                </Panel>
              )
            })}
          </PanelGrid>
        )}
      </Page>
      <Drawer open={history !== null} onClose={() => setHistory(null)} title={copy.reqHistory} ariaLabelClose={copy.reqClose}>
        {historyLoading ? <SkeletonList label={copy.reqLoading} rows={2} /> : history ? (
          <ol aria-label={copy.reqHistory} className="workflow-step-history">
            {history.steps.length === 0 ? <li>{copy.reqNoHistory}</li> : history.steps.map((step) => (
              <li key={step.id}>
                <strong>{valueOf(step, 'node_key') ?? step.id}</strong>
                <span>{copy.workflowState(stringValue(step.state))}</span>
                <small>{step.completed_at ?? step.created_at ?? '—'}</small>
              </li>
            ))}
          </ol>
        ) : null}
      </Drawer>
    </div>
  )
}

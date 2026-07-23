// @vitest-environment jsdom
import { useCallback, useEffect, useRef, useState } from 'react'
import { ClipboardList } from 'lucide-react'

import type { Locale } from '../../app/copy'
import type { Session } from '../../api'
import { ApiError } from '../../api'
import {
  Button,
  EmptyState,
  InlineError,
  Page,
  PageHeader,
  Panel,
  PanelGrid,
  SkeletonList,
  StatusBadge,
} from '../../ui'
import { directionForLocale } from '../../app/copy'
import { formatAge, stringValue, workflowCopy } from './workflow-copy'
import { listWorkflowInstances, type WorkflowInstance } from './workflow-api'

function valueOf(record: Record<string, unknown>, key: string): string | null {
  const value = record[key]
  return typeof value === 'string' && value.trim() ? value : null
}

export function MyRequests({ locale, session, scopeReady, scopeEpoch }: { locale: Locale; session: Session; scopeReady: boolean; scopeEpoch: number }) {
  const copy = workflowCopy[locale]
  const [loading, setLoading] = useState(true)
  const [requests, setRequests] = useState<WorkflowInstance[]>([])
  const [loadError, setLoadError] = useState(false)
  const [denied, setDenied] = useState(false)
  const requestRef = useRef(0)

  const load = useCallback(async () => {
    const request = ++requestRef.current
    if (!scopeReady) {
      setRequests([])
      setLoadError(false)
      setDenied(false)
      setLoading(false)
      return
    }
    setLoading(true)
    setLoadError(false)
    setDenied(false)
    try {
      const collection = await listWorkflowInstances(session.access_token)
      if (request !== requestRef.current) return
      setRequests(collection.items)
    } catch (error) {
      if (request !== requestRef.current) return
      setRequests([])
      if (error instanceof ApiError && error.status === 403) setDenied(true)
      else setLoadError(true)
    } finally {
      if (request === requestRef.current) setLoading(false)
    }
  }, [scopeReady, session.access_token])

  useEffect(() => { void load() }, [load, scopeEpoch])

  return (
    <div dir={directionForLocale(locale)}>
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
                   <Button type="button" variant="secondary" onClick={() => { window.location.href = `/my-requests/${instance.id}` }}>{copy.detail}</Button>
                </Panel>
              )
            })}
          </PanelGrid>
        )}
      </Page>
    </div>
  )
}

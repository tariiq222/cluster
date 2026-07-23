// @vitest-environment jsdom
import { useCallback, useEffect, useRef, useState } from 'react'
import { ClipboardList } from 'lucide-react'
import type { Locale } from '../../app/copy'
import { directionForLocale } from '../../app/copy'
import type { Session } from '../../api'
import { ApiError } from '../../api'
import { Button, EmptyState, InlineError, Page, PageHeader, Panel, SkeletonList, StatusBadge } from '../../ui'
import { workflowCopy } from './workflow-copy'
import { getWorkflowInstance, type WorkflowInstanceDetails } from './workflow-api'

export function MyRequestDetail({ locale, session, instanceId, scopeReady, scopeEpoch }: { locale: Locale; session: Session; instanceId: string; scopeReady: boolean; scopeEpoch: number }) {
  const copy = workflowCopy[locale]
  const [state, setState] = useState<'loading' | 'ready' | 'empty' | 'error' | 'forbidden' | 'not-found'>('loading')
  const [detail, setDetail] = useState<WorkflowInstanceDetails | null>(null)
  const requestRef = useRef(0)
  const load = useCallback(async () => { const request = ++requestRef.current; setDetail(null); setState('loading'); if (!scopeReady) return; try { const value = await getWorkflowInstance(session.access_token, instanceId); if (request !== requestRef.current) return; setDetail(value); setState(value.steps.length ? 'ready' : 'empty') } catch (error) { if (request !== requestRef.current) return; setState(error instanceof ApiError && error.status === 403 ? 'forbidden' : error instanceof ApiError && error.status === 404 ? 'not-found' : 'error') } }, [scopeReady, session.access_token, instanceId])
  useEffect(() => { void load() }, [load, scopeEpoch])
  return <div dir={directionForLocale(locale)}><Page aria-labelledby="request-detail-heading"><PageHeader id="request-detail-heading" title={copy.detail} actions={<Button variant="secondary" onClick={() => { window.location.href = '/my-requests' }}>{copy.backToList}</Button>} />{state === 'loading' ? <SkeletonList label={copy.reqLoading} /> : state === 'forbidden' ? <Panel id="request-denied" title={copy.deniedTitle} level={2}><p>{copy.deniedBody}</p></Panel> : state === 'not-found' || state === 'empty' ? <EmptyState icon={<ClipboardList aria-hidden="true" />} title={copy.noDetails} /> : state === 'error' || !detail ? <InlineError message={copy.error} retryLabel={copy.retry} onRetry={() => void load()} /> : <Panel id="request-detail-panel" title={detail.instance.id} level={2}><StatusBadge>{copy.workflowState(detail.instance.state ?? '')}</StatusBadge><ol className="workflow-step-history">{detail.steps.map((step) => <li key={step.id}><strong>{step.node_key ?? step.id}</strong><span>{copy.workflowState(step.state ?? '')}</span><small>{step.completed_at ?? step.created_at ?? '—'}</small></li>)}</ol></Panel>}</Page></div>
}

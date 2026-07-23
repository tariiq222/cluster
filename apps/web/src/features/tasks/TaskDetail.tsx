// @vitest-environment jsdom
import { useCallback, useEffect, useRef, useState } from 'react'
import { CheckSquare } from 'lucide-react'
import type { Locale } from '../../app/copy'
import { directionForLocale } from '../../app/copy'
import type { Session } from '../../api'
import { ApiError } from '../../api'
import { Button, EmptyState, InlineError, Page, PageHeader, Panel, SkeletonList, StatusBadge } from '../../ui'
import { workflowCopy } from '../workflow/workflow-copy'
import { getTask, getTaskComments, type Task } from '../workflow/workflow-api'

export function TaskDetail({ locale, session, taskId, scopeReady, scopeEpoch }: { locale: Locale; session: Session; taskId: string; scopeReady: boolean; scopeEpoch: number }) {
  const copy = workflowCopy[locale]
  const [state, setState] = useState<'loading' | 'ready' | 'empty' | 'error' | 'forbidden' | 'not-found'>('loading')
  const [task, setTask] = useState<Task | null>(null)
  const [comments, setComments] = useState<Array<Record<string, unknown>>>([])
  const requestRef = useRef(0)
  const load = useCallback(async () => { const request = ++requestRef.current; setTask(null); setComments([]); setState('loading'); if (!scopeReady) return; try { const [value, commentList] = await Promise.all([getTask(session.access_token, taskId), getTaskComments(session.access_token, taskId)]); if (request !== requestRef.current) return; setTask(value); setComments(commentList.items); setState('ready') } catch (error) { if (request !== requestRef.current) return; setState(error instanceof ApiError && error.status === 403 ? 'forbidden' : error instanceof ApiError && error.status === 404 ? 'not-found' : 'error') } }, [scopeReady, session.access_token, taskId])
  useEffect(() => { void load() }, [load, scopeEpoch])
  return <div dir={directionForLocale(locale)}><Page aria-labelledby="task-detail-heading"><PageHeader id="task-detail-heading" title={copy.taskDetails} actions={<Button variant="secondary" onClick={() => { window.location.href = '/tasks' }}>{copy.backToList}</Button>} />{state === 'loading' ? <SkeletonList label={copy.reqLoading} /> : state === 'forbidden' || state === 'not-found' ? <Panel id="task-denied" title={state === 'forbidden' ? copy.deniedTitle : copy.noDetails} level={2}><p>{state === 'forbidden' ? copy.deniedBody : copy.noDetails}</p></Panel> : state === 'error' || !task ? <InlineError message={copy.reqError} retryLabel={copy.retry} onRetry={() => void load()} /> : <><Panel id="task-detail-panel" title={task.title ?? task.id} level={2}><StatusBadge>{task.status ?? task.state ?? '—'}</StatusBadge><p>{task.description ?? copy.noDetails}</p><h2>{copy.taskActions}</h2><p dir="ltr">{(task.allowed_actions ?? []).join(', ') || '—'}</p></Panel><Panel id="task-comments" title={copy.comments} level={2}>{comments.length ? <ul>{comments.map((comment, index) => <li key={String(comment.id ?? index)}>{String(comment.body ?? comment.comment ?? '')}</li>)}</ul> : <EmptyState icon={<CheckSquare aria-hidden="true" />} title={copy.noComments} />}</Panel></>}</Page></div>
}

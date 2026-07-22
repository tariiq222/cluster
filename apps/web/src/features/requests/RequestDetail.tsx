import { type FormEvent, useCallback, useEffect, useState } from 'react'
import { ApiError, type WorkRecord } from '../../api'
import {
  archiveRequest,
  cancelRequest,
  getDocumentDownloadUrl,
  linkDocument as linkR1Document,
  transitionRequest,
} from '../../api/r1'
import { listDocumentRecords, type DocumentRecord } from '../../api/documents'
import { recordStatusLabel, text, type Locale } from '../../app/copy'
import { formatDate } from '../../app/NotificationList'
import { stateFromError } from '../../api/http'
import { Button, Field, InlineError, Page, PageHeader, Panel, Select, StatusBadge, SkeletonList } from '../../ui'
import { RecordProjection } from '../work-records/RecordProjection'

function canAttachDocument(document: DocumentRecord): boolean {
  const payload = document as unknown as Record<string, unknown>
  const allowed = payload.allowed_actions
  const currentVersionId = payload.current_version_id
  return Array.isArray(allowed)
    && allowed.includes('link')
    && typeof currentVersionId === 'string'
    && currentVersionId.length > 0
}

export function RequestDetail({ locale, token, record, loading, state, authorizedRecord, onRetry }: {
  locale: Locale
  token: string
  record: WorkRecord | null
  loading: boolean
  state: 'ready' | 'unavailable' | 'error'
  authorizedRecord?: import('../../api/r1').AuthorizedWorkRecord | null
  onRetry: () => void
}) {
  const copy = text[locale]
  const [busy, setBusy] = useState(false)
  const [actionState, setActionState] = useState<'idle' | 'done' | 'error' | 'stale'>('idle')
  const [attachedDocumentId, setAttachedDocumentId] = useState<string | null>(null)
  const [documentState, setDocumentState] = useState<'loading' | 'ready' | 'empty' | 'forbidden' | 'error'>('loading')
  const [documentOptions, setDocumentOptions] = useState<Array<{ value: string; label: string }>>([])
  const [selectedDocumentId, setSelectedDocumentId] = useState('')
  const loadDocuments = useCallback(async () => {
    if (!record || state !== 'ready' || loading) return

    setDocumentState('loading')
    try {
      const documents: DocumentRecord[] = []
      let cursor: string | undefined
      do {
        const page = await listDocumentRecords({ limit: 100, cursor })
        documents.push(...page.items)
        cursor = page.next_cursor ?? undefined
      } while (cursor)
      const available = documents.filter(canAttachDocument)
      const options = available.map((document) => {
        const payload = document as unknown as Record<string, unknown>
        return {
          value: String(payload.id),
          label: String(payload.title ?? payload.name ?? payload.id),
        }
      })
      setDocumentOptions(options)
      setSelectedDocumentId(options[0]?.value ?? '')
      setDocumentState(options.length ? 'ready' : 'empty')
    } catch (error) {
      setDocumentState(stateFromError(error) === 'forbidden' ? 'forbidden' : 'error')
    }
  }, [record, state, loading])

  useEffect(() => {
    if (!record || state !== 'ready' || loading) return
    void loadDocuments()
  }, [loadDocuments, record, state, loading])

  if (loading && !authorizedRecord) return <Page><section className="state-panel"><PageHeader id="detail-loading-heading" title={copy.loadingDetail} /></section></Page>
  if (state === 'unavailable') return <Page><section className="state-panel"><PageHeader id="detail-unavailable-heading" title={copy.unavailable} /></section></Page>
  if (state === 'error') return <Page><section className="state-panel" role="alert"><PageHeader id="detail-error-heading" title={copy.detailError} /><Button variant="secondary" onClick={onRetry}>{copy.retry}</Button></section></Page>
  if (!record) return <Page><section className="state-panel"><PageHeader id="detail-loading-heading" title={copy.loadingDetail} /></section></Page>
  async function act(action: 'submit' | 'return' | 'complete' | 'complete-submission' | 'cancel' | 'archive', reason?: string) {
    if (!record) return
    setBusy(true); setActionState('idle')
    try {
      if (action === 'cancel' || action === 'archive') {
        if (!reason?.trim()) { setActionState('error'); return }
        if (action === 'cancel') await cancelRequest(token, record.id, reason, record.lock_version)
        await archiveRequest(token, record.id, reason, record.lock_version)
      } else {
        await transitionRequest(token, record.id, action, record.lock_version)
      }
      setActionState('done'); onRetry()
    }
    catch (error) { setActionState(error instanceof ApiError && (error.status === 409 || error.status === 412) ? 'stale' : 'error') }
    finally { setBusy(false) }
  }
  async function attach(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); if (!record) return
    const documentId = selectedDocumentId
    if (!documentId) return
    setBusy(true); setActionState('idle')
    try { await linkR1Document(token, record.id, documentId); setAttachedDocumentId(documentId); setActionState('done'); onRetry() }
    catch (error) { setActionState(error instanceof ApiError && (error.status === 409 || error.status === 412) ? 'stale' : 'error') }
    finally { setBusy(false) }
  }
  const documentPanel = (
    <Panel id="record-documents-heading" title={text[locale].linkedDocuments} level={2} className="surface-card">
      <p>{text[locale].aDocumentCannotBeLinked}</p>
      {documentState === 'loading' && <SkeletonList label={text[locale].loadingAvailableDocuments} />}
      {documentState === 'forbidden' && <p role="status" className="status-message">{text[locale].forbiddenDocuments}</p>}
      {documentState === 'error' && <InlineError message={text[locale].documentPickerError} retryLabel={text[locale].retry} onRetry={() => void loadDocuments()} />}
      {documentState === 'empty' && <p role="status" className="status-message">{text[locale].emptyAvailableDocuments}</p>}
      {documentState === 'ready' && (
        <form className="inline-form" onSubmit={(event) => void attach(event)}>
          <Field id="record-document-id" label={text[locale].availableDocumentId} required>
            <Select id="record-document-id" value={selectedDocumentId} onChange={setSelectedDocumentId} options={documentOptions} />
          </Field>
          <Button type="submit" disabled={busy || !selectedDocumentId}>{text[locale].attach}</Button>
        </form>
      )}
      {attachedDocumentId && <Button variant="secondary" onClick={() => void getDocumentDownloadUrl(token, attachedDocumentId).then((url) => window.location.assign(url)).catch((error) => { setActionState(error instanceof ApiError && (error.status === 409 || error.status === 412) ? 'stale' : 'error') })}>{text[locale].downloadThroughAccessDecision}</Button>}
    </Panel>
  )
  if (authorizedRecord) {
    return <><RecordProjection
        record={authorizedRecord}
        locale={locale}
        busy={busy}
        actionState={actionState}
        onRefresh={onRetry}
        onAction={(action, reason) => {
          if (action === 'submit' || action === 'return' || action === 'complete' || action === 'complete-submission' || action === 'cancel' || action === 'archive') void act(action, reason)
        }}
      /><Page>{documentPanel}</Page></>
  }
  return (
    <Page>
      <article className="detail-panel">
        <PageHeader
          id="detail-title-heading"
          title={record.payload.title ?? copy.noDescription}
          description={record.payload.description ?? copy.noDescription}
        />
        <div className="detail-meta"><StatusBadge>{recordStatusLabel(record.status, locale)}</StatusBadge><time dateTime={record.created_at}>{formatDate(record.created_at, locale)}</time></div>
        <Panel id="record-actions-heading" title={text[locale].requestActions} level={2} className="surface-card">
          <div className="table-actions">
            <Button disabled={busy} onClick={() => void act('submit')}>{text[locale].submit2}</Button>
            <Button variant="secondary" disabled={busy} onClick={() => void act('return')}>{text[locale].return}</Button>
            <Button disabled={busy} onClick={() => void act('complete')}>{text[locale].complete}</Button>
          </div>
        </Panel>
        {documentPanel}
        <Panel id="record-timeline-heading" title={text[locale].activityTimeline} level={2} className="surface-card">
          <ol><li><time dateTime={record.created_at}>{formatDate(record.created_at, locale)}</time> — {recordStatusLabel(record.status, locale)}</li></ol>
        </Panel>
        {actionState !== 'idle' && <p className={actionState === 'done' ? 'status-message' : 'error-summary'} role="status">{actionState === 'done' ? (text[locale].actionCompleted) : actionState === 'stale' ? (text[locale].theDataIsStaleRefresh) : (text[locale].theActionFailed)}</p>}
      </article>
    </Page>
  )
}

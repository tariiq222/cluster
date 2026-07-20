import { type FormEvent, useState } from 'react'
import { ApiError, type WorkRecord } from '../../api'
import {
  getDocumentDownloadUrl,
  linkDocument as linkR1Document,
  transitionRequest,
} from '../../api/r1'
import { text, type Locale } from '../../app/copy'
import { formatDate } from '../../app/NotificationList'
import { RecordProjection } from '../work-records/RecordProjection'

export function RequestDetail({ locale, token, record, loading, state, authorizedRecord, onRetry, onSessionExpired }: {
  locale: Locale
  token: string
  record: WorkRecord | null
  loading: boolean
  state: 'ready' | 'unavailable' | 'error'
  authorizedRecord?: import('../../api/r1').AuthorizedWorkRecord | null
  onRetry: () => void
  onSessionExpired: () => void
}) {
  const copy = text[locale]
  const [busy, setBusy] = useState(false)
  const [actionState, setActionState] = useState<'idle' | 'done' | 'error' | 'stale'>('idle')
  const [attachedDocumentId, setAttachedDocumentId] = useState<string | null>(null)
  if (loading) return <section><h1>{copy.loadingDetail}</h1></section>
  if (state === 'unavailable') return <section className="state-panel"><h1>{copy.unavailable}</h1></section>
  if (state === 'error') return <section className="state-panel" role="alert"><h1>{copy.detailError}</h1><button type="button" className="secondary-button" onClick={onRetry}>{copy.retry}</button></section>
  if (!record) return <section><h1>{copy.loadingDetail}</h1></section>
  async function act(action: 'submit' | 'return' | 'complete') {
    if (!record) return
    setBusy(true); setActionState('idle')
    try { await transitionRequest(token, record.id, action, record.lock_version); setActionState('done'); onRetry() }
    catch (error) { if (error instanceof ApiError && error.status === 401) onSessionExpired(); else setActionState(error instanceof ApiError && (error.status === 409 || error.status === 412) ? 'stale' : 'error') }
    finally { setBusy(false) }
  }
  async function attach(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); if (!record) return
    const documentId = String(new FormData(event.currentTarget).get('document_id') ?? '')
    setBusy(true); setActionState('idle')
    try { await linkR1Document(token, record.id, documentId); setAttachedDocumentId(documentId); setActionState('done'); event.currentTarget.reset(); onRetry() }
    catch (error) { if (error instanceof ApiError && error.status === 401) onSessionExpired(); else setActionState(error instanceof ApiError && (error.status === 409 || error.status === 412) ? 'stale' : 'error') }
    finally { setBusy(false) }
  }
  if (authorizedRecord) {
    return <RecordProjection
      record={authorizedRecord}
      locale={locale}
      onRefresh={onRetry}
      onAction={(action) => {
        if (action === 'submit' || action === 'return' || action === 'complete') void act(action)
      }}
    />
  }
  return (
    <article className="detail-panel">
      <h1>{record.payload.title ?? copy.noDescription}</h1>
      <p>{record.payload.description ?? copy.noDescription}</p>
      <div className="detail-meta"><span className="status-badge">{copy.submitted}</span><time dateTime={record.created_at}>{formatDate(record.created_at, locale)}</time></div>
      <section className="surface-card" aria-labelledby="record-actions-heading"><h2 id="record-actions-heading">{locale === 'ar' ? 'إجراءات الطلب' : 'Request actions'}</h2><div className="table-actions"><button disabled={busy} type="button" className="primary-button" onClick={() => void act('submit')}>{locale === 'ar' ? 'إرسال' : 'Submit'}</button><button disabled={busy} type="button" className="secondary-button" onClick={() => void act('return')}>{locale === 'ar' ? 'إعادة' : 'Return'}</button><button disabled={busy} type="button" className="primary-button" onClick={() => void act('complete')}>{locale === 'ar' ? 'إكمال' : 'Complete'}</button></div></section>
      <section className="surface-card" aria-labelledby="record-documents-heading"><h2 id="record-documents-heading">{locale === 'ar' ? 'المستندات المرتبطة' : 'Linked documents'}</h2><p>{locale === 'ar' ? 'لا يصبح المستند قابلاً للربط والتنزيل حتى يمر بالحجر والفحص ويصبح متاحاً.' : 'A document cannot be linked or downloaded until quarantine and scanning finish and it becomes available.'}</p><form className="inline-form" onSubmit={(event) => void attach(event)}><label>{locale === 'ar' ? 'معرّف المستند المتاح' : 'Available document ID'}<input name="document_id" required pattern="[0-9a-f-]{36}" /></label><button disabled={busy} className="primary-button">{locale === 'ar' ? 'إرفاق' : 'Attach'}</button></form>{attachedDocumentId && <button type="button" className="secondary-button" onClick={() => void getDocumentDownloadUrl(token, attachedDocumentId).then((url) => window.location.assign(url)).catch((error) => { if (error instanceof ApiError && error.status === 401) onSessionExpired(); else setActionState(error instanceof ApiError && (error.status === 409 || error.status === 412) ? 'stale' : 'error') })}>{locale === 'ar' ? 'تنزيل عبر قرار الوصول' : 'Download through access decision'}</button>}</section>
      <section className="surface-card" aria-labelledby="record-timeline-heading"><h2 id="record-timeline-heading">{locale === 'ar' ? 'الخط الزمني للنشاط' : 'Activity timeline'}</h2><ol><li><time dateTime={record.created_at}>{formatDate(record.created_at, locale)}</time> — {String(record.status)}</li></ol></section>
      {actionState !== 'idle' && <p className={actionState === 'done' ? 'status-message' : 'error-summary'} role="status">{actionState === 'done' ? (locale === 'ar' ? 'اكتمل الإجراء.' : 'Action completed.') : actionState === 'stale' ? (locale === 'ar' ? 'البيانات قديمة؛ تم طلب التحديث.' : 'The data is stale; refresh requested.') : (locale === 'ar' ? 'تعذر تنفيذ الإجراء.' : 'The action failed.')}</p>}
    </article>
  )
}

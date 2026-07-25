import { FileText, ArrowLeft, Archive, ShieldCheck } from 'lucide-react'
import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react'
import { stateFromError, type ResourceState } from '../../api/http'
import {
  getDocumentRecord,
  listDocumentRecordLinks,
  listDocumentRecordVersions,
  listDocumentRecords,
  createDocumentRecord,
  updateDocumentRecord,
  addDocumentRecordVersion,
  completeDocumentUpload,
  linkDocumentRecord,
  grantDocumentAccess,
  putUploadTicket,
  sha256ForFile,
  transitionDocumentRecord,
  type DocumentRecord,
} from '../../api/documents'
import { Button, EmptyState, Field, InlineError, Page, PageHeader, Panel, Select, SkeletonList, StatusBadge } from '../../ui'

type Locale = 'ar' | 'en'
const copy = {
  ar: { title: 'مركز المستندات', intro: 'استعرض المستندات المصرح لك بها وإصداراتها وروابطها.', filter: 'التصنيف', all: 'كل التصنيفات', loading: 'جارٍ تحميل المستندات…', empty: 'لا توجد مستندات متاحة', emptyBody: 'ستظهر المستندات التي يتيحها سياق وصولك هنا.', retry: 'إعادة المحاولة', forbidden: 'لا تملك صلاحية عرض المستندات.', notFound: 'المستند غير موجود أو تم حجب الوصول إليه.', error: 'تعذر تحميل المستندات.', next: 'تحميل المزيد', back: 'العودة للمستندات', metadata: 'البيانات الوصفية', versions: 'الإصدارات', links: 'الروابط', state: 'الحالة', classification: 'التصنيف', owner: 'الوحدة المالكة', archive: 'أرشفة', archived: 'تمت الأرشفة', actionError: 'تعذر تنفيذ الإجراء. قد تكون البيانات قديمة؛ حدّث الصفحة ثم أعد المحاولة.', conflict: 'حدث تعارض. حدّث البيانات ثم أعد المحاولة.', stale: 'البيانات قديمة. حدّث الصفحة قبل الحفظ.', archiveReason: 'أرشفة من مركز المستندات', create: 'إضافة مستند', update: 'حفظ التعديلات', version: 'إضافة إصدار', relation: 'نوع الرابط', sourceModule: 'الموديول المصدر', recordType: 'نوع السجل', recordId: 'معرّف السجل', grant: 'إصدار صلاحية تنزيل', purpose: 'الغرض', titleField: 'العنوان', descriptionField: 'الوصف', ownerField: 'معرّف الوحدة المالكة', required: 'هذا الحقل مطلوب.' },
  en: { title: 'Documents', intro: 'Browse documents authorized for you, including versions and links.', filter: 'Classification', all: 'All classifications', loading: 'Loading documents…', empty: 'No documents available', emptyBody: 'Documents available in your access context will appear here.', retry: 'Retry', forbidden: 'You do not have permission to view documents.', notFound: 'The document was not found or access is concealed.', error: 'Could not load documents.', next: 'Load more', back: 'Back to documents', metadata: 'Metadata', versions: 'Versions', links: 'Links', state: 'State', classification: 'Classification', owner: 'Owner unit', archive: 'Archive', archived: 'Archived', actionError: 'Action failed. The data may be stale; refresh and retry.', conflict: 'A conflict occurred. Refresh before retrying.', stale: 'The data is stale. Refresh before saving.', archiveReason: 'Archived from the document center', create: 'Add document', update: 'Save changes', version: 'Add version', relation: 'Link relation', sourceModule: 'Source module', recordType: 'Record type', recordId: 'Record ID', grant: 'Issue download grant', purpose: 'Purpose', titleField: 'Title', descriptionField: 'Description', ownerField: 'Owner unit ID', required: 'This field is required.' },
} as const

function value(record: DocumentRecord, key: string): unknown { if (typeof record !== 'object' || record === null) return undefined; const payload: Record<PropertyKey, unknown> = { ...(record as object) }; return payload[key] }
export function isDocumentActionAllowed(record: DocumentRecord | null, action: string): boolean {
  const actions = record ? value(record, 'allowed_actions') : null
  return Array.isArray(actions) && actions.includes(action)
}
function availableGrantVersion(document: DocumentRecord, versions: DocumentRecord[]): string | undefined {
  const available = versions.filter(version => value(version, 'availability_status') === 'available' && value(version, 'status') !== 'rejected')
  const current = value(document, 'current_version_id')
  if (typeof current === 'string' && available.some(version => value(version, 'id') === current)) return current
  const sorted = [...available].sort((left, right) => Number(value(right, 'version_number') ?? 0) - Number(value(left, 'version_number') ?? 0))
  const top = sorted[0]
  return top ? String(value(top, 'id')) : undefined
}

export function DocumentsWorkspace({ locale, token, documentId, onNavigate }: { locale: Locale; token: string; documentId?: string; onNavigate: (path: string) => void }) {
  const t = copy[locale]
  const [classification, setClassification] = useState<string>('')
  const [items, setItems] = useState<DocumentRecord[]>([])
  const [cursor, setCursor] = useState<string | null>(null)
  const [state, setState] = useState<ResourceState>('loading')
  const [selected, setSelected] = useState<DocumentRecord | null>(null)
  const [versions, setVersions] = useState<DocumentRecord[]>([])
  const [links, setLinks] = useState<DocumentRecord[]>([])
  const [actionState, setActionState] = useState<ResourceState | null>(null)
  /**
   * Track in-flight list/detail requests so superseded calls cannot overwrite
   * the freshest snapshot. The unmount cleanup bumps the ref so no async
   * callback writes after the workspace route is torn down.
   */
  const activeRef = useRef(true)
  const listRequestRef = useRef(0)
  const detailRequestRef = useRef(0)
  useEffect(() => () => {
    activeRef.current = false
    listRequestRef.current += 1
    detailRequestRef.current += 1
  }, [])

  const loadList = useCallback(async (append = false) => {
    const epoch = ++listRequestRef.current
    activeRef.current = true
    setState('loading')
    try {
      const page = await listDocumentRecords({ classification: classification ? classification as never : undefined, cursor: append ? cursor ?? undefined : undefined, limit: 20 })
      if (!activeRef.current || epoch !== listRequestRef.current) return
      setItems(current => append ? [...current, ...page.items] : page.items)
      setCursor(page.next_cursor)
      setState(page.items.length || append ? 'ready' : 'empty')
    } catch (error) {
      if (!activeRef.current || epoch !== listRequestRef.current) return
      setState(stateFromError(error))
    }
  }, [classification, cursor])
  const loadDetail = useCallback(async () => {
    if (!documentId) return
    const epoch = ++detailRequestRef.current
    activeRef.current = true
    setState('loading')
    try {
      const [doc, ver, lnk] = await Promise.all([getDocumentRecord(documentId), listDocumentRecordVersions(documentId), listDocumentRecordLinks(documentId)])
      if (!activeRef.current || epoch !== detailRequestRef.current) return
      setSelected(doc); setVersions(ver.items); setLinks(lnk.items); setState('ready')
    } catch (error) {
      if (!activeRef.current || epoch !== detailRequestRef.current) return
      setState(stateFromError(error))
    }
  }, [documentId])
  useEffect(() => { if (documentId) void loadDetail(); else void loadList(false) }, [documentId, loadDetail, loadList])

  if (documentId) return <DocumentDetail locale={locale} token={token} documentId={documentId} selected={selected} versions={versions} links={links} state={state} actionState={actionState} onBack={() => onNavigate('/documents')} onRetry={loadDetail} onArchive={async () => { if (!selected) return; setActionState('loading'); try { await transitionDocumentRecord(token, documentId, 'archive', { reason: t.archiveReason }, Number(value(selected, 'lock_version')) || undefined); setActionState(null); await loadDetail() } catch (error) { setActionState(stateFromError(error)) } }} />

  return <Page><PageHeader id="documents-heading" title={t.title} description={t.intro} actions={<Select id="documents-classification" ariaLabel={t.filter} value={classification} onChange={value => { setClassification(value); setCursor(null) }} options={[{ value: '', label: t.all }, ...['public', 'internal', 'confidential', 'top_secret'].map(item => ({ value: item, label: item }))]} />} />
    <DocumentCreateForm locale={locale} token={token} onCreated={record => onNavigate(`/documents/${String(value(record, 'id'))}`)} />
    {state === 'loading' && !items.length ? <SkeletonList label={t.loading} /> : state === 'forbidden' ? <EmptyState icon={<ShieldCheck />} title={t.forbidden} /> : state === 'error' ? <InlineError message={t.error} retryLabel={t.retry} onRetry={() => void loadList(false)} /> : state === 'empty' ? <EmptyState icon={<FileText />} title={t.empty} body={t.emptyBody} /> : <><div className="ui-panel-grid">{items.map(item => <Panel key={String(value(item, 'id'))} id={`document-${String(value(item, 'id'))}`} title={<button className="link-button" onClick={() => onNavigate(`/documents/${String(value(item, 'id'))}`)}>{String(value(item, 'title') ?? value(item, 'name') ?? value(item, 'id'))}</button>}><p>{t.classification}: <StatusBadge>{String(value(item, 'classification') ?? '—')}</StatusBadge></p><p>{t.state}: {String(value(item, 'lifecycle_state') ?? value(item, 'status') ?? '—')}</p></Panel>)}</div>{cursor ? <Button variant="secondary" onClick={() => void loadList(true)}>{t.next}</Button> : null}</>}
  </Page>
}

function DocumentCreateForm({ locale, token, onCreated }: { locale: Locale; token: string; onCreated: (record: DocumentRecord) => void }) {
  const t = copy[locale]
  const [title, setTitle] = useState('')
  const [description, setDescription] = useState('')
  const [owner, setOwner] = useState('')
  const [classification, setClassification] = useState('internal')
  const [error, setError] = useState<ResourceState | null>(null)
  const [saving, setSaving] = useState(false)
  async function submit(event: FormEvent) { event.preventDefault(); if (!title.trim() || !owner.trim()) { setError('error'); return }; setSaving(true); setError(null); try { const result = await createDocumentRecord(token, { title: title.trim(), description: description.trim() || undefined, owner_organization_unit_id: owner.trim(), classification: classification as never, restriction_policy_key: 'default' }); onCreated(result) } catch (cause) { setError(stateFromError(cause)) } finally { setSaving(false) } }
  return <Panel id="document-create" title={t.create}><form onSubmit={submit} className="ui-form-grid"><Field id="document-create-title" label={t.titleField} required><input id="document-create-title" value={title} onChange={event => setTitle(event.target.value)} /></Field><Field id="document-create-description" label={t.descriptionField}><textarea id="document-create-description" value={description} onChange={event => setDescription(event.target.value)} /></Field><Field id="document-create-owner" label={t.ownerField} required><input id="document-create-owner" value={owner} onChange={event => setOwner(event.target.value)} /></Field><Select id="document-create-classification" ariaLabel={t.classification} value={classification} onChange={setClassification} options={['public', 'internal', 'confidential', 'top_secret'].map(item => ({ value: item, label: item }))} /><Button type="submit" disabled={saving}>{t.create}</Button>{error ? <InlineError message={error === 'conflict' ? t.conflict : t.actionError} /> : null}</form></Panel>
}

function DocumentDetail({ locale, token, documentId, selected, versions, links, state, actionState, onBack, onRetry, onArchive }: { locale: Locale; token: string; documentId: string; selected: DocumentRecord | null; versions: DocumentRecord[]; links: DocumentRecord[]; state: ResourceState; actionState: ResourceState | null; onBack: () => void; onRetry: () => void; onArchive: () => Promise<void> }) {
  const t = copy[locale]
  if (state === 'loading') return <Page><SkeletonList label={t.loading} /></Page>
  if (state === 'forbidden') return <Page><EmptyState icon={<ShieldCheck />} title={t.forbidden} action={<Button variant="secondary" onClick={onBack}>{t.back}</Button>} /></Page>
  if (state === 'not-found') return <Page><EmptyState icon={<FileText />} title={t.notFound} action={<Button variant="secondary" onClick={onBack}>{t.back}</Button>} /></Page>
  if (state === 'error' || !selected) return <Page><InlineError message={t.error} retryLabel={t.retry} onRetry={onRetry} /></Page>
  const title = String(value(selected, 'title') ?? value(selected, 'name') ?? value(selected, 'id'))
  const latestVersionId = availableGrantVersion(selected, versions)
  return <Page>
    <PageHeader id="document-detail-heading" title={title} description={String(value(selected, 'description') ?? '')} actions={<><Button variant="secondary" onClick={onBack}><ArrowLeft aria-hidden="true" />{t.back}</Button>{isDocumentActionAllowed(selected, 'archive') ? <Button variant="secondary" onClick={() => void onArchive()} disabled={actionState === 'loading'}><Archive aria-hidden="true" />{t.archive}</Button> : null}</>} />
    {actionState === 'conflict' || actionState === 'stale' ? <InlineError message={actionState === 'conflict' ? t.conflict : t.stale} retryLabel={t.retry} onRetry={onRetry} /> : actionState === 'error' ? <InlineError message={t.actionError} retryLabel={t.retry} onRetry={onRetry} /> : null}
    <DocumentDetailActions locale={locale} token={token} documentId={documentId} selected={selected} latestVersionId={latestVersionId} onSaved={onRetry} />
    <Panel id="document-metadata" title={t.metadata}><dl><dt>{t.classification}</dt><dd>{String(value(selected, 'classification') ?? '—')}</dd><dt>{t.state}</dt><dd>{String(value(selected, 'lifecycle_state') ?? value(selected, 'status') ?? '—')}</dd><dt>{t.owner}</dt><dd>{String(value(selected, 'owner_organization_unit_id') ?? '—')}</dd></dl></Panel>
    <Panel id="document-versions" title={t.versions}>{versions.length ? versions.map(item => <div key={String(value(item, 'id'))}><StatusBadge>{String(value(item, 'version_number') ?? '—')}</StatusBadge> {String(value(item, 'file_name') ?? value(item, 'id'))}</div>) : <p>{t.empty}</p>}</Panel>
    <Panel id="document-links" title={t.links}>{links.length ? links.map(item => <div key={String(value(item, 'id'))}>{String(value(item, 'relation_type') ?? value(item, 'record_type') ?? value(item, 'id'))}</div>) : <p>{t.empty}</p>}</Panel>
  </Page>
}

function DocumentDetailActions({ locale, token, documentId, selected, latestVersionId, onSaved }: { locale: Locale; token: string; documentId: string; selected: DocumentRecord | null; latestVersionId?: string; onSaved: () => void }) {
  const t = copy[locale]
  const [title, setTitle] = useState(String(value(selected ?? ({} as DocumentRecord), 'title') ?? ''))
  const [fileName, setFileName] = useState('')
  const [file, setFile] = useState<File | null>(null)
  const [contentType, setContentType] = useState('application/octet-stream')
  const [recordId, setRecordId] = useState('')
  const [status, setStatus] = useState<ResourceState | null>(null)
  async function run(action: () => Promise<unknown>) { setStatus('loading'); try { await action(); setStatus(null); onSaved() } catch (cause) { setStatus(stateFromError(cause)) } }
  const canUpdate = isDocumentActionAllowed(selected, 'update')
  const canVersion = isDocumentActionAllowed(selected, 'add-version')
  const canLink = isDocumentActionAllowed(selected, 'link')
  const canGrant = isDocumentActionAllowed(selected, 'grant')
  if (!canUpdate && !canVersion && !canLink && !canGrant) return null
  return <Panel id="document-actions" title={t.update}>
    {canUpdate ? <form className="ui-form-grid" onSubmit={event => { event.preventDefault(); void run(() => updateDocumentRecord(token, documentId, { title }, Number(value(selected ?? ({} as DocumentRecord), 'lock_version')) || undefined)) }}><Field id="document-update-title" label={t.titleField}><input id="document-update-title" value={title} onChange={event => setTitle(event.target.value)} /></Field><Button type="submit" disabled={status === 'loading'}>{t.update}</Button></form> : null}
    {canVersion ? <form className="ui-form-grid" onSubmit={event => { event.preventDefault(); void run(async () => { if (!file) return; const sha256 = await sha256ForFile(file); const intent = await addDocumentRecordVersion(token, documentId, { file_name: file.name, content_type: file.type || contentType, byte_size: file.size, sha256 }); await putUploadTicket(intent.upload_url, file, intent.required_headers); return completeDocumentUpload(token, intent.upload_id, { sha256, byte_size: file.size }) }) }}><Field id="document-version-file" label={t.version} required><input id="document-version-file" type="file" onChange={event => { const picked = event.target.files?.[0] ?? null; setFile(picked); setFileName(picked?.name ?? ''); setContentType(picked?.type || 'application/octet-stream') }} /></Field><Button type="submit" disabled={status === 'loading' || !fileName}>{t.version}</Button></form> : null}
    {canLink ? <form className="ui-form-grid" onSubmit={event => { event.preventDefault(); void run(() => linkDocumentRecord(token, documentId, { source: { source_module: 'documents', record_type: 'record', record_id: recordId }, relation_type: 'related' }, Number(value(selected ?? ({} as DocumentRecord), 'lock_version')) || undefined)) }}><Field id="document-link-record" label={t.recordId} required><input id="document-link-record" value={recordId} onChange={event => setRecordId(event.target.value)} /></Field><Button type="submit" disabled={status === 'loading' || !recordId}>{t.links}</Button></form> : null}
    {canGrant && latestVersionId ? <Button variant="secondary" disabled={status === 'loading'} onClick={() => void run(() => grantDocumentAccess(token, documentId, 'download', { version_id: latestVersionId, purpose: t.purpose }))}><ShieldCheck aria-hidden="true" />{t.grant}</Button> : null}
    {status === 'conflict' || status === 'stale' ? <InlineError message={status === 'conflict' ? t.conflict : t.stale} /> : status === 'error' ? <InlineError message={t.actionError} /> : null}
  </Panel>
}

import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react'
import { Archive, FileText, Link2, Lock, ShieldCheck, Unlock, XCircle } from 'lucide-react'
import * as generated from '../../../src/api/generated/cluster'
import { ApiError, customFetch, requestInit, stateFromError, unwrap, type ResourceState } from '../../api/http'
import { useNavigate } from '../../app/navigation-context'
import { useLocale, useSessionToken } from '../../app/session-context'
import { formatDate, statusLabel } from '../../i18n'
import { Button, Drawer, EmptyState, Field, InlineError, Page, PageHeader, Panel, Select, type SelectOption, SkeletonList, StatusBadge } from '../../ui'

type DocumentAction = 'archive' | 'unarchive' | 'place-hold' | 'release-hold'

interface DocumentRecord {
  id: string
  title?: string
  name?: string
  description?: string
  classification: generated.Classification
  status: string
  lifecycle_state?: string
  retention_until?: string | null
  restriction_policy_key?: string | null
  owner_organization_unit_id?: string
  lock_version: number
  allowed_actions?: string[]
  created_at: string
  updated_at: string
}

interface DocumentVersion {
  id: string
  version_number?: number
  file_name?: string
  status?: string
  availability_status?: string
  created_at?: string
}

interface DocumentLink {
  id: string
  relation_type?: string
  source?: { source_module?: string; record_type?: string; record_id?: string }
  created_at?: string
}

type UploadState = 'idle' | 'hashing' | 'initiating' | 'uploading' | 'checking' | 'completing' | 'done'

const copy = {
  ar: {
    pageTitle: 'المستندات',
    back: 'عودة إلى المستندات',
    loading: 'جارٍ تحميل المستند…',
    notFound: 'المستند غير موجود أو لا يمكن الوصول إليه.',
    forbidden: 'غير مصرح لك بعرض هذا المستند.',
    error: 'تعذر تحميل المستند. يرجى إعادة المحاولة.',
    retry: 'إعادة المحاولة',
    metadataTitle: 'بيانات المستند',
    name: 'الاسم',
    classification: 'التصنيف',
    classificationPublic: 'عام',
    classificationInternal: 'داخلي',
    classificationConfidential: 'سري',
    classificationTopSecret: 'سري للغاية',
    state: 'الحالة',
    retentionUntil: 'الاحتفاظ حتى',
    restrictionPolicy: 'سياسة التقييد',
    owner: 'الوحدة المالكة',
    createdAt: 'تاريخ الإنشاء',
    updatedAt: 'آخر تحديث',
    description: 'الوصف',
    noDescription: 'لا يوجد وصف',
    versionsTitle: 'الإصدارات',
    noVersions: 'لا توجد إصدارات',
    versionNumber: 'الإصدار',
    fileName: 'اسم الملف',
    linksTitle: 'الروابط',
    noLinks: 'لا توجد روابط',
    relationType: 'نوع العلاقة',
    sourceRecord: 'السجل المصدر',
    actionsTitle: 'الإجراءات المتاحة',
    actionArchive: 'أرشفة',
    actionUnarchive: 'إلغاء الأرشفة',
    actionPlaceHold: 'وضع قيد الحجز',
    actionReleaseHold: 'رفع الحجز',
    actionLink: 'ربط مستند',
    actionUpload: 'رفع إصدار جديد',
    noActions: 'لا توجد إجراءات متاحة حالياً',
    reasonTitle: 'سبب الإجراء',
    reasonLabel: 'السبب',
    reasonPlaceholder: 'اذكر سبباً واضحاً…',
    reasonRequired: 'السبب مطلوب.',
    stale: 'تعارض في الإصدار: تم تحديث المستند من جهة أخرى، تم إعادة تحميله.',
    actionError: 'تعذر تنفيذ الإجراء. يرجى إعادة المحاولة.',
    cancel: 'إلغاء',
    confirm: 'تأكيد',
    linkTitle: 'ربط سجل مصدر',
    linkRelationLabel: 'نوع العلاقة',
    linkRelationAttachment: 'مرفق',
    linkRelationEvidence: 'دليل',
    linkRecordIdLabel: 'معرّف السجل المصدر',
    linkRecordIdPlaceholder: 'معرّف UUID للسجل المرتبط',
    linkModuleLabel: 'الوحدة المصدر',
    linkModulePlaceholder: 'مثال: tasks',
    linkRecordTypeLabel: 'نوع السجل',
    linkRecordTypePlaceholder: 'مثال: task',
    recordIdRequired: 'معرّف السجل المصدر مطلوب.',
    uploadTitle: 'رفع إصدار جديد',
    uploadFileLabel: 'الملف',
    uploadIdle: 'اختر ملفاً لرفعه كإصدار جديد.',
    uploadHashing: 'جارٍ حساب بصمة الملف…',
    uploadInitiating: 'جارٍ تجهيز الرفع…',
    uploadUploading: 'جارٍ نقل الملف إلى التخزين…',
    uploadChecking: 'جارٍ التحقق من حالة الرفع…',
    uploadCompleting: 'جارٍ إكمال الرفع…',
    uploadDone: 'تم رفع الإصدار بنجاح.',
    uploadError: 'تعذر رفع الملف. يرجى إعادة المحاولة.',
    uploadRejected: 'رُفض الإصدار بعد الفحص.',
    chooseFile: 'اختيار ملف',
    uploadNow: 'رفع',
  },
  en: {
    pageTitle: 'Documents',
    back: 'Back to documents',
    loading: 'Loading document…',
    notFound: 'Document not found or not accessible.',
    forbidden: 'You are not authorized to view this document.',
    error: 'Could not load the document. Please try again.',
    retry: 'Retry',
    metadataTitle: 'Document metadata',
    name: 'Name',
    classification: 'Classification',
    classificationPublic: 'Public',
    classificationInternal: 'Internal',
    classificationConfidential: 'Confidential',
    classificationTopSecret: 'Top secret',
    state: 'State',
    retentionUntil: 'Retention until',
    restrictionPolicy: 'Restriction policy',
    owner: 'Owner organization unit',
    createdAt: 'Created at',
    updatedAt: 'Updated at',
    description: 'Description',
    noDescription: 'No description',
    versionsTitle: 'Versions',
    noVersions: 'No versions',
    versionNumber: 'Version',
    fileName: 'File name',
    linksTitle: 'Links',
    noLinks: 'No links',
    relationType: 'Relation type',
    sourceRecord: 'Source record',
    actionsTitle: 'Available actions',
    actionArchive: 'Archive',
    actionUnarchive: 'Unarchive',
    actionPlaceHold: 'Place on hold',
    actionReleaseHold: 'Release hold',
    actionLink: 'Link document',
    actionUpload: 'Upload new version',
    noActions: 'No actions are currently available',
    reasonTitle: 'Action reason',
    reasonLabel: 'Reason',
    reasonPlaceholder: 'Provide a clear reason…',
    reasonRequired: 'A reason is required.',
    stale: 'Version conflict: the document was updated elsewhere, it has been reloaded.',
    actionError: 'Could not perform the action. Please try again.',
    cancel: 'Cancel',
    confirm: 'Confirm',
    linkTitle: 'Link a source record',
    linkRelationLabel: 'Relation type',
    linkRelationAttachment: 'Attachment',
    linkRelationEvidence: 'Evidence',
    linkRecordIdLabel: 'Source record id',
    linkRecordIdPlaceholder: 'UUID of the linked record',
    linkModuleLabel: 'Source module',
    linkModulePlaceholder: 'e.g. tasks',
    linkRecordTypeLabel: 'Record type',
    linkRecordTypePlaceholder: 'e.g. task',
    recordIdRequired: 'A source record id is required.',
    uploadTitle: 'Upload a new version',
    uploadFileLabel: 'File',
    uploadIdle: 'Pick a file to upload as a new version.',
    uploadHashing: 'Hashing the file…',
    uploadInitiating: 'Preparing the upload…',
    uploadUploading: 'Transferring the file to storage…',
    uploadChecking: 'Checking upload status…',
    uploadCompleting: 'Completing the upload…',
    uploadDone: 'Version uploaded successfully.',
    uploadError: 'Could not upload the file. Please try again.',
    uploadRejected: 'The version was rejected after scanning.',
    chooseFile: 'Choose file',
    uploadNow: 'Upload',
  },
} as const

type DocDetailCopy = (typeof copy)[keyof typeof copy]

export function DocumentDetailScreen({ documentId }: { documentId: string }) {
  const locale = useLocale()
  const csrfToken = useSessionToken()
  const navigate = useNavigate()
  const t = copy[locale]

  const [state, setState] = useState<ResourceState>('loading')
  const [document, setDocument] = useState<DocumentRecord | null>(null)
  const [versions, setVersions] = useState<DocumentVersion[]>([])
  const [links, setLinks] = useState<DocumentLink[]>([])
  const [busy, setBusy] = useState(false)
  const [actionError, setActionError] = useState<string | null>(null)
  const [reasonDraft, setReasonDraft] = useState('')
  const [pendingAction, setPendingAction] = useState<DocumentAction | null>(null)
  const [linkOpen, setLinkOpen] = useState(false)
  const [linkRelation, setLinkRelation] = useState('attachment')
  const [linkRecordId, setLinkRecordId] = useState('')
  const [linkModule, setLinkModule] = useState('tasks')
  const [linkRecordType, setLinkRecordType] = useState('task')
  const [file, setFile] = useState<File | null>(null)
  const [uploadState, setUploadState] = useState<UploadState>('idle')
  const [uploadError, setUploadError] = useState<string | null>(null)
  const requestRef = useRef(0)

  const load = useCallback(async () => {
    const request = ++requestRef.current
    setState('loading')
    setActionError(null)
    try {
      const [record, versionList, linkList] = await Promise.all([
        unwrap<DocumentRecord>(await generated.getDocument(documentId, requestInit(csrfToken))),
        unwrap<generated.EntityCollection>(
          await generated.listDocumentVersions(documentId, { limit: 50 }, requestInit(csrfToken)),
        ),
        unwrap<generated.EntityCollection>(
          await generated.listDocumentLinks(documentId, { limit: 50 }, requestInit(csrfToken)),
        ),
      ])
      if (request !== requestRef.current) return
      setDocument(record)
      setVersions(versionList.items as unknown as DocumentVersion[])
      setLinks(linkList.items as unknown as DocumentLink[])
      setState('ready')
    } catch (error) {
      if (request !== requestRef.current) return
      setDocument(null)
      setVersions([])
      setLinks([])
      setState(stateFromError(error))
    }
  }, [csrfToken, documentId])

  useEffect(() => {
    void load()
  }, [load])

  const runTransition = useCallback(
    async (action: DocumentAction, reason: string) => {
      if (!document) return
      setBusy(true)
      setActionError(null)
      try {
        const updated = unwrap<DocumentRecord>(
          await generated.transitionDocument(
            documentId,
            action,
            { reason },
            requestInit(csrfToken, { command: true, idempotency: `document-${action}`, lockVersion: document.lock_version }),
          ),
        )
        setDocument(updated)
        setPendingAction(null)
        setReasonDraft('')
      } catch (error) {
        if (error instanceof ApiError && error.status === 412) {
          setPendingAction(null)
          await load()
          setActionError(t.stale)
          return
        }
        setActionError(errorMessage(error, t.actionError))
      } finally {
        setBusy(false)
      }
    },
    [csrfToken, document, documentId, load, t.actionError, t.stale],
  )

  const submitLink = useCallback(async () => {
    if (!document) return
    const recordId = linkRecordId.trim()
    if (!recordId) {
      setActionError(t.recordIdRequired)
      return
    }
    setBusy(true)
    setActionError(null)
    try {
      await unwrap<generated.Entity>(
        await generated.linkDocument(
          document.id,
          {
            source: {
              source_module: linkModule.trim() || 'tasks',
              record_type: linkRecordType.trim() || 'task',
              record_id: recordId,
            },
            relation_type: linkRelation,
          },
          requestInit(csrfToken, { command: true, idempotency: 'document-link', lockVersion: document.lock_version }),
        ),
      )
      setLinkOpen(false)
      setLinkRecordId('')
      await load()
    } catch (error) {
      if (error instanceof ApiError && error.status === 412) {
        setLinkOpen(false)
        await load()
        setActionError(t.stale)
        return
      }
      setActionError(errorMessage(error, t.actionError))
    } finally {
      setBusy(false)
    }
  }, [csrfToken, document, linkModule, linkRecordId, linkRecordType, linkRelation, load, t.actionError, t.recordIdRequired, t.stale])

  const submitUpload = useCallback(async () => {
    if (!file || uploadState === 'hashing' || uploadState === 'initiating' || uploadState === 'uploading' || uploadState === 'checking' || uploadState === 'completing') return
    if (!document) return
    const chosen = file
    setUploadError(null)
    try {
      setUploadState('hashing')
      const sha256 = await sha256ForFile(chosen)
      setUploadState('initiating')
      const intent = unwrap<generated.DocumentUploadInitiated>(
        await generated.initiateDocumentUpload(
          {
            purpose: 'document_version',
            name: chosen.name,
            file_name: chosen.name,
            content_type: chosen.type || 'application/octet-stream',
            byte_size: chosen.size,
            sha256,
            classification: document.classification,
            description: null,
          },
          requestInit(csrfToken, { command: true, idempotency: 'document-upload' }),
        ),
      )
      setUploadState('uploading')
      const putResult = await customFetch(intent.upload_url, {
        method: 'PUT',
        body: chosen,
        headers: { 'Content-Type': chosen.type || 'application/octet-stream' },
      })
      if (putResult.status >= 400) {
        throw new ApiError(putResult.status, {
          type: 'about:blank',
          title: 'Upload to storage failed',
          status: putResult.status,
        })
      }
      setUploadState('checking')
      const uploadStatus = unwrap<generated.DocumentUploadStatus>(
        await generated.getDocumentUploadStatus(intent.upload_id, requestInit(csrfToken)),
      )
      if (uploadStatus.scan_status === 'rejected') {
        setUploadError(t.uploadRejected)
        setUploadState('idle')
        return
      }
      setUploadState('completing')
      const completion = unwrap<generated.DocumentUploadCompletion>(
        await generated.completeDocumentUpload(
          intent.upload_id,
          { sha256, byte_size: chosen.size },
          requestInit(csrfToken, { command: true, idempotency: 'document-upload-complete' }),
        ),
      )
      if (!completion.accepted) {
        setUploadError(completion.failure_codes.length > 0 ? `${t.uploadRejected} (${completion.failure_codes.join(', ')})` : t.uploadRejected)
        setUploadState('idle')
        return
      }
      setUploadState('done')
      setFile(null)
      await load()
    } catch (error) {
      setUploadError(errorMessage(error, t.uploadError))
      setUploadState('idle')
    }
  }, [csrfToken, document, file, load, t.uploadError, t.uploadRejected, uploadState])

  const backAction = (
    <Button variant="secondary" onClick={() => navigate('/documents')}>
      {t.back}
    </Button>
  )

  if (state === 'loading' && !document) {
    return (
      <Page aria-labelledby="document-detail-heading">
        <PageHeader id="document-detail-heading" title={t.pageTitle} actions={backAction} />
        <SkeletonList />
      </Page>
    )
  }

  if (state === 'forbidden' && !document) {
    return (
      <Page aria-labelledby="document-detail-heading">
        <PageHeader id="document-detail-heading" title={t.pageTitle} actions={backAction} />
        <EmptyState title={t.forbidden} />
      </Page>
    )
  }

  if (state === 'not-found' && !document) {
    return (
      <Page aria-labelledby="document-detail-heading">
        <PageHeader id="document-detail-heading" title={t.pageTitle} actions={backAction} />
        <EmptyState icon={<XCircle aria-hidden="true" />} title={t.notFound} />
      </Page>
    )
  }

  if ((state === 'error' || state === 'conflict' || state === 'stale') && !document) {
    return (
      <Page aria-labelledby="document-detail-heading">
        <PageHeader id="document-detail-heading" title={t.pageTitle} actions={backAction} />
        <InlineError message={t.error} retryLabel={t.retry} onRetry={() => void load()} />
      </Page>
    )
  }

  if (!document) return null

  const allowed = document.allowed_actions ?? []
  const can = (action: string) => allowed.some((item) => item === action)
  const docTitle = document.title ?? document.name ?? document.id

  return (
    <Page aria-labelledby="document-detail-heading">
      <PageHeader id="document-detail-heading" title={docTitle} description={t.pageTitle} actions={backAction} />

      {state === 'stale' ? (
        <InlineError message={t.stale} retryLabel={t.retry} onRetry={() => void load()} />
      ) : null}
      {actionError ? (
        <InlineError message={actionError} retryLabel={t.retry} onRetry={() => setActionError(null)} />
      ) : null}

      <Panel id="document-metadata-panel" title={t.metadataTitle} level={2}>
        <dl className="detail-list">
          <div>
            <dt>{t.classification}</dt>
            <dd>{classificationLabel(document.classification, t)}</dd>
          </div>
          <div>
            <dt>{t.state}</dt>
            <dd>
              <StatusBadge>{statusLabel(document.lifecycle_state ?? document.status, locale)}</StatusBadge>
            </dd>
          </div>
          {document.retention_until ? (
            <div>
              <dt>{t.retentionUntil}</dt>
              <dd>
                <time dateTime={document.retention_until}>{formatDate(document.retention_until, locale)}</time>
              </dd>
            </div>
          ) : null}
          <div>
            <dt>{t.restrictionPolicy}</dt>
            <dd>{document.restriction_policy_key ?? '—'}</dd>
          </div>
          {document.owner_organization_unit_id ? (
            <div>
              <dt>{t.owner}</dt>
              <dd>{document.owner_organization_unit_id}</dd>
            </div>
          ) : null}
          <div>
            <dt>{t.description}</dt>
            <dd>{document.description || t.noDescription}</dd>
          </div>
          <div>
            <dt>{t.createdAt}</dt>
            <dd>
              <time dateTime={document.created_at}>{formatDate(document.created_at, locale)}</time>
            </dd>
          </div>
          <div>
            <dt>{t.updatedAt}</dt>
            <dd>
              <time dateTime={document.updated_at}>{formatDate(document.updated_at, locale)}</time>
            </dd>
          </div>
        </dl>
      </Panel>

      <Panel id="document-versions-panel" title={t.versionsTitle} level={2}>
        {versions.length > 0 ? (
          <ul className="ui-list">
            {versions.map((version) => (
              <li key={version.id} className="ui-comment">
                <FileText aria-hidden="true" /> {version.file_name ?? version.id}
                {version.version_number ? (
                  <span>
                    {' '}
                    {t.versionNumber}: {version.version_number}
                  </span>
                ) : null}
                <small>
                  {version.availability_status ? statusLabel(version.availability_status, locale) : ''}
                  {version.created_at ? ` · ${formatDate(version.created_at, locale)}` : ''}
                </small>
              </li>
            ))}
          </ul>
        ) : (
          <EmptyState icon={<FileText aria-hidden="true" />} title={t.noVersions} />
        )}
      </Panel>

      <Panel id="document-links-panel" title={t.linksTitle} level={2}>
        {links.length > 0 ? (
          <ul className="ui-list">
            {links.map((link) => (
              <li key={link.id} className="ui-comment">
                <Link2 aria-hidden="true" />
                <span>{link.relation_type ?? '—'}</span>
                <small>
                  {link.source ? `${link.source.source_module ?? ''}/${link.source.record_type ?? ''}/${link.source.record_id ?? ''}` : link.id}
                </small>
              </li>
            ))}
          </ul>
        ) : (
          <EmptyState icon={<Link2 aria-hidden="true" />} title={t.noLinks} />
        )}
      </Panel>

      <Panel id="document-actions-panel" title={t.actionsTitle} level={2}>
        <div className="server-actions" aria-label={t.actionsTitle}>
          {can('archive') ? (
            <Button variant="secondary" disabled={busy} onClick={() => openReasonDialog(setPendingAction, setActionError, 'archive')}>
              <Archive aria-hidden="true" /> {t.actionArchive}
            </Button>
          ) : null}
          {can('unarchive') ? (
            <Button variant="secondary" disabled={busy} onClick={() => openReasonDialog(setPendingAction, setActionError, 'unarchive')}>
              <Unlock aria-hidden="true" /> {t.actionUnarchive}
            </Button>
          ) : null}
          {can('place-hold') ? (
            <Button variant="secondary" disabled={busy} onClick={() => openReasonDialog(setPendingAction, setActionError, 'place-hold')}>
              <Lock aria-hidden="true" /> {t.actionPlaceHold}
            </Button>
          ) : null}
          {can('release-hold') ? (
            <Button variant="secondary" disabled={busy} onClick={() => openReasonDialog(setPendingAction, setActionError, 'release-hold')}>
              <ShieldCheck aria-hidden="true" /> {t.actionReleaseHold}
            </Button>
          ) : null}
          {can('link') ? (
            <Button variant="secondary" disabled={busy} onClick={() => setLinkOpen(true)}>
              <Link2 aria-hidden="true" /> {t.actionLink}
            </Button>
          ) : null}
          {!can('archive') &&
          !can('unarchive') &&
          !can('place-hold') &&
          !can('release-hold') &&
          !can('link') ? (
            <p className="ui-muted">{t.noActions}</p>
          ) : null}
        </div>
      </Panel>

      {can('add-version') || can('initiate-upload') ? (
        <details className="ui-collapsible">
          <summary>{t.uploadTitle}</summary>
          <form
            className="ui-form-grid"
            onSubmit={(event: FormEvent) => {
              event.preventDefault()
              void submitUpload()
            }}
          >
            <Field id="document-upload-file" label={t.uploadFileLabel}>
              <input
                id="document-upload-file"
                className="field__control"
                type="file"
                onChange={(event) => {
                  setFile(event.target.files?.[0] ?? null)
                  setUploadError(null)
                  setUploadState('idle')
                }}
                disabled={uploadState === 'hashing' || uploadState === 'initiating' || uploadState === 'uploading' || uploadState === 'checking' || uploadState === 'completing'}
              />
            </Field>
            <p className="ui-muted">{uploadStatusCopy(uploadState, t)}</p>
            {uploadError ? <InlineError message={uploadError} /> : null}
            <div className="dialog-actions">
              <Button
                type="submit"
                disabled={!file || uploadState === 'hashing' || uploadState === 'initiating' || uploadState === 'uploading' || uploadState === 'checking' || uploadState === 'completing' || uploadState === 'done'}
              >
                {t.uploadNow}
              </Button>
            </div>
          </form>
        </details>
      ) : null}

      {pendingAction ? (
        <Drawer open onClose={() => setPendingAction(null)} title={t.reasonTitle}>
          <Field id="document-reason" label={t.reasonLabel} required>
            <textarea
              id="document-reason"
              className="field__control"
              value={reasonDraft}
              onChange={(event) => setReasonDraft(event.target.value)}
              disabled={busy}
              aria-required="true"
              placeholder={t.reasonPlaceholder}
            />
          </Field>
          <div className="dialog-actions">
            <Button variant="secondary" onClick={() => setPendingAction(null)} disabled={busy}>
              {t.cancel}
            </Button>
            <Button
              disabled={busy}
              onClick={() => {
                const reason = reasonDraft.trim()
                if (!reason) {
                  setActionError(t.reasonRequired)
                  return
                }
                void runTransition(pendingAction, reason)
              }}
            >
              {t.confirm}
            </Button>
          </div>
        </Drawer>
      ) : null}

      {linkOpen ? (
        <Drawer open onClose={() => setLinkOpen(false)} title={t.linkTitle}>
          <Field id="document-link-relation" label={t.linkRelationLabel}>
            <Select
              id="document-link-relation"
              value={linkRelation}
              onChange={setLinkRelation}
              options={linkRelationOptions(t)}
              ariaLabel={t.linkRelationLabel}
            />
          </Field>
          <Field id="document-link-module" label={t.linkModuleLabel}>
            <input
              id="document-link-module"
              className="field__control"
              value={linkModule}
              onChange={(event) => setLinkModule(event.target.value)}
              disabled={busy}
            />
          </Field>
          <Field id="document-link-record-type" label={t.linkRecordTypeLabel}>
            <input
              id="document-link-record-type"
              className="field__control"
              value={linkRecordType}
              onChange={(event) => setLinkRecordType(event.target.value)}
              disabled={busy}
            />
          </Field>
          <Field id="document-link-record-id" label={t.linkRecordIdLabel} required>
            <input
              id="document-link-record-id"
              className="field__control"
              value={linkRecordId}
              onChange={(event) => setLinkRecordId(event.target.value)}
              disabled={busy}
              aria-required="true"
              placeholder={t.linkRecordIdPlaceholder}
            />
          </Field>
          <div className="dialog-actions">
            <Button variant="secondary" onClick={() => setLinkOpen(false)} disabled={busy}>
              {t.cancel}
            </Button>
            <Button disabled={busy} onClick={() => void submitLink()}>
              {t.confirm}
            </Button>
          </div>
        </Drawer>
      ) : null}
    </Page>
  )
}

function openReasonDialog(
  setPendingAction: (value: DocumentAction | null) => void,
  setActionError: (value: string | null) => void,
  action: DocumentAction,
): void {
  setActionError(null)
  setPendingAction(action)
}

function linkRelationOptions(t: DocDetailCopy): SelectOption[] {
  return [
    { value: 'attachment', label: t.linkRelationAttachment },
    { value: 'evidence', label: t.linkRelationEvidence },
  ]
}

function classificationLabel(classification: generated.Classification, t: DocDetailCopy): string {
  switch (classification) {
    case 'public':
      return t.classificationPublic
    case 'internal':
      return t.classificationInternal
    case 'confidential':
      return t.classificationConfidential
    case 'top_secret':
      return t.classificationTopSecret
    default:
      return classification
  }
}

function uploadStatusCopy(uploadState: UploadState, t: DocDetailCopy): string {
  switch (uploadState) {
    case 'idle':
      return t.uploadIdle
    case 'hashing':
      return t.uploadHashing
    case 'initiating':
      return t.uploadInitiating
    case 'uploading':
      return t.uploadUploading
    case 'checking':
      return t.uploadChecking
    case 'completing':
      return t.uploadCompleting
    case 'done':
      return t.uploadDone
  }
}

async function sha256ForFile(file: File): Promise<string> {
  const digest = await crypto.subtle.digest('SHA-256', await file.arrayBuffer())
  return Array.from(new Uint8Array(digest), (byte) => byte.toString(16).padStart(2, '0')).join('')
}

function errorMessage(error: unknown, fallback: string): string {
  if (error instanceof ApiError) {
    return error.problem.detail ?? error.problem.title ?? fallback
  }
  if (error instanceof Error && error.message) return error.message
  return fallback
}

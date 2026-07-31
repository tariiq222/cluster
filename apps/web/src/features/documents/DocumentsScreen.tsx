import { useCallback, useEffect, useMemo, useState, type FormEvent } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { FileText, Inbox } from 'lucide-react'
import * as generated from '../../api/generated/cluster'
import { useDocumentsList } from '../../api/hooks'
import { ApiError, requestInit, unwrap } from '../../api/http'
import { useNavigate } from '../../app/navigation-context'
import { useLocale, useSessionToken } from '../../app/session-context'
import { usePrincipal } from '../../app/principal-context'
import { formatDate, statusLabel } from '../../i18n'
import { Button, EmptyState, Field, InlineError, Page, PageHeader, Panel, Select, type SelectOption, SkeletonList, StatusBadge } from '../../ui'

interface DocumentSummary {
  id: string
  title?: string
  name?: string
  classification?: string
  status?: string
  lifecycle_state?: string
  retention_until?: string | null
}

type ClassificationFilter = 'all' | generated.Classification

const copy = {
  ar: {
    pageTitle: 'المستندات',
    pageDescription: 'مستندات التجمع الصحي وتصنيفاتها',
    classificationLabel: 'التصنيف',
    classificationAll: 'كل التصنيفات',
    classificationPublic: 'عام',
    classificationInternal: 'داخلي',
    classificationConfidential: 'سري',
    classificationTopSecret: 'سري للغاية',
    loadMore: 'عرض المزيد',
    loading: 'جارٍ تحميل المستندات…',
    emptyTitle: 'لا توجد مستندات',
    emptyBody: 'أنشئ مستنداً جديداً أو غيّر عامل التصفية.',
    error: 'تعذر تحميل المستندات. يرجى إعادة المحاولة.',
    forbidden: 'غير مصرح لك بعرض المستندات.',
    retry: 'إعادة المحاولة',
    open: 'فتح المستند',
    state: 'الحالة',
    retentionUntil: 'الاحتفاظ حتى',
    createTitle: 'إنشاء مستند',
    createTitleLabel: 'العنوان',
    createTitlePlaceholder: 'عنوان المستند…',
    ownerLabel: 'الوحدة المالكة',
    ownerHelp: 'تُستخدم الوحدة الحالية من سياق الوصول.',
    ownerMissing: 'لا توجد وحدة تابعة لمنشأة في سياق الوصول الحالي، لا يمكن إنشاء مستند.',
    restrictionPolicyLabel: 'سياسة التقييد',
    restrictionPolicyHelp: 'مفتاح سياسة التقييد المطبقة على المستند.',
    create: 'إنشاء',
    creating: 'جارٍ الإنشاء…',
    titleRequired: 'عنوان المستند مطلوب.',
    createError: 'تعذر إنشاء المستند. يرجى إعادة المحاولة.',
  },
  en: {
    pageTitle: 'Documents',
    pageDescription: 'Health cluster documents and their classifications',
    classificationLabel: 'Classification',
    classificationAll: 'All classifications',
    classificationPublic: 'Public',
    classificationInternal: 'Internal',
    classificationConfidential: 'Confidential',
    classificationTopSecret: 'Top secret',
    loadMore: 'Load more',
    loading: 'Loading documents…',
    emptyTitle: 'No documents',
    emptyBody: 'Create a new document or adjust the filter.',
    error: 'Could not load documents. Please try again.',
    forbidden: 'You are not authorized to view documents.',
    retry: 'Retry',
    open: 'Open document',
    state: 'State',
    retentionUntil: 'Retention until',
    createTitle: 'Create document',
    createTitleLabel: 'Title',
    createTitlePlaceholder: 'Document title…',
    ownerLabel: 'Owner organization unit',
    ownerHelp: 'Uses the current unit from the access context.',
    ownerMissing: 'No facility-scoped unit in the current access context; documents cannot be created.',
    restrictionPolicyLabel: 'Restriction policy',
    restrictionPolicyHelp: 'Restriction policy key applied to the document.',
    create: 'Create',
    creating: 'Creating…',
    titleRequired: 'A document title is required.',
    createError: 'Could not create the document. Please try again.',
  },
} as const

type DocsCopy = (typeof copy)[keyof typeof copy]

export function DocumentsScreen() {
  const locale = useLocale()
  const csrfToken = useSessionToken()
  const navigate = useNavigate()
  const principal = usePrincipal()
  const t = copy[locale]
  const queryClient = useQueryClient()

  const [classification, setClassification] = useState<ClassificationFilter>('all')
  const [extraPages, setExtraPages] = useState<DocumentSummary[][]>([])
  const [nextCursor, setNextCursor] = useState<string | null>(null)
  const [loadingMore, setLoadingMore] = useState(false)

  const [createTitle, setCreateTitle] = useState('')
  const [createClassification, setCreateClassification] = useState('internal')
  const [restrictionPolicyKey, setRestrictionPolicyKey] = useState('restricted')
  const [creating, setCreating] = useState(false)
  const [createError, setCreateError] = useState<string | null>(null)

  const ownerUnitId = principal.effectiveScope?.scopeType === 'facility' ? principal.effectiveScope.scopeId : ''
  const canCreate = ownerUnitId.length > 0

  const filters = useMemo<generated.ListDocumentsParams>(() => {
    const params: generated.ListDocumentsParams = { limit: 50 }
    if (classification !== 'all') params.classification = classification
    return params
  }, [classification])

  const query = useDocumentsList(filters)
  const collection = query.data as generated.EntityCollection | undefined
  const forbidden = query.isError && query.error instanceof ApiError && query.error.status === 403

  const items = useMemo<DocumentSummary[] | null>(() => {
    if (!collection) return null
    return [...(collection.items as unknown as DocumentSummary[]), ...extraPages.flat()]
  }, [collection, extraPages])

  useEffect(() => {
    if (!collection) return
    setExtraPages([])
    setNextCursor(collection.next_cursor)
  }, [collection])

  const loadMore = useCallback(async () => {
    if (!nextCursor || loadingMore) return
    setLoadingMore(true)
    try {
      const params: generated.ListDocumentsParams = { limit: 50, cursor: nextCursor }
      if (classification !== 'all') params.classification = classification
      const collection = unwrap<generated.EntityCollection>(
        await generated.listDocuments(params, requestInit(null)),
      )
      setExtraPages((current) => [...current, collection.items as unknown as DocumentSummary[]])
      setNextCursor(collection.next_cursor)
    } catch {
      // keep the current page; the load-more button remains for a retry
    } finally {
      setLoadingMore(false)
    }
  }, [classification, loadingMore, nextCursor])

  const createDocument = async (event: FormEvent) => {
    event.preventDefault()
    const trimmedTitle = createTitle.trim()
    if (!trimmedTitle) {
      setCreateError(t.titleRequired)
      return
    }
    if (!canCreate) {
      setCreateError(t.ownerMissing)
      return
    }
    setCreating(true)
    setCreateError(null)
    try {
      const input: generated.DocumentCreate = {
        title: trimmedTitle,
        classification: createClassification as generated.Classification,
        owner_organization_unit_id: ownerUnitId,
        restriction_policy_key: restrictionPolicyKey.trim() || 'restricted',
      }
      unwrap<generated.Entity>(
        await generated.createDocument(input, requestInit(csrfToken, { command: true, idempotency: 'document-create' })),
      )
      setCreateTitle('')
      setCreateClassification('internal')
      setRestrictionPolicyKey('restricted')
      void queryClient.invalidateQueries({ queryKey: ['documents'] })
    } catch (cause) {
      if (cause instanceof ApiError && cause.status === 403) {
        setCreateError(t.forbidden)
      } else {
        setCreateError(t.createError)
      }
    } finally {
      setCreating(false)
    }
  }

  const classificationOptions = useMemo<SelectOption[]>(
    () => [
      { value: 'all', label: t.classificationAll },
      { value: 'public', label: t.classificationPublic },
      { value: 'internal', label: t.classificationInternal },
      { value: 'confidential', label: t.classificationConfidential },
      { value: 'top_secret', label: t.classificationTopSecret },
    ],
    [t],
  )

  const createClassificationOptions = classificationOptions.slice(1)

  return (
    <Page aria-labelledby="documents-heading">
      <PageHeader id="documents-heading" title={t.pageTitle} description={t.pageDescription} />

      <Panel id="documents-create" title={t.createTitle} level={2}>
        {!canCreate ? <p className="ui-muted">{t.ownerMissing}</p> : null}
        <form className="ui-form-grid" onSubmit={(event) => void createDocument(event)}>
          <Field id="document-create-title" label={t.createTitleLabel} required>
            <input
              id="document-create-title"
              className="field__control"
              value={createTitle}
              onChange={(event) => setCreateTitle(event.target.value)}
              maxLength={255}
              disabled={creating || !canCreate}
              aria-required="true"
              placeholder={t.createTitlePlaceholder}
            />
          </Field>
          <Field id="document-create-classification" label={t.classificationLabel}>
            <Select
              id="document-create-classification"
              value={createClassification}
              onChange={setCreateClassification}
              options={createClassificationOptions}
              ariaLabel={t.classificationLabel}
            />
          </Field>
          <Field id="document-create-owner" label={t.ownerLabel} help={t.ownerHelp}>
            <input
              id="document-create-owner"
              className="field__control"
              value={ownerUnitId}
              disabled
              readOnly
            />
          </Field>
          <Field id="document-create-restriction" label={t.restrictionPolicyLabel} help={t.restrictionPolicyHelp}>
            <input
              id="document-create-restriction"
              className="field__control"
              value={restrictionPolicyKey}
              onChange={(event) => setRestrictionPolicyKey(event.target.value)}
              maxLength={128}
              disabled={creating || !canCreate}
            />
          </Field>
          {createError ? <InlineError message={createError} /> : null}
          <div className="dialog-actions">
            <Button type="submit" disabled={creating || !canCreate}>
              {creating ? t.creating : t.create}
            </Button>
          </div>
        </form>
      </Panel>

      <Panel id="documents-list" title={t.pageTitle} level={2}>
        <div role="group" aria-label={t.classificationLabel} className="ui-form-grid">
          <Select
            id="documents-classification-filter"
            value={classification}
            onChange={(value) => setClassification(value as ClassificationFilter)}
            options={classificationOptions}
            ariaLabel={t.classificationLabel}
          />
        </div>

        {query.isPending ? (
          <SkeletonList />
        ) : forbidden ? (
          <EmptyState title={t.forbidden} />
        ) : query.isError || !items || items.length === 0 ? (
          <EmptyState icon={<Inbox aria-hidden="true" />} title={t.emptyTitle} body={t.emptyBody} />
        ) : (
          <>
            <ul className="ui-list" aria-label={t.pageTitle}>
              {items.map((document) => (
                <li key={document.id}>
                  <DocumentRow document={document} onOpen={() => navigate(`/documents/${document.id}`)} />
                </li>
              ))}
            </ul>
            {nextCursor ? (
              <div className="ui-pagination">
                <Button variant="secondary" disabled={loadingMore} onClick={() => void loadMore()}>
                  {loadingMore ? t.loading : t.loadMore}
                </Button>
              </div>
            ) : null}
          </>
        )}
        {query.isError && !forbidden ? (
          <InlineError message={t.error} retryLabel={t.retry} onRetry={() => void query.refetch()} />
        ) : null}
      </Panel>
    </Page>
  )
}

function DocumentRow({ document, onOpen }: { document: DocumentSummary; onOpen: () => void }) {
  const locale = useLocale()
  const t = copy[locale]
  const title = document.title ?? document.name ?? document.id
  const stateValue = document.lifecycle_state ?? document.status ?? ''
  const variant =
    stateValue === 'archived'
      ? 'neutral'
      : stateValue === 'draft'
        ? 'warning'
        : stateValue === 'active'
          ? 'success'
          : 'info'
  return (
    <article className="ui-panel" aria-labelledby={`document-row-${document.id}`}>
      <header className="ui-panel__header">
        <div>
          <h3 id={`document-row-${document.id}`}>{title}</h3>
          {document.classification ? <p>{classificationLabel(document.classification, t)}</p> : null}
        </div>
        <Button variant="quiet" onClick={onOpen}>
          <FileText aria-hidden="true" /> {t.open}
        </Button>
      </header>
      <dl className="detail-list">
        <div>
          <dt>{t.state}</dt>
          <dd>
            <StatusBadge variant={variant}>{stateValue ? statusLabel(stateValue, locale) : '—'}</StatusBadge>
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
      </dl>
    </article>
  )
}

function classificationLabel(classification: string, t: DocsCopy): string {
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

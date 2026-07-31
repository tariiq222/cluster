import { useCallback, useEffect, useState, type FormEvent } from 'react'
import * as generated from '../../../src/api/generated/cluster'
import { ApiError, requestInit, unwrap } from '../../api/http'
import { useLocale, useSessionToken } from '../../app/session-context'
import { usePrincipal } from '../../app/principal-context'
import { formatDate, shellCopy, statusLabel } from '../../i18n'
import { Button, EmptyState, Field, InlineError, Page, PageHeader, Panel, Select, SkeletonList, StatusBadge } from '../../ui'

const copy = {
  ar: {
    approvalInboxTitle: 'صندوق الموافقات',
    approvalInboxDescription: 'حالات العمل الموكلة إليك بانتظار موافقتك.',
    myRequestsTitle: 'طلباتي',
    myRequestsDescription: 'حالات العمل التي بدأتها.',
    workDefinitionsTitle: 'تعريفات العمل',
    workDefinitionsDescription: 'إدارة تعريفات العمل وإصداراتها ونشرها.',
    featureDisabled: 'الميزة غير مفعلة',
    featureDisabledBody: 'إدارة سير العمل غير مفعلة لمنطقتك.',
    empty: 'لا توجد بيانات.',
    error: 'حدث خطأ غير متوقع.',
    retry: 'إعادة المحاولة',
    forbidden: 'غير مصرح',
    status: 'الحالة',
    created: 'تاريخ الإنشاء',
    createDefinition: 'إنشاء تعريف عمل',
    code: 'الرمز',
    name: 'الاسم',
    descriptionLabel: 'الوصف',
    classification: 'التصنيف الافتراضي',
    create: 'إنشاء',
    creating: 'جارٍ الإنشاء…',
    versions: 'إصدارات التعريف',
    publish: 'نشر',
    publishing: 'جارٍ النشر…',
    selectDefinition: 'اختر تعريفًا لعرض إصداراته.',
    version: 'الإصدار',
    formError: 'تعذر حفظ التعريف.',
    publishFailed: 'تعذر نشر الإصدار.',
    classificationPublic: 'عام',
    classificationInternal: 'داخلي',
    classificationConfidential: 'سري',
    classificationTopSecret: 'سري للغاية',
  },
  en: {
    approvalInboxTitle: 'Approval inbox',
    approvalInboxDescription: 'Workflow instances assigned to you awaiting your approval.',
    myRequestsTitle: 'My requests',
    myRequestsDescription: 'Workflow instances that you initiated.',
    workDefinitionsTitle: 'Work definitions',
    workDefinitionsDescription: 'Manage work definitions, their versions, and publishing.',
    featureDisabled: 'Feature is not enabled',
    featureDisabledBody: 'Workflow management is not enabled for your scope.',
    empty: 'No data.',
    error: 'Something went wrong.',
    retry: 'Retry',
    forbidden: 'Forbidden',
    status: 'Status',
    created: 'Created',
    createDefinition: 'Create work definition',
    code: 'Code',
    name: 'Name',
    descriptionLabel: 'Description',
    classification: 'Default classification',
    create: 'Create',
    creating: 'Creating…',
    versions: 'Definition versions',
    publish: 'Publish',
    publishing: 'Publishing…',
    selectDefinition: 'Select a definition to see its versions.',
    version: 'Version',
    formError: 'Could not save the definition.',
    publishFailed: 'Could not publish the version.',
    classificationPublic: 'Public',
    classificationInternal: 'Internal',
    classificationConfidential: 'Confidential',
    classificationTopSecret: 'Top secret',
  },
} as const

type CopyKey = keyof (typeof copy)['ar']

function t(locale: 'ar' | 'en', key: CopyKey): string {
  return copy[locale][key]
}

type WorkflowState = 'loading' | 'ready' | 'empty' | 'forbidden' | 'error' | 'disabled'

interface InstanceView {
  id: string
  title: string
  status: string
  lifecycle_state?: string
  version_number?: number
  lock_version?: number
  created_at: string
}

function toInstanceView(item: generated.Entity): InstanceView {
  const record = item as {
    id?: string
    status?: string
    title?: string
    name?: string
    lifecycle_state?: string
    version_number?: number
    lock_version?: number
    created_at?: string
  }
  return {
    id: record.id ?? '',
    title: record.title ?? record.name ?? record.id ?? '',
    status: record.status ?? 'unknown',
    lifecycle_state: record.lifecycle_state,
    version_number: record.version_number,
    lock_version: record.lock_version,
    created_at: record.created_at ?? '',
  }
}

function badgeVariantFor(status: string): 'neutral' | 'success' | 'warning' | 'danger' | 'info' {
  switch (status) {
    case 'completed':
    case 'approved':
    case 'published':
    case 'active':
      return 'success'
    case 'cancelled':
    case 'rejected':
    case 'disabled':
    case 'archived':
      return 'danger'
    case 'pending':
    case 'in_progress':
    case 'running':
    case 'submitted':
      return 'info'
    case 'blocked':
    case 'returned':
      return 'warning'
    default:
      return 'neutral'
  }
}

function isFeatureDisabled(error: unknown): boolean {
  if (!(error instanceof ApiError)) return false
  if (error.status === 404) return true
  if (error.status === 409 && error.problem?.type?.includes('feature-disabled')) return true
  return false
}

function useWorkflowFeature(): boolean {
  const { features } = usePrincipal()
  return features?.work_management === true
}

function WorkflowFeatureDisabled() {
  const locale = useLocale()
  return <EmptyState title={t(locale, 'featureDisabled')} body={t(locale, 'featureDisabledBody')} />
}

function useWorkflowInstances(enabled: boolean) {
  const csrfToken = useSessionToken()
  const [state, setState] = useState<WorkflowState>('loading')
  const [items, setItems] = useState<generated.Entity[]>([])

  const load = useCallback(async () => {
    if (!enabled) return
    setState('loading')
    try {
      const response = await generated.listWorkflowInstances({ limit: 50 }, requestInit(csrfToken))
      const collection = unwrap<generated.EntityCollection>(response)
      setItems(collection.items)
      setState(collection.items.length === 0 ? 'empty' : 'ready')
    } catch (error) {
      if (isFeatureDisabled(error)) {
        setState('disabled')
      } else if (error instanceof ApiError && error.status === 403) {
        setState('forbidden')
      } else {
        setState('error')
      }
    }
  }, [csrfToken, enabled])

  useEffect(() => {
    if (enabled) void load()
  }, [enabled, load])

  return { state, items, reload: load }
}

function WorkflowInstancesPage({
  pageId,
  title,
  description,
  state,
  items,
  onRetry,
}: {
  pageId: string
  title: string
  description: string
  state: WorkflowState
  items: generated.Entity[]
  onRetry: () => void
}) {
  const locale = useLocale()
  return (
    <Page>
      <PageHeader id={pageId} title={title} description={description} />
      {state === 'loading' && <SkeletonList rows={5} />}
      {state === 'disabled' && <WorkflowFeatureDisabled />}
      {state === 'forbidden' && <EmptyState title={shellCopy[locale].denied} />}
      {state === 'error' && <InlineError message={t(locale, 'error')} retryLabel={t(locale, 'retry')} onRetry={onRetry} />}
      {state === 'empty' && <EmptyState title={t(locale, 'empty')} />}
      {state === 'ready' && (
        <ul className="screen-list">
          {items.map((item) => {
            const view = toInstanceView(item)
            return (
              <li key={view.id} className="screen-list__row">
                <div>
                  <div className="screen-list__row-title">{view.title}</div>
                  <div className="screen-list__row-meta">
                    {view.lifecycle_state ? `${statusLabel(view.lifecycle_state, locale)} · ` : ''}
                    {t(locale, 'created')}: {formatDate(view.created_at, locale)}
                  </div>
                </div>
                <StatusBadge variant={badgeVariantFor(view.status)}>{statusLabel(view.status, locale)}</StatusBadge>
              </li>
            )
          })}
        </ul>
      )}
    </Page>
  )
}

export function ApprovalInboxScreen() {
  const locale = useLocale()
  const enabled = useWorkflowFeature()
  const { state, items, reload } = useWorkflowInstances(enabled)

  if (!enabled) return <WorkflowFeatureDisabled />

  return (
    <WorkflowInstancesPage
      pageId="approval-inbox-title"
      title={t(locale, 'approvalInboxTitle')}
      description={t(locale, 'approvalInboxDescription')}
      state={state}
      items={items}
      onRetry={reload}
    />
  )
}

export function MyRequestsScreen() {
  const locale = useLocale()
  const enabled = useWorkflowFeature()
  const { state, items, reload } = useWorkflowInstances(enabled)

  if (!enabled) return <WorkflowFeatureDisabled />

  return (
    <WorkflowInstancesPage
      pageId="my-requests-title"
      title={t(locale, 'myRequestsTitle')}
      description={t(locale, 'myRequestsDescription')}
      state={state}
      items={items}
      onRetry={reload}
    />
  )
}

const classificationOptions = [
  { value: generated.Classification.public, label: 'public' },
  { value: generated.Classification.internal, label: 'internal' },
  { value: generated.Classification.confidential, label: 'confidential' },
  { value: generated.Classification.top_secret, label: 'top_secret' },
] as const

function classificationLabel(key: string, locale: 'ar' | 'en'): string {
  switch (key) {
    case 'public':
      return t(locale, 'classificationPublic')
    case 'internal':
      return t(locale, 'classificationInternal')
    case 'confidential':
      return t(locale, 'classificationConfidential')
    case 'top_secret':
      return t(locale, 'classificationTopSecret')
    default:
      return key
  }
}

export function WorkDefinitionsScreen() {
  const locale = useLocale()
  const csrfToken = useSessionToken()
  const enabled = useWorkflowFeature()

  const [state, setState] = useState<WorkflowState>('loading')
  const [items, setItems] = useState<generated.Entity[]>([])
  const [formError, setFormError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)
  const [code, setCode] = useState('')
  const [name, setName] = useState('')
  const [classification, setClassification] = useState<generated.Classification>(generated.Classification.public)
  const [description, setDescription] = useState('')

  const [selectedId, setSelectedId] = useState<string | null>(null)
  const [versionsState, setVersionsState] = useState<'idle' | WorkflowState>('idle')
  const [versionItems, setVersionItems] = useState<generated.Entity[]>([])
  const [publishingId, setPublishingId] = useState<string | null>(null)
  const [publishError, setPublishError] = useState(false)

  const load = useCallback(async () => {
    if (!enabled) return
    setState('loading')
    try {
      const response = await generated.listWorkDefinitions({ limit: 50 }, requestInit(csrfToken))
      const collection = unwrap<generated.EntityCollection>(response)
      setItems(collection.items)
      setState(collection.items.length === 0 ? 'empty' : 'ready')
    } catch (error) {
      if (isFeatureDisabled(error)) {
        setState('disabled')
      } else if (error instanceof ApiError && error.status === 403) {
        setState('forbidden')
      } else {
        setState('error')
      }
    }
  }, [csrfToken, enabled])

  useEffect(() => {
    if (enabled) void load()
  }, [enabled, load])

  const loadVersions = useCallback(
    async (definitionId: string) => {
      setVersionsState('loading')
      setPublishError(false)
      try {
        const response = await generated.listWorkDefinitionVersions(
          definitionId,
          { limit: 50 },
          requestInit(csrfToken),
        )
        const collection = unwrap<generated.EntityCollection>(response)
        setVersionItems(collection.items)
        setVersionsState(collection.items.length === 0 ? 'empty' : 'ready')
      } catch (error) {
        if (isFeatureDisabled(error)) {
          setVersionsState('disabled')
        } else {
          setVersionsState('error')
        }
      }
    },
    [csrfToken],
  )

  const selectDefinition = useCallback(
    (definitionId: string) => {
      setSelectedId(definitionId)
      void loadVersions(definitionId)
    },
    [loadVersions],
  )

  const createDefinition = useCallback(
    async (event: FormEvent<HTMLFormElement>) => {
      event.preventDefault()
      setSubmitting(true)
      setFormError(null)
      try {
        await unwrap(
          await generated.createWorkDefinition(
            {
              code: code.trim(),
              name: name.trim(),
              default_classification: classification,
              description: description.trim() || undefined,
            },
            requestInit(csrfToken, { command: true }),
          ),
        )
        setCode('')
        setName('')
        setDescription('')
        await load()
      } catch (error) {
        if (isFeatureDisabled(error)) {
          setState('disabled')
        } else if (error instanceof ApiError && error.status === 403) {
          setState('forbidden')
        } else {
          setFormError(
            error instanceof ApiError ? (error.problem.detail ?? error.problem.title) : t(locale, 'formError'),
          )
        }
      } finally {
        setSubmitting(false)
      }
    },
    [csrfToken, code, name, classification, description, load, locale],
  )

  const publishVersion = useCallback(
    async (version: generated.Entity) => {
      const view = toInstanceView(version)
      setPublishingId(view.id)
      setPublishError(false)
      try {
        await unwrap(
          await generated.publishWorkDefinitionVersion(
            view.id,
            requestInit(csrfToken, { command: true, lockVersion: view.lock_version ?? 1 }),
          ),
        )
        if (selectedId) await loadVersions(selectedId)
      } catch {
        setPublishError(true)
      } finally {
        setPublishingId(null)
      }
    },
    [csrfToken, selectedId, loadVersions],
  )

  if (!enabled) return <WorkflowFeatureDisabled />

  return (
    <Page>
      <PageHeader
        id="work-definitions-title"
        title={t(locale, 'workDefinitionsTitle')}
        description={t(locale, 'workDefinitionsDescription')}
      />
      {state === 'loading' && <SkeletonList rows={5} />}
      {state === 'disabled' && <WorkflowFeatureDisabled />}
      {state === 'forbidden' && <EmptyState title={shellCopy[locale].denied} />}
      {state === 'error' && <InlineError message={t(locale, 'error')} retryLabel={t(locale, 'retry')} onRetry={() => void load()} />}
      {(state === 'ready' || state === 'empty') && (
        <div className="detail-grid">
          <Panel id="work-definitions-list" title={t(locale, 'workDefinitionsTitle')}>
            {state === 'empty' && <EmptyState title={t(locale, 'empty')} />}
            {state === 'ready' && (
              <ul className="screen-list">
                {items.map((item) => {
                  const view = toInstanceView(item)
                  const isSelected = view.id === selectedId
                  return (
                    <li key={view.id}>
                      <button
                        type="button"
                        className="screen-list__row screen-list__row--button"
                        aria-pressed={isSelected}
                        onClick={() => selectDefinition(view.id)}
                      >
                        <span>
                          <span className="screen-list__row-title">{view.title}</span>
                          <span className="screen-list__row-meta">
                            {view.lifecycle_state ? `${statusLabel(view.lifecycle_state, locale)} · ` : ''}
                            {formatDate(view.created_at, locale)}
                          </span>
                        </span>
                        <StatusBadge variant={badgeVariantFor(view.status)}>{statusLabel(view.status, locale)}</StatusBadge>
                      </button>
                    </li>
                  )
                })}
              </ul>
            )}
          </Panel>
          <div className="detail-list">
            <Panel id="work-definition-create" title={t(locale, 'createDefinition')}>
              <form className="inline-form" onSubmit={(event) => void createDefinition(event)}>
                <Field id="work-definition-code" label={t(locale, 'code')} required>
                  <input
                    id="work-definition-code"
                    className="field__control"
                    required
                    value={code}
                    onChange={(event) => setCode(event.currentTarget.value)}
                  />
                </Field>
                <Field id="work-definition-name" label={t(locale, 'name')} required>
                  <input
                    id="work-definition-name"
                    className="field__control"
                    required
                    value={name}
                    onChange={(event) => setName(event.currentTarget.value)}
                  />
                </Field>
                <Field id="work-definition-classification" label={t(locale, 'classification')} required>
                  <Select
                    id="work-definition-classification"
                    value={classification}
                    onChange={(value) => setClassification(value as generated.Classification)}
                    options={classificationOptions.map((option) => ({
                      value: option.value,
                      label: classificationLabel(option.value, locale),
                    }))}
                  />
                </Field>
                <Field id="work-definition-description" label={t(locale, 'descriptionLabel')}>
                  <textarea
                    id="work-definition-description"
                    className="field__control"
                    rows={3}
                    value={description}
                    onChange={(event) => setDescription(event.currentTarget.value)}
                  />
                </Field>
                <Button type="submit" disabled={submitting}>
                  {submitting ? t(locale, 'creating') : t(locale, 'create')}
                </Button>
              </form>
              {formError && <InlineError message={formError} />}
            </Panel>
            <Panel id="work-definition-versions" title={t(locale, 'versions')}>
              {!selectedId && <p>{t(locale, 'selectDefinition')}</p>}
              {selectedId && versionsState === 'loading' && <SkeletonList rows={3} />}
              {selectedId && versionsState === 'disabled' && <WorkflowFeatureDisabled />}
              {selectedId && versionsState === 'error' && (
                <InlineError
                  message={t(locale, 'error')}
                  retryLabel={t(locale, 'retry')}
                  onRetry={() => void loadVersions(selectedId)}
                />
              )}
              {selectedId && versionsState === 'empty' && <EmptyState title={t(locale, 'empty')} />}
              {selectedId && versionsState === 'ready' && (
                <>
                  {publishError && <InlineError message={t(locale, 'publishFailed')} />}
                  <ul className="screen-list">
                    {versionItems.map((version) => {
                      const view = toInstanceView(version)
                      const isPublishing = publishingId === view.id
                      const isPublished = view.lifecycle_state === 'published'
                      return (
                        <li key={view.id} className="screen-list__row">
                          <div>
                            <div className="screen-list__row-title">
                              {t(locale, 'version')} #{view.version_number ?? view.id}
                            </div>
                            <div className="screen-list__row-meta">
                              {view.lifecycle_state ? `${statusLabel(view.lifecycle_state, locale)} · ` : ''}
                              {formatDate(view.created_at, locale)}
                            </div>
                          </div>
                          <div className="screen-list__row-actions">
                            <StatusBadge variant={badgeVariantFor(view.lifecycle_state ?? view.status)}>
                              {statusLabel(view.lifecycle_state ?? view.status, locale)}
                            </StatusBadge>
                            {!isPublished && (
                              <Button variant="secondary" disabled={isPublishing} onClick={() => void publishVersion(version)}>
                                {isPublishing ? t(locale, 'publishing') : t(locale, 'publish')}
                              </Button>
                            )}
                          </div>
                        </li>
                      )
                    })}
                  </ul>
                </>
              )}
            </Panel>
          </div>
        </div>
      )}
    </Page>
  )
}

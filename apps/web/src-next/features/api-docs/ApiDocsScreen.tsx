import { useCallback, useMemo, useState } from 'react'
import { customFetch, requestInit, stateFromError, type ResourceState } from '../../api/http'
import { useLocale, useSessionToken } from '../../app/session-context'
import { usePrincipal } from '../../app/principal-context'
import { shellCopy } from '../../i18n'
import { Button, EmptyState, InlineError, Page, PageHeader, Panel, SkeletonList, StatusBadge } from '../../ui'

const copy = {
  ar: {
    title: 'مرجع API',
    description: 'وثائق واجهة برمجة التطبيقات (API) لمنصة التجمع الصحي.',
    loadDocs: 'تحميل مرجع API',
    loading: 'جارٍ تحميل المواصفات…',
    error: 'تعذر تحميل مواصفات API.',
    retry: 'إعادة المحاولة',
    paths: 'المسارات',
    noPaths: 'لا توجد مسارات في المواصفات.',
    method: 'الطريقة',
    operationId: 'معرّف العملية',
    summary: 'الملخص',
    parameters: 'المعلمات',
    parameterName: 'الاسم',
    parameterIn: 'الموقع',
    required: 'مطلوب',
    yes: 'نعم',
    no: 'لا',
    requestBody: 'نص الطلب',
    requestBodyNone: 'بدون',
    responses: 'الاستجابات',
    responseDescription: 'الوصف',
    schema: 'النموذج',
    unknown: 'غير معروف',
    general: 'عام',
    selectPath: 'اختر مسارًا لعرض تفاصيله.',
  },
  en: {
    title: 'API Reference',
    description: 'Documentation of the Health Cluster Platform API.',
    loadDocs: 'Load API reference',
    loading: 'Loading the specification…',
    error: 'Could not load the API specification.',
    retry: 'Retry',
    paths: 'Paths',
    noPaths: 'The specification contains no paths.',
    method: 'Method',
    operationId: 'Operation ID',
    summary: 'Summary',
    parameters: 'Parameters',
    parameterName: 'Name',
    parameterIn: 'In',
    required: 'Required',
    yes: 'Yes',
    no: 'No',
    requestBody: 'Request body',
    requestBodyNone: 'None',
    responses: 'Responses',
    responseDescription: 'Description',
    schema: 'Schema',
    unknown: 'Unknown',
    general: 'General',
    selectPath: 'Select a path to see its details.',
  },
} as const

type CopyKey = keyof (typeof copy)['ar']

function t(locale: 'ar' | 'en', key: CopyKey): string {
  return copy[locale][key]
}

interface SpecSchema {
  $ref?: string
  type?: string
}

interface SpecParameter {
  name?: string
  in?: string
  required?: boolean
  schema?: SpecSchema
}

interface SpecOperation {
  operationId?: string
  summary?: string
  description?: string
  tags?: string[]
  parameters?: SpecParameter[]
  requestBody?: {
    content?: Record<string, { schema?: SpecSchema }>
  }
  responses?: Record<string, { description?: string }>
}

interface OpenApiSpec {
  openapi?: string
  info?: { title?: string; version?: string }
  paths?: Record<string, Record<string, SpecOperation>>
}

interface PathEntry {
  path: string
  method: string
  operation: SpecOperation
}

interface TagGroup {
  tag: string
  entries: PathEntry[]
}

function parseSpec(data: unknown): OpenApiSpec | null {
  if (typeof data !== 'object' || data === null) return null
  const candidate = data as OpenApiSpec
  if (!candidate.paths || typeof candidate.paths !== 'object') return null
  return candidate
}

function schemaName(schema: SpecSchema | undefined, fallback: string): string {
  if (!schema) return fallback
  if (schema.$ref) return schema.$ref.split('/').pop() ?? fallback
  return schema.type ?? fallback
}

function methodVariant(method: string): 'success' | 'info' | 'warning' | 'danger' | 'neutral' {
  switch (method.toLowerCase()) {
    case 'get':
      return 'success'
    case 'post':
      return 'info'
    case 'put':
    case 'patch':
      return 'warning'
    case 'delete':
      return 'danger'
    default:
      return 'neutral'
  }
}

function groupPaths(spec: OpenApiSpec): TagGroup[] {
  const groups = new Map<string, PathEntry[]>()
  for (const [path, methods] of Object.entries(spec.paths ?? {})) {
    if (!methods || typeof methods !== 'object') continue
    for (const [method, operation] of Object.entries(methods)) {
      if (!operation || typeof operation !== 'object') continue
      const tags = operation.tags && operation.tags.length > 0 ? operation.tags : ['general']
      for (const tag of tags) {
        const entries = groups.get(tag) ?? []
        entries.push({ path, method, operation })
        groups.set(tag, entries)
      }
    }
  }
  return Array.from(groups.entries()).map(([tag, entries]) => ({
    tag,
    entries: entries.sort((a, b) => a.path.localeCompare(b.path)),
  }))
}

type DocsState = 'idle' | ResourceState

export function ApiDocsScreen() {
  const locale = useLocale()
  const csrfToken = useSessionToken()
  const { capabilities } = usePrincipal()

  const [state, setState] = useState<DocsState>('idle')
  const [spec, setSpec] = useState<OpenApiSpec | null>(null)
  const [selected, setSelected] = useState<{ path: string; method: string } | null>(null)

  const canRead = capabilities?.includes('authorization.audit.read') === true

  const loadDocs = useCallback(async () => {
    setState('loading')
    try {
      const primary = await customFetch('/api/v1/openapi', {
        ...requestInit(csrfToken),
        method: 'GET',
      })
      const parsed = parseSpec(primary.data)
      if (parsed) {
        setSpec(parsed)
        setState('ready')
        return
      }
      const fallback = await fetch('/openapi.yaml', {
        credentials: 'include',
        headers: { Accept: 'application/json, application/yaml, text/yaml' },
      })
      if (fallback.ok) {
        const text = await fallback.text()
        try {
          const parsedFallback = parseSpec(JSON.parse(text))
          if (parsedFallback) {
            setSpec(parsedFallback)
            setState('ready')
            return
          }
        } catch {
          // not JSON: the YAML fallback is not served; report the error below
        }
      }
      setState('error')
    } catch (error) {
      setState(stateFromError(error))
    }
  }, [csrfToken])

  const groups = useMemo(() => (spec ? groupPaths(spec) : []), [spec])

  const selectedEntry = useMemo(() => {
    if (!selected) return null
    const group = groups.find((g) => g.entries.some((e) => e.path === selected.path && e.method === selected.method))
    return group?.entries.find((e) => e.path === selected.path && e.method === selected.method) ?? null
  }, [groups, selected])

  if (!canRead) {
    return <EmptyState title={shellCopy[locale].denied} />
  }

  return (
    <Page>
      <PageHeader id="api-docs-title" title={t(locale, 'title')} description={t(locale, 'description')} />
      {state === 'idle' && (
        <Panel id="api-docs-intro" title={t(locale, 'title')}>
          <p>{t(locale, 'description')}</p>
          <div className="form-actions">
            <Button onClick={() => void loadDocs()}>{t(locale, 'loadDocs')}</Button>
          </div>
        </Panel>
      )}
      {state === 'loading' && <SkeletonList rows={6} />}
      {state === 'forbidden' && <EmptyState title={shellCopy[locale].denied} />}
      {(state === 'error' || state === 'stale' || state === 'conflict') && (
        <InlineError message={t(locale, 'error')} retryLabel={t(locale, 'retry')} onRetry={() => void loadDocs()} />
      )}
      {state === 'ready' && groups.length === 0 && <EmptyState title={t(locale, 'noPaths')} />}
      {state === 'ready' && groups.length > 0 && (
        <div className="detail-grid">
          <Panel id="api-docs-paths" title={t(locale, 'paths')}>
            {groups.map((group) => (
              <section key={group.tag} aria-labelledby={`api-docs-tag-${group.tag}`}>
                <h3 className="panel__heading" id={`api-docs-tag-${group.tag}`}>
                  {group.tag}
                </h3>
                <ul className="screen-list">
                  {group.entries.map((entry) => {
                    const isSelected = selected?.path === entry.path && selected?.method === entry.method
                    return (
                      <li key={`${entry.method}-${entry.path}`}>
                        <button
                          type="button"
                          className={`screen-list__row screen-list__row--button${isSelected ? ' screen-list__row--selected' : ''}`}
                          aria-current={isSelected ? 'true' : undefined}
                          aria-pressed={isSelected}
                          onClick={() => setSelected({ path: entry.path, method: entry.method })}
                        >
                          <span>
                            <span className="screen-list__row-title">{entry.path}</span>
                            {entry.operation.summary && (
                              <span className="screen-list__row-meta"> — {entry.operation.summary}</span>
                            )}
                          </span>
                          <StatusBadge variant={methodVariant(entry.method)}>{entry.method.toUpperCase()}</StatusBadge>
                        </button>
                      </li>
                    )
                  })}
                </ul>
              </section>
            ))}
          </Panel>
          <Panel id="api-docs-detail" title={t(locale, 'method')}>
            {!selectedEntry && <p>{t(locale, 'selectPath')}</p>}
            {selectedEntry && (
              <div className="detail-list">
                <div className="detail-list__row">
                  <span className="detail-list__key">{t(locale, 'method')}</span>
                  <span className="detail-list__value">
                    <StatusBadge variant={methodVariant(selectedEntry.method)}>
                      {selectedEntry.method.toUpperCase()} {selectedEntry.path}
                    </StatusBadge>
                  </span>
                </div>
                <div className="detail-list__row">
                  <span className="detail-list__key">{t(locale, 'operationId')}</span>
                  <span className="detail-list__value">{selectedEntry.operation.operationId ?? t(locale, 'unknown')}</span>
                </div>
                <div className="detail-list__row">
                  <span className="detail-list__key">{t(locale, 'summary')}</span>
                  <span className="detail-list__value">
                    {selectedEntry.operation.summary ?? selectedEntry.operation.description ?? t(locale, 'unknown')}
                  </span>
                </div>
                <div className="detail-list__row">
                  <span className="detail-list__key">{t(locale, 'parameters')}</span>
                  <span className="detail-list__value">
                    {!selectedEntry.operation.parameters || selectedEntry.operation.parameters.length === 0 ? (
                      t(locale, 'requestBodyNone')
                    ) : (
                      <table className="entity-table">
                        <thead>
                          <tr>
                            <th scope="col">{t(locale, 'parameterName')}</th>
                            <th scope="col">{t(locale, 'parameterIn')}</th>
                            <th scope="col">{t(locale, 'required')}</th>
                            <th scope="col">{t(locale, 'schema')}</th>
                          </tr>
                        </thead>
                        <tbody>
                          {selectedEntry.operation.parameters.map((parameter) => (
                            <tr key={`${parameter.in}-${parameter.name}`}>
                              <td>{parameter.name ?? t(locale, 'unknown')}</td>
                              <td>{parameter.in ?? t(locale, 'unknown')}</td>
                              <td>{parameter.required === true ? t(locale, 'yes') : t(locale, 'no')}</td>
                              <td>{schemaName(parameter.schema, t(locale, 'unknown'))}</td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    )}
                  </span>
                </div>
                <div className="detail-list__row">
                  <span className="detail-list__key">{t(locale, 'requestBody')}</span>
                  <span className="detail-list__value">
                    {schemaName(
                      selectedEntry.operation.requestBody?.content?.['application/json']?.schema,
                      t(locale, 'requestBodyNone'),
                    )}
                  </span>
                </div>
                <div className="detail-list__row">
                  <span className="detail-list__key">{t(locale, 'responses')}</span>
                  <span className="detail-list__value">
                    {!selectedEntry.operation.responses || Object.keys(selectedEntry.operation.responses).length === 0 ? (
                      t(locale, 'requestBodyNone')
                    ) : (
                      <table className="entity-table">
                        <thead>
                          <tr>
                            <th scope="col">{t(locale, 'method')}</th>
                            <th scope="col">{t(locale, 'responseDescription')}</th>
                          </tr>
                        </thead>
                        <tbody>
                          {Object.entries(selectedEntry.operation.responses).map(([code, response]) => (
                            <tr key={code}>
                              <td>{code}</td>
                              <td>{response.description ?? t(locale, 'unknown')}</td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    )}
                  </span>
                </div>
              </div>
            )}
          </Panel>
        </div>
      )}
    </Page>
  )
}

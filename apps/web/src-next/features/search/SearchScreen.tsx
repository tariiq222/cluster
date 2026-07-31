import { useCallback, useEffect, useState, type FormEvent } from 'react'
import * as generated from '../../../src/api/generated/cluster'
import type { Entity } from '../../../src/api/generated/cluster'
import { ApiError, requestInit, unwrap } from '../../api/http'
import { usePrincipal } from '../../app/principal-context'
import { useLocale, useSessionToken } from '../../app/session-context'
import { statusLabel } from '../../i18n'
import { Button, EmptyState, Field, InlineError, Page, PageHeader, Panel, Select, SkeletonList, StatusBadge } from '../../ui'

const copy = {
  ar: {
    title: 'بحث',
    description: 'ابحث في المحتوى المصرح لك به فقط.',
    query: 'نص البحث',
    queryPlaceholder: 'ابحث في المنصة…',
    type: 'النوع',
    status: 'الحالة',
    all: 'الكل',
    submit: 'بحث',
    searching: 'جارٍ البحث…',
    invalidQuery: 'أدخل استعلام بحث صالحًا (من 1 إلى 256 حرفًا).',
    failed: 'تعذر إكمال البحث.',
    noResults: 'لا توجد نتائج',
    noResultsBody: 'جرّب استعلامًا آخر أو وسّع المرشحات.',
    resultsFor: 'نتائج',
    loadMore: 'عرض المزيد',
    retry: 'إعادة المحاولة',
    denied: 'غير مصرح لك باستخدام البحث.',
    sourceType: 'النوع',
    sourceId: 'المعرّف',
    excerpt: 'ملخص',
    notAvailable: 'غير متاح',
  },
  en: {
    title: 'Search',
    description: 'Search only content you are authorized to see.',
    query: 'Search text',
    queryPlaceholder: 'Search the platform…',
    type: 'Type',
    status: 'Status',
    all: 'All',
    submit: 'Search',
    searching: 'Searching…',
    invalidQuery: 'Enter a valid search query (1 to 256 characters).',
    failed: 'The search could not be completed.',
    noResults: 'No results',
    noResultsBody: 'Try a different query or widen the filters.',
    resultsFor: 'Results',
    loadMore: 'Load more',
    retry: 'Retry',
    denied: 'You are not authorized to use search.',
    sourceType: 'Type',
    sourceId: 'ID',
    excerpt: 'Summary',
    notAvailable: 'Not available',
  },
} as const

const TYPE_VALUES = ['work_record', 'task', 'document'] as const

const TYPE_LABELS: Record<string, { ar: string; en: string }> = {
  work_record: { ar: 'سجل عمل', en: 'Work record' },
  task: { ar: 'مهمة', en: 'Task' },
  document: { ar: 'مستند', en: 'Document' },
}

const STATUS_VALUES = ['draft', 'submitted', 'in_review', 'approved', 'completed'] as const

function resultTitle(entity: Entity): string {
  if ('resource_type' in entity) {
    const title = entity.title ?? entity.name ?? entity.code
    if (title) return String(title)
  }
  if ('record_number' in entity) return entity.record_number
  return entity.id
}

function resultSourceType(entity: Entity): string {
  return 'resource_type' in entity ? String(entity.resource_type) : 'work_record'
}

function resultExcerpt(entity: Entity): string {
  if ('description' in entity && entity.description) return String(entity.description)
  return ''
}

function resultSourceId(entity: Entity): string | null {
  return 'source' in entity && entity.source ? entity.source.record_id : null
}

export function SearchScreen({ initialQuery = '' }: { initialQuery?: string }) {
  const locale = useLocale()
  const csrfToken = useSessionToken()
  const principal = usePrincipal()
  const t = copy[locale]
  const canSearch = principal.capabilities?.includes('search.query') ?? false

  const [query, setQuery] = useState('')
  const [typeFilter, setTypeFilter] = useState('')
  const [statusFilter, setStatusFilter] = useState('')
  const [items, setItems] = useState<Entity[]>([])
  const [state, setState] = useState<'idle' | 'loading' | 'ready' | 'empty' | 'invalid' | 'error'>('idle')
  const [submitting, setSubmitting] = useState(false)
  const [nextCursor, setNextCursor] = useState<string | null>(null)
  const [loadingMore, setLoadingMore] = useState(false)
  const [lastSearch, setLastSearch] = useState<{ query: string; type: string; status: string } | null>(null)

  const runSearch = useCallback(
    async (q: string, type = '', status = '', cursor: string | null = null) => {
      if (!cursor) {
        setLastSearch({ query: q, type, status })
        setSubmitting(true)
        setItems([])
        setNextCursor(null)
      } else {
        setLoadingMore(true)
      }
      try {
        const page = unwrap<generated.CollectionResponse>(
          await generated.search(
            {
              q,
              limit: 50,
              ...(type ? { type } : {}),
              ...(status ? { status } : {}),
              ...(cursor ? { cursor } : {}),
            },
            requestInit(csrfToken),
          ),
        )
        const pageItems = page.items ?? []
        setItems((current) => (cursor ? [...current, ...pageItems] : pageItems))
        setNextCursor(page.next_cursor)
        if (!cursor) {
          setState(pageItems.length > 0 ? 'ready' : 'empty')
        }
      } catch (error) {
        if (!cursor) {
          setItems([])
          setNextCursor(null)
          if (error instanceof ApiError && error.status === 400) setState('invalid')
          else setState('error')
        }
      } finally {
        if (!cursor) setSubmitting(false)
        else setLoadingMore(false)
      }
    },
    [csrfToken],
  )

  useEffect(() => {
    const normalized = initialQuery.trim()
    if (!normalized) return
    setQuery(normalized)
    void runSearch(normalized)
  }, [initialQuery, runSearch])

  async function submit(event: FormEvent<HTMLFormElement>): Promise<void> {
    event.preventDefault()
    const normalized = query.trim()
    if (!normalized || submitting) return
    await runSearch(normalized, typeFilter, statusFilter)
  }

  if (!canSearch) {
    return (
      <Page aria-labelledby="search-title">
        <PageHeader id="search-title" title={t.title} description={t.description} />
        <EmptyState title={t.denied} />
      </Page>
    )
  }

  const allOptions: Array<{ value: string; label: string }> = [
    { value: '', label: t.all },
    ...TYPE_VALUES.map((value) => ({ value, label: TYPE_LABELS[value]?.[locale] ?? value })),
  ]
  const allStatuses: Array<{ value: string; label: string }> = [
    { value: '', label: t.all },
    ...STATUS_VALUES.map((value) => ({ value, label: statusLabel(value, locale) })),
  ]

  return (
    <Page aria-labelledby="search-title">
      <PageHeader id="search-title" title={t.title} description={t.description} />
      <form className="inline-form" onSubmit={(event) => void submit(event)}>
        <Field id="search-query" label={t.query} required>
          <input
            id="search-query"
            required
            maxLength={256}
            value={query}
            onChange={(event) => setQuery(event.currentTarget.value)}
            placeholder={t.queryPlaceholder}
          />
        </Field>
        <Field id="search-type" label={t.type}>
          <Select id="search-type" value={typeFilter} onChange={setTypeFilter} options={allOptions} />
        </Field>
        <Field id="search-status" label={t.status}>
          <Select id="search-status" value={statusFilter} onChange={setStatusFilter} options={allStatuses} />
        </Field>
        <div className="form-actions">
          <Button type="submit" disabled={submitting || query.trim() === ''}>
            {submitting ? t.searching : t.submit}
          </Button>
        </div>
      </form>

      {state === 'loading' ? <SkeletonList rows={5} /> : null}
      {state === 'invalid' ? (
        <p className="status-message status-message--error" role="alert">
          {t.invalidQuery}
        </p>
      ) : null}
      {state === 'error' ? (
        <InlineError
          message={t.failed}
          retryLabel={t.retry}
          onRetry={() => {
            if (lastSearch) void runSearch(lastSearch.query, lastSearch.type, lastSearch.status)
          }}
        />
      ) : null}
      {state === 'empty' ? <EmptyState title={t.noResults} body={t.noResultsBody} /> : null}

      {state === 'ready' && lastSearch ? (
        <Panel id="search-results-panel" title={t.resultsFor} level={2}>
          <ul className="screen-list">
            {items.map((item) => (
              <li key={item.id} className="screen-list__row">
                <div>
                  <span className="screen-list__row-title">{resultTitle(item)}</span>
                  <span className="screen-list__row-meta">
                    {resultSourceType(item)} · {resultSourceId(item) ?? t.notAvailable}
                  </span>
                  {resultExcerpt(item) ? <p>{resultExcerpt(item)}</p> : null}
                </div>
                <StatusBadge>{statusLabel(String(item.status ?? ''), locale)}</StatusBadge>
              </li>
            ))}
          </ul>
          {nextCursor ? (
            <div className="pagination-bar">
              <Button variant="secondary" disabled={loadingMore} onClick={() => void runSearch(lastSearch.query, lastSearch.type, lastSearch.status, nextCursor)}>
                {t.loadMore}
              </Button>
            </div>
          ) : null}
        </Panel>
      ) : null}
    </Page>
  )
}

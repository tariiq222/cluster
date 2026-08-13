import { useCallback, useEffect, useMemo, useRef, useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { FileText, FolderSearch, ListTodo, type LucideIcon } from 'lucide-react'
import * as generated from '../../api/generated/cluster'
import type { Entity } from '../../api/generated/cluster'
import { ApiError, requestInit, unwrap } from '../../api/http'
import { usePrincipal } from '../../app/principal-context'
import { useLocale, useSessionToken } from '../../app/session-context'
import { statusLabel } from '../../i18n'
import { PageHeader, PageLayout } from '@/components/page-layout'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { EmptyState, ResourceBoundary } from '@/components/states'
import { searchCopy } from './search-copy'

const TYPE_VALUES = ['task', 'document'] as const
const STATUS_VALUES = ['draft', 'submitted', 'in_review', 'approved', 'completed'] as const

function iconForType(type: string): LucideIcon {
  switch (type) {
    case 'task':
      return ListTodo
    case 'document':
      return FileText
    default:
      return FolderSearch
  }
}

function resultType(entity: Entity): string {
  return String(entity.resource_type)
}

function resultTitle(entity: Entity): string {
  if ('resource_type' in entity) {
    const title = entity.title ?? entity.name ?? entity.code
    if (title) return String(title)
  }
  return entity.id
}

/** Known result kinds link to their destination route; unknown kinds stay plain text. */
function resultPath(entity: Entity): string | null {
  if (entity.resource_type === 'task') return `/tasks/${entity.id}`
  if (entity.resource_type === 'document') return `/documents/${entity.id}`
  return null
}

function resultExcerpt(entity: Entity): string {
  if ('description' in entity && entity.description) return String(entity.description)
  return ''
}

function typeLabel(type: string, locale: 'ar' | 'en'): string {
  const t = searchCopy[locale]
  switch (type) {
    case 'task':
      return t.task
    case 'document':
      return t.document
    default:
      return type
  }
}

interface SearchResults {
  items: Entity[]
  nextCursor: string | null
}

export function SearchScreen({ initialQuery = '' }: { initialQuery?: string }) {
  const locale = useLocale()
  const csrfToken = useSessionToken()
  const { capabilities, scopeEpoch } = usePrincipal()
  const text = searchCopy[locale]
  const canSearch = capabilities?.includes('search.query') === true

  const [query, setQuery] = useState('')
  const [typeFilter, setTypeFilter] = useState('')
  const [statusFilter, setStatusFilter] = useState('')
  const [results, setResults] = useState<SearchResults | null>(null)
  const [state, setState] = useState<'idle' | 'loading' | 'ready' | 'empty' | 'invalid' | 'error'>('idle')
  const [submitting, setSubmitting] = useState(false)
  const [loadingMore, setLoadingMore] = useState(false)
  const [loadMoreFailed, setLoadMoreFailed] = useState(false)
  const [lastParams, setLastParams] = useState<{ q: string; type: string; status: string } | null>(null)

  /*
   * The epoch only changes on a REAL scope switch (it is stable across mount
   * and refreshes). When it does, local results and pagination state fetched
   * under the old scope must not survive into the new scope.
   */
  const previousEpoch = useRef(scopeEpoch)

  useEffect(() => {
    if (previousEpoch.current === scopeEpoch) return
    previousEpoch.current = scopeEpoch
    setResults(null)
    setState('idle')
    setSubmitting(false)
    setLoadingMore(false)
    setLoadMoreFailed(false)
    setLastParams(null)
  }, [scopeEpoch])

  /*
   * Radix Select reports the "__all" sentinel for the "All" option. Normalize
   * it to an empty string so the sentinel never reaches the API as a filter.
   */
  const onTypeFilterChange = (value: string) => setTypeFilter(value === '__all' ? '' : value)
  const onStatusFilterChange = (value: string) => setStatusFilter(value === '__all' ? '' : value)

  const runSearch = useCallback(
    async (q: string, type = '', status = '', cursor: string | null = null) => {
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
        if (cursor) {
          setResults((current) =>
            current
              ? { items: [...current.items, ...pageItems], nextCursor: page.next_cursor }
              : { items: pageItems, nextCursor: page.next_cursor },
          )
        } else {
          setLastParams({ q, type, status })
          setResults({ items: pageItems, nextCursor: page.next_cursor })
          setState(pageItems.length > 0 ? 'ready' : 'empty')
        }
      } catch (error) {
        if (!cursor) {
          setResults(null)
          if (error instanceof ApiError && error.status === 400) setState('invalid')
          else setState('error')
        } else {
          setLoadMoreFailed(true)
        }
      }
    },
    [csrfToken],
  )

  useEffect(() => {
    const normalized = initialQuery.trim()
    if (!normalized) return
    setQuery(normalized)
    setState('loading')
    void runSearch(normalized)
  }, [initialQuery, runSearch])

  async function submit(event: FormEvent<HTMLFormElement>): Promise<void> {
    event.preventDefault()
    const normalized = query.trim()
    if (!normalized || submitting) return
    setSubmitting(true)
    setState('loading')
    setResults(null)
    setLoadMoreFailed(false)
    try {
      await runSearch(normalized, typeFilter, statusFilter)
    } finally {
      setSubmitting(false)
    }
  }

  const grouped = useMemo(() => {
    if (!results) return []
    const byType = new Map<string, Entity[]>()
    for (const item of results.items) {
      const type = resultType(item)
      const list = byType.get(type) ?? []
      list.push(item)
      byType.set(type, list)
    }
    return Array.from(byType.entries())
  }, [results])

  const derivedState: 'loading' | 'ready' | 'empty' | 'forbidden' | 'not-found' | 'conflict' | 'stale' | 'error' =
    !canSearch
      ? 'forbidden'
      : state === 'ready'
        ? 'ready'
        : state === 'loading'
          ? 'loading'
          : state === 'error'
            ? 'error'
            : 'empty'

  return (
    <PageLayout>
      <PageHeader title={text.title} description={text.description} />

      <form onSubmit={(event) => void submit(event)} className="grid max-w-3xl gap-3 sm:grid-cols-[1fr_auto_auto_auto] sm:items-end">
        <div className="grid gap-1">
          <Label htmlFor="search-query">{text.query}</Label>
          <Input
            id="search-query"
            maxLength={256}
            value={query}
            onChange={(event) => setQuery(event.currentTarget.value)}
            placeholder={text.queryPlaceholder}
            disabled={!canSearch}
          />
        </div>
        <div className="grid gap-1">
          <Label htmlFor="search-type">{text.type}</Label>
          <Select value={typeFilter || '__all'} onValueChange={onTypeFilterChange} disabled={!canSearch}>
            <SelectTrigger id="search-type" className="w-36">
              <SelectValue placeholder={text.all} />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="__all">{text.all}</SelectItem>
              {TYPE_VALUES.map((value) => (
                <SelectItem key={value} value={value}>
                  {typeLabel(value, locale)}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
        <div className="grid gap-1">
          <Label htmlFor="search-status">{text.status}</Label>
          <Select value={statusFilter || '__all'} onValueChange={onStatusFilterChange} disabled={!canSearch}>
            <SelectTrigger id="search-status" className="w-36">
              <SelectValue placeholder={text.all} />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="__all">{text.all}</SelectItem>
              {STATUS_VALUES.map((value) => (
                <SelectItem key={value} value={value}>
                  {statusLabel(value, locale)}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
        <Button type="submit" disabled={submitting || query.trim() === '' || !canSearch}>
          {submitting ? text.searching : text.submit}
        </Button>
      </form>

      <ResourceBoundary
        state={derivedState}
        locale={locale}
        onRetry={() => {
          if (lastParams) {
            setState('loading')
            void runSearch(lastParams.q, lastParams.type, lastParams.status)
          }
        }}
        empty={
          <EmptyState
            icon={<FolderSearch aria-hidden="true" />}
            title={state === 'invalid' ? text.invalidQuery : text.noResults}
            body={state === 'invalid' ? undefined : text.noResultsBody}
          />
        }
      >
        {state === 'ready' && results && (
          <div className="space-y-5">
            {grouped.map(([type, items]) => {
              const Icon = iconForType(type)
              return (
                <section key={type} aria-label={typeLabel(type, locale)}>
                  <h2 className="flex items-center gap-2 text-base font-semibold">
                    <Icon aria-hidden="true" className="size-4 text-muted-foreground" />
                    {typeLabel(type, locale)}
                    <span className="text-muted-foreground text-xs font-normal">
                      {items.length}
                    </span>
                  </h2>
                  <ul className="mt-2 divide-y rounded-lg border">
                    {items.map((item) => {
                      const path = resultPath(item)
                      return (
                        <li key={item.id} className="flex items-start justify-between gap-3 p-3">
                          <div className="min-w-0">
                            {path ? (
                              <Link to={path} className="block truncate font-medium hover:underline">
                                {resultTitle(item)}
                              </Link>
                            ) : (
                              <p className="truncate font-medium">{resultTitle(item)}</p>
                            )}
                            {resultExcerpt(item) ? (
                              <p className="text-muted-foreground mt-0.5 line-clamp-2 text-sm">{resultExcerpt(item)}</p>
                            ) : null}
                          </div>
                          <Badge variant="outline" className="shrink-0">
                            {statusLabel(String(item.status ?? ''), locale)}
                          </Badge>
                        </li>
                      )
                    })}
                  </ul>
                </section>
              )
            })}
            {results.nextCursor && (
              <div className="flex justify-center">
                <Button
                  variant="outline"
                  size="sm"
                  disabled={loadingMore}
                  onClick={() => {
                    if (!lastParams || !results.nextCursor) return
                    setLoadingMore(true)
                    setLoadMoreFailed(false)
                    void runSearch(lastParams.q, lastParams.type, lastParams.status, results.nextCursor).finally(() =>
                      setLoadingMore(false),
                    )
                  }}
                >
                  {loadingMore ? text.loadingMore : text.loadMore}
                </Button>
              </div>
            )}
            {loadMoreFailed && (
              <p className="text-destructive text-sm" role="alert">
                {text.failed}
              </p>
            )}
          </div>
        )}
      </ResourceBoundary>
    </PageLayout>
  )
}

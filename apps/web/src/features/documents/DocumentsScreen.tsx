import { useCallback, useEffect, useMemo, useState } from 'react'
import { Plus, Search } from 'lucide-react'
import type { ColumnDef } from '@tanstack/react-table'
import * as generated from '../../api/generated/cluster'
import { useDocumentsList } from '../../api/hooks'
import { ApiError, requestInit, stateFromError, unwrap } from '../../api/http'
import { useNavigate } from '../../app/navigation-context'
import { useLocale } from '../../app/session-context'
import { formatDate, statusLabel } from '../../i18n'
import { PageHeader, PageLayout } from '@/components/page-layout'
import { DataTable } from '@/components/data-table'
import { EmptyState } from '@/components/states'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { documentsCopy } from './documents-copy'

interface DocumentSummary {
  id: string
  title?: string
  name?: string
  classification?: string
  status?: string
  lifecycle_state?: string
  updated_at?: string
}

type ClassificationFilter = 'all' | generated.Classification

interface PageState {
  items: DocumentSummary[]
  nextCursor: string | null
}

export function DocumentsScreen() {
  const locale = useLocale()
  const navigate = useNavigate()
  const t = documentsCopy[locale]

  const [classification, setClassification] = useState<ClassificationFilter>('all')
  const [search, setSearch] = useState('')
  const [pages, setPages] = useState<PageState[]>([])

  const filters = useMemo<generated.ListDocumentsParams>(() => {
    const params: generated.ListDocumentsParams = { limit: 50 }
    if (classification !== 'all') params.classification = classification
    return params
  }, [classification])

  const query = useDocumentsList(filters)
  const collection = query.data as generated.EntityCollection | undefined
  const forbidden = query.isError && query.error instanceof ApiError && query.error.status === 403

  useEffect(() => {
    setPages([])
  }, [filters])

  const loaded = useMemo<DocumentSummary[]>(() => {
    const base = (collection?.items as unknown as DocumentSummary[]) ?? []
    return [...base, ...pages.flatMap((page) => page.items)]
  }, [collection, pages])

  const nextCursor = pages.at(-1)?.nextCursor ?? collection?.next_cursor ?? null

  const loadNext = useCallback(async () => {
    if (!nextCursor) return
    const params: generated.ListDocumentsParams = { limit: 50, cursor: nextCursor }
    if (classification !== 'all') params.classification = classification
    const collection = unwrap<generated.EntityCollection>(
      await generated.listDocuments(params, requestInit(null)),
    )
    setPages((current) => [...current, { items: collection.items as unknown as DocumentSummary[], nextCursor: collection.next_cursor }])
  }, [classification, nextCursor])

  const items = useMemo(() => {
    const q = search.trim().toLowerCase()
    if (!q) return loaded
    return loaded.filter((document) => {
      const title = document.title ?? document.name ?? ''
      return title.toLowerCase().includes(q)
    })
  }, [loaded, search])

  const screenState = forbidden
    ? 'forbidden'
    : query.isError ? stateFromError(query.error)
    : query.isPending ? 'loading'
    : items.length === 0 ? 'empty'
    : 'ready'

  const columns = useMemo<ColumnDef<DocumentSummary>[]>(
    () => [
      {
        accessorKey: 'title',
        header: t.name,
        cell: ({ row }) => <span className="font-medium">{row.original.title ?? row.original.name ?? row.original.id}</span>,
      },
      {
        accessorKey: 'classification',
        header: t.classificationLabel,
        cell: ({ row }) => <span>{classificationLabel(row.original.classification, t)}</span>,
      },
      {
        accessorKey: 'lifecycle_state',
        header: t.state,
        cell: ({ row }) => (
          <Badge variant="outline">
            {statusLabel(row.original.lifecycle_state ?? row.original.status ?? '', locale)}
          </Badge>
        ),
      },
      {
        accessorKey: 'updated_at',
        header: t.updatedAt,
        cell: ({ row }) => (row.original.updated_at ? formatDate(row.original.updated_at, locale) : '—'),
      },
    ],
    [locale, t],
  )

  return (
    <PageLayout>
      <PageHeader
        title={t.pageTitle}
        description={t.pageDescription}
        actions={
          <Button onClick={() => navigate('/documents/new')}>
            <Plus aria-hidden="true" />
            {t.createTitle}
          </Button>
        }
      />

      <DataTable
        columns={columns}
        data={items}
        state={screenState}
        nextCursor={nextCursor}
        onNext={() => void loadNext()}
        onPrev={() => setPages((current) => current.slice(0, -1))}
        canPrev={pages.length > 0}
        locale={locale}
        onRowClick={(row) => navigate(`/documents/${row.id}`)}
        empty={
          <EmptyState
            icon={<Search aria-hidden="true" />}
            title={t.emptyTitle}
            body={t.emptyBody}
            action={
              <Button variant="outline" onClick={() => navigate('/documents/new')}>
                {t.createTitle}
              </Button>
            }
          />
        }
        toolbar={
          <div className="flex flex-wrap items-end gap-2 pb-2">
            <div className="grid gap-1">
              <Label htmlFor="documents-search">{t.searchLabel}</Label>
              <Input
                id="documents-search"
                value={search}
                onChange={(event) => setSearch(event.target.value)}
                placeholder={t.searchPlaceholder}
                className="w-56"
              />
            </div>
            <div className="grid gap-1">
              <Label htmlFor="documents-classification-filter">{t.classificationLabel}</Label>
              <Select value={classification} onValueChange={(value) => setClassification(value as ClassificationFilter)}>
                <SelectTrigger id="documents-classification-filter" className="w-44">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">{t.classificationAll}</SelectItem>
                  <SelectItem value="public">{t.classificationPublic}</SelectItem>
                  <SelectItem value="internal">{t.classificationInternal}</SelectItem>
                  <SelectItem value="confidential">{t.classificationConfidential}</SelectItem>
                  <SelectItem value="top_secret">{t.classificationTopSecret}</SelectItem>
                </SelectContent>
              </Select>
            </div>
          </div>
        }
      />
    </PageLayout>
  )
}

function classificationLabel(classification: string | undefined, t: (typeof documentsCopy)[keyof typeof documentsCopy]): string {
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
      return classification ?? '—'
  }
}

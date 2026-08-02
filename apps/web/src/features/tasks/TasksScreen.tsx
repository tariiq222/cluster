import { useCallback, useEffect, useMemo, useState } from 'react'
import { Plus, Search } from 'lucide-react'
import type { ColumnDef } from '@tanstack/react-table'
import * as generated from '../../api/generated/cluster'
import { useTasksList } from '../../api/hooks'
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

interface TaskSummary {
  id: string
  title: string
  description?: string
  state: string
  priority: string
  assignee_user_id?: string
  due_at?: string
}

type RelationshipFilter = 'all' | generated.ListTasksRelationship
type StateFilter = 'all' | generated.ListTasksState

const copy = {
  ar: {
    pageTitle: 'المهام',
    pageDescription: 'المهام المسندة والمنشأة والمشترك فيها ضمن التجمع الصحي',
    createTask: 'أنشئ مهمة',
    relationshipLabel: 'علاقة المهمة',
    relationshipAll: 'الكل',
    relationshipAssigned: 'مسندة إليّ',
    relationshipCreated: 'أنشأتها',
    relationshipParticipating: 'أشارك فيها',
    stateLabel: 'الحالة',
    stateAll: 'كل الحالات',
    searchLabel: 'بحث',
    searchPlaceholder: 'ابحث في العنوان…',
    priorityLabel: 'الأولوية',
    priorityAll: 'كل الأولويات',
    emptyTitle: 'لا توجد مهام',
    emptyBody: 'ابدأ بإنشاء مهمة جديدة أو غيّر عوامل التصفية.',
    loading: 'جارٍ تحميل المهام…',
    error: 'تعذر تحميل المهام. يرجى إعادة المحاولة.',
    retry: 'إعادة المحاولة',
    title: 'العنوان',
    priorityLow: 'منخفضة',
    priorityNormal: 'عادية',
    priorityHigh: 'عالية',
    priorityUrgent: 'عاجلة',
    assignee: 'المسند إليه',
    dueAt: 'الاستحقاق',
  },
  en: {
    pageTitle: 'Tasks',
    pageDescription: 'Tasks assigned to, created by, or involving you within the health cluster',
    createTask: 'Create task',
    relationshipLabel: 'Task relationship',
    relationshipAll: 'All',
    relationshipAssigned: 'Assigned to me',
    relationshipCreated: 'Created by me',
    relationshipParticipating: 'I participate in',
    stateLabel: 'State',
    stateAll: 'All states',
    searchLabel: 'Search',
    searchPlaceholder: 'Search by title…',
    priorityLabel: 'Priority',
    priorityAll: 'All priorities',
    emptyTitle: 'No tasks',
    emptyBody: 'Create a new task or adjust the filters.',
    loading: 'Loading tasks…',
    error: 'Could not load tasks. Please try again.',
    retry: 'Retry',
    title: 'Title',
    priorityLow: 'Low',
    priorityNormal: 'Normal',
    priorityHigh: 'High',
    priorityUrgent: 'Urgent',
    assignee: 'Assignee',
    dueAt: 'Due at',
  },
} as const

interface PageState {
  items: TaskSummary[]
  nextCursor: string | null
}

export function TasksScreen() {
  const locale = useLocale()
  const navigate = useNavigate()
  const t = copy[locale]

  const [relationship, setRelationship] = useState<RelationshipFilter>('all')
  const [state, setState] = useState<StateFilter>('all')
  const [priority, setPriority] = useState<'all' | string>('all')
  const [search, setSearch] = useState('')
  const [pages, setPages] = useState<PageState[]>([])

  const filters = useMemo<generated.ListTasksParams>(() => {
    const params: generated.ListTasksParams = { limit: 50 }
    if (relationship !== 'all') params.relationship = relationship
    if (state !== 'all') params.state = state
    return params
  }, [relationship, state])

  const query = useTasksList(filters)
  const collection = query.data as generated.EntityCollection | undefined
  const forbidden = query.isError && query.error instanceof ApiError && query.error.status === 403

  useEffect(() => {
    setPages([])
  }, [filters])

  const loaded = useMemo<TaskSummary[]>(() => {
    const base = (collection?.items as unknown as TaskSummary[]) ?? []
    return [...base, ...pages.flatMap((page) => page.items)]
  }, [collection, pages])

  const nextCursor = pages.at(-1)?.nextCursor ?? collection?.next_cursor ?? null

  const loadNext = useCallback(async () => {
    if (!nextCursor) return
    const params: generated.ListTasksParams = { limit: 50, cursor: nextCursor }
    if (relationship !== 'all') params.relationship = relationship
    if (state !== 'all') params.state = state
    const collection = unwrap<generated.EntityCollection>(
      await generated.listTasks(params, requestInit(null)),
    )
    setPages((current) => [...current, { items: collection.items as unknown as TaskSummary[], nextCursor: collection.next_cursor }])
  }, [nextCursor, relationship, state])

  const items = useMemo(() => {
    const query = search.trim().toLowerCase()
    return loaded.filter((task) => {
      if (priority !== 'all' && task.priority !== priority) return false
      if (query && !task.title.toLowerCase().includes(query)) return false
      return true
    })
  }, [loaded, priority, search])

  const screenState = forbidden
    ? 'forbidden'
    : query.isError ? stateFromError(query.error)
    : query.isPending ? 'loading'
    : items.length === 0 ? 'empty'
    : 'ready'

  const columns = useMemo<ColumnDef<TaskSummary>[]>(
    () => [
      {
        accessorKey: 'title',
        header: t.title,
        cell: ({ row }) => <span className="font-medium">{row.original.title}</span>,
      },
      {
        accessorKey: 'state',
        header: t.stateLabel,
        cell: ({ row }) => <Badge variant="outline">{statusLabel(row.original.state, locale)}</Badge>,
      },
      {
        accessorKey: 'priority',
        header: t.priorityLabel,
        cell: ({ row }) => priorityLabel(row.original.priority, t),
      },
      {
        accessorKey: 'assignee_user_id',
        header: t.assignee,
        cell: ({ row }) => row.original.assignee_user_id ?? '—',
      },
      {
        accessorKey: 'due_at',
        header: t.dueAt,
        cell: ({ row }) => (row.original.due_at ? formatDate(row.original.due_at, locale) : '—'),
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
          <Button onClick={() => navigate('/tasks/new')}>
            <Plus aria-hidden="true" />
            {t.createTask}
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
        onRowClick={(row) => navigate(`/tasks/${row.id}`)}
        empty={
          <EmptyState
            icon={<Search aria-hidden="true" />}
            title={t.emptyTitle}
            body={t.emptyBody}
            action={
              <Button variant="outline" onClick={() => navigate('/tasks/new')}>
                {t.createTask}
              </Button>
            }
          />
        }
        toolbar={
          <div className="flex flex-wrap items-end gap-2 pb-2">
            <div className="grid gap-1">
              <Label htmlFor="tasks-search">{t.searchLabel}</Label>
              <Input
                id="tasks-search"
                value={search}
                onChange={(event) => setSearch(event.target.value)}
                placeholder={t.searchPlaceholder}
                className="w-56"
              />
            </div>
            <div className="grid gap-1">
              <Label htmlFor="tasks-state-filter">{t.stateLabel}</Label>
              <Select value={state} onValueChange={(value) => setState(value as StateFilter)}>
                <SelectTrigger id="tasks-state-filter" className="w-40">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">{t.stateAll}</SelectItem>
                  <SelectItem value="open">{statusLabel('open', locale)}</SelectItem>
                  <SelectItem value="in_progress">{statusLabel('in_progress', locale)}</SelectItem>
                  <SelectItem value="blocked">{statusLabel('blocked', locale)}</SelectItem>
                  <SelectItem value="completed">{statusLabel('completed', locale)}</SelectItem>
                  <SelectItem value="cancelled">{statusLabel('cancelled', locale)}</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div className="grid gap-1">
              <Label htmlFor="tasks-priority-filter">{t.priorityLabel}</Label>
              <Select value={priority} onValueChange={setPriority}>
                <SelectTrigger id="tasks-priority-filter" className="w-40">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">{t.priorityAll}</SelectItem>
                  <SelectItem value="low">{t.priorityLow}</SelectItem>
                  <SelectItem value="normal">{t.priorityNormal}</SelectItem>
                  <SelectItem value="high">{t.priorityHigh}</SelectItem>
                  <SelectItem value="urgent">{t.priorityUrgent}</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div className="grid gap-1">
              <Label htmlFor="tasks-relationship-filter">{t.relationshipLabel}</Label>
              <Select value={relationship} onValueChange={(value) => setRelationship(value as RelationshipFilter)}>
                <SelectTrigger id="tasks-relationship-filter" className="w-40">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">{t.relationshipAll}</SelectItem>
                  <SelectItem value="assigned">{t.relationshipAssigned}</SelectItem>
                  <SelectItem value="created">{t.relationshipCreated}</SelectItem>
                  <SelectItem value="participating">{t.relationshipParticipating}</SelectItem>
                </SelectContent>
              </Select>
            </div>
          </div>
        }
      />
    </PageLayout>
  )
}

function priorityLabel(priority: string, t: (typeof copy)[keyof typeof copy]): string {
  switch (priority) {
    case 'low':
      return t.priorityLow
    case 'high':
      return t.priorityHigh
    case 'urgent':
      return t.priorityUrgent
    default:
      return t.priorityNormal
  }
}

import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Plus, Search } from 'lucide-react'
import type { ColumnDef } from '@tanstack/react-table'
import * as generated from '../../api/generated/cluster'
import { useTasksList } from '../../api/hooks'
import { ApiError, requestInit, stateFromError, unwrap } from '../../api/http'
import { useNavigate } from '../../app/navigation-context'
import { usePrincipal } from '../../app/principal-context'
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

type TaskSummary = generated.Task

type RelationshipFilter = 'all' | generated.ListTasksRelationship
type StateFilter = 'all' | generated.ListTasksState
type TasksView = generated.ListTasksView

interface TaskScopeOption {
  scopeType: generated.ListTasksScopeType
  scopeId: string
  label: string
}

const copy = {
  ar: {
    pageTitle: 'المهام',
    pageDescription: 'المهام المسندة والمنشأة والمشترك فيها ضمن التجمع الصحي',
    createTask: 'أنشئ مهمة',
    viewLabel: 'عرض المهام',
    myTasks: 'مهامي',
    scopeTasks: 'مهام نطاقي',
    scopeLabel: 'نطاق المهام',
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
    emptyReadOnlyBody: 'لا توجد مهام متاحة وفق عوامل التصفية الحالية.',
    emptyTitleWithNext: 'لا توجد مهام ظاهرة في هذه الصفحة',
    emptyBodyWithNext: 'انتقل إلى الصفحة التالية للبحث عن مهام متاحة، أو غيّر عوامل التصفية.',
    loading: 'جارٍ تحميل المهام…',
    error: 'تعذر تحميل المهام. يرجى إعادة المحاولة.',
    retry: 'إعادة المحاولة',
    title: 'العنوان',
    priorityLow: 'منخفضة',
    priorityNormal: 'عادية',
    priorityHigh: 'عالية',
    priorityUrgent: 'عاجلة',
    assignee: 'المسند إليه',
    creator: 'المنشئ',
    ownerScope: 'النطاق المالك',
    dueAt: 'الاستحقاق',
  },
  en: {
    pageTitle: 'Tasks',
    pageDescription: 'Tasks assigned to, created by, or involving you within the health cluster',
    createTask: 'Create task',
    viewLabel: 'Task view',
    myTasks: 'My tasks',
    scopeTasks: 'Scope tasks',
    scopeLabel: 'Task scope',
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
    emptyReadOnlyBody: 'No tasks are available for the current filters.',
    emptyTitleWithNext: 'No tasks are visible on this page',
    emptyBodyWithNext: 'Continue to the next page to look for accessible tasks, or adjust the filters.',
    loading: 'Loading tasks…',
    error: 'Could not load tasks. Please try again.',
    retry: 'Retry',
    title: 'Title',
    priorityLow: 'Low',
    priorityNormal: 'Normal',
    priorityHigh: 'High',
    priorityUrgent: 'Urgent',
    assignee: 'Assignee',
    creator: 'Creator',
    ownerScope: 'Owner scope',
    dueAt: 'Due at',
  },
} as const

interface PageState {
  items: generated.Task[]
  nextCursor: generated.UUIDv7 | null
}

export function TasksScreen() {
  const locale = useLocale()
  const navigate = useNavigate()
  const principal = usePrincipal()
  const t = copy[locale]
  const canCreate = principal.capabilities?.includes('tasks.create') === true

  const [view, setView] = useState<TasksView>('mine')
  const [availableScopes, setAvailableScopes] = useState<TaskScopeOption[]>([])
  const [selectedScopeKey, setSelectedScopeKey] = useState<string | null>(null)
  const [relationship, setRelationship] = useState<RelationshipFilter>('all')
  const [state, setState] = useState<StateFilter>('all')
  const [priority, setPriority] = useState<'all' | string>('all')
  const [search, setSearch] = useState('')
  const [pages, setPages] = useState<PageState[]>([])
  const clearPages = useCallback(() => setPages([]), [])

  const effectiveScopeKey = principal.effectiveScope && isNamedTaskScope(principal.effectiveScope)
    ? taskScopeKey(principal.effectiveScope)
    : null
  const selectedScope = useMemo(
    () =>
      availableScopes.find((scope) => taskScopeKey(scope) === selectedScopeKey) ??
      availableScopes.find((scope) => taskScopeKey(scope) === effectiveScopeKey) ??
      availableScopes[0],
    [availableScopes, effectiveScopeKey, selectedScopeKey],
  )
  const scopeView = view === 'scope' && selectedScope !== undefined

  useEffect(() => {
    if (view === 'scope' && !selectedScope) setView('mine')
  }, [selectedScope, view])

  const filters = useMemo<generated.ListTasksParams>(() => {
    const params: generated.ListTasksParams = { limit: 50, view: scopeView ? 'scope' : 'mine' }
    if (scopeView && selectedScope) {
      params.scope_type = selectedScope.scopeType
      params.scope_id = selectedScope.scopeId
    }
    if (!scopeView && relationship !== 'all') params.relationship = relationship
    if (state !== 'all') params.state = state
    return params
  }, [relationship, scopeView, selectedScope, state])
  const filtersKey = useMemo(() => taskFiltersKey(filters), [filters])
  const filtersKeyRef = useRef(filtersKey)
  filtersKeyRef.current = filtersKey

  const changeView = useCallback((nextView: TasksView) => {
    clearPages()
    setView(nextView)
  }, [clearPages])

  const changeScope = useCallback((nextScopeKey: string) => {
    clearPages()
    setSelectedScopeKey(nextScopeKey)
  }, [clearPages])

  const query = useTasksList(filters)
  const collection = query.data
  const forbidden = query.isError && query.error instanceof ApiError && query.error.status === 403

  useEffect(() => {
    if (!collection?.available_scopes) return
    setAvailableScopes(collection.available_scopes.map((scope) => ({
      scopeType: scope.scope_type,
      scopeId: scope.scope_id,
      label: scope.label,
    })))
  }, [collection?.available_scopes])

  useEffect(() => {
    clearPages()
  }, [clearPages, filters])

  const loaded = useMemo<TaskSummary[]>(() => {
    const base = collection?.items ?? []
    return [...base, ...pages.flatMap((page) => page.items)]
  }, [collection, pages])

  const nextCursor = pages.at(-1)?.nextCursor ?? collection?.next_cursor ?? null

  const loadNext = useCallback(async () => {
    if (!nextCursor) return
    const requestFiltersKey = filtersKey
    const collection = unwrap<generated.TaskCollection>(
      await generated.listTasks({ ...filters, cursor: nextCursor }, requestInit(null)),
    )
    if (filtersKeyRef.current !== requestFiltersKey) return
    setPages((current) => [...current, { items: collection.items, nextCursor: collection.next_cursor }])
  }, [filters, filtersKey, nextCursor])

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
        accessorKey: 'assignee',
        header: t.assignee,
        cell: ({ row }) => taskAssigneeLabel(row.original),
      },
      {
        accessorKey: 'creator',
        header: t.creator,
        cell: ({ row }) => taskCreatorLabel(row.original),
      },
      {
        accessorKey: 'owner_scope',
        header: t.ownerScope,
        cell: ({ row }) => row.original.owner_scope?.label ?? '—',
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
        actions={canCreate ? (
          <Button onClick={() => navigate('/tasks/new')}>
            <Plus aria-hidden="true" />
            {t.createTask}
          </Button>
        ) : undefined}
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
            title={nextCursor ? t.emptyTitleWithNext : t.emptyTitle}
            body={nextCursor ? t.emptyBodyWithNext : canCreate ? t.emptyBody : t.emptyReadOnlyBody}
            action={canCreate ? (
              <Button variant="outline" onClick={() => navigate('/tasks/new')}>
                {t.createTask}
              </Button>
            ) : undefined}
          />
        }
        toolbar={
          <div className="flex flex-wrap items-end gap-2 pb-2">
            {availableScopes.length > 0 && (
              <>
                <div className="grid gap-1">
                  <Label>{t.viewLabel}</Label>
                  <div className="flex rounded-lg border p-1" role="group" aria-label={t.viewLabel}>
                    <Button
                      type="button"
                      variant={view === 'mine' ? 'secondary' : 'ghost'}
                      size="sm"
                      aria-pressed={view === 'mine'}
                      onClick={() => changeView('mine')}
                    >
                      {t.myTasks}
                    </Button>
                    <Button
                      type="button"
                      variant={scopeView ? 'secondary' : 'ghost'}
                      size="sm"
                      aria-pressed={scopeView}
                      onClick={() => changeView('scope')}
                    >
                      {t.scopeTasks}
                    </Button>
                  </div>
                </div>
                {scopeView && selectedScope && (
                  <div className="grid gap-1">
                    <Label htmlFor="tasks-scope-filter">{t.scopeLabel}</Label>
                    <Select
                      value={taskScopeKey(selectedScope)}
                      onValueChange={changeScope}
                    >
                      <SelectTrigger id="tasks-scope-filter" className="w-48">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        {availableScopes.map((scope) => (
                          <SelectItem key={taskScopeKey(scope)} value={taskScopeKey(scope)}>
                            {scope.label}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>
                )}
              </>
            )}
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
            {!scopeView && (
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
            )}
          </div>
        }
      />
    </PageLayout>
  )
}

function isNamedTaskScope(scope: { scopeType: string; scopeId: string; label: string }): scope is TaskScopeOption {
  return (
    (scope.scopeType === 'cluster' || scope.scopeType === 'facility' || scope.scopeType === 'unit') &&
    scope.label.trim().length > 0 &&
    scope.label !== scope.scopeId
  )
}

function taskScopeKey(scope: Pick<TaskScopeOption, 'scopeType' | 'scopeId'>): string {
  return `${scope.scopeType}:${scope.scopeId}`
}

function taskFiltersKey(filters: generated.ListTasksParams): string {
  return JSON.stringify(Object.entries(filters).sort(([left], [right]) => left.localeCompare(right)))
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

function taskAssigneeLabel(task: TaskSummary): string {
  return task.assignee?.display_name ?? task.assignee_user_id ?? '—'
}

function taskCreatorLabel(task: TaskSummary): string {
  return task.creator?.display_name ?? task.creator_user_id ?? '—'
}

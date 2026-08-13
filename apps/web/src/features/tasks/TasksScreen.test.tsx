// @vitest-environment jsdom
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { act, fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { SessionProvider } from '../../app/session-context'
import { TasksScreen } from './TasksScreen'

const taskList = vi.hoisted(() => ({
  items: [] as object[],
  nextCursor: null as string | null,
  availableScopes: [] as Array<{ scope_type: 'cluster' | 'facility' | 'unit'; scope_id: string; label: string }>,
  hook: vi.fn(),
}))

const generatedMocks = vi.hoisted(() => ({ listTasks: vi.fn() }))

const principal = vi.hoisted(() => ({
  current: {
    state: 'ready',
    capabilities: [] as string[],
    features: { tasks: true },
    effectiveScope: null as { scopeType: string; scopeId: string; label: string } | null,
    availableScopes: [] as Array<{ scopeType: string; scopeId: string; label: string }>,
    revision: 0,
    scopeEpoch: 0,
    scopeReady: true,
    errorCorrelationId: null,
    refresh: () => {},
    selectScope: async () => {},
  },
}))

vi.mock('../../api/hooks', () => ({
  useTasksList: (filters: unknown) => {
    taskList.hook(filters)
    return {
    data: {
      items: taskList.items,
      next_cursor: taskList.nextCursor,
      available_scopes: taskList.availableScopes,
    },
    isError: false,
    error: null,
    isPending: false,
    }
  },
}))

vi.mock('../../api/generated/cluster', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../../api/generated/cluster')>()),
  listTasks: (...args: unknown[]) => generatedMocks.listTasks(...args),
}))

vi.mock('../../app/principal-context', () => ({
  usePrincipal: () => principal.current,
}))

const session = {
  csrfToken: 'csrf',
  userId: '01980f50-5f0d-7000-8000-000000000001',
  expiresAt: '2027-01-01T00:00:00Z',
  restricted: false,
}

const labelledTask = {
  id: '01980f50-5f0d-7000-8000-000000000011',
  title: 'مراجعة مؤشرات الأداء',
  state: 'open',
  classification: 'internal',
  priority: 'normal',
  assignee_user_id: '01980f50-5f0d-7000-8000-000000000012',
  creator_user_id: '01980f50-5f0d-7000-8000-000000000013',
  assignee: {
    user_id: '01980f50-5f0d-7000-8000-000000000012',
    display_name: 'د. نورة العتيبي',
  },
  creator: {
    user_id: '01980f50-5f0d-7000-8000-000000000013',
    display_name: 'م. فهد الغامدي',
  },
  owner_scope: {
    scope_type: 'facility',
    scope_id: '01980f50-5f0d-7000-8000-000000000014',
    label: 'مستشفى التجمع',
    code: 'HOSPITAL-01',
  },
  lock_version: 1,
  created_at: '2026-08-13T08:00:00Z',
  updated_at: '2026-08-13T08:00:00Z',
}

const fallbackTask = {
  ...labelledTask,
  assignee: undefined,
  creator: undefined,
  owner_scope: {
    scope_type: 'facility',
    scope_id: '01980f50-5f0d-7000-8000-000000000014',
    label: '01980f50-5f0d-7000-8000-000000000014',
  },
}

const FACILITY_SCOPE_ID = '01980f50-5f0d-7000-8000-000000000021'
const UNIT_SCOPE_ID = '01980f50-5f0d-7000-8000-000000000022'
const FIRST_CURSOR = '01980f50-5f0d-7000-8000-000000000031'
const SECOND_CURSOR = '01980f50-5f0d-7000-8000-000000000032'

function deferred<T>() {
  let resolve!: (value: T) => void
  const promise = new Promise<T>((finish) => {
    resolve = finish
  })
  return { promise, resolve }
}

function taskCollectionResponse(items: object[], nextCursor: string | null) {
  return {
    status: 200,
    data: { data: { items, next_cursor: nextCursor } },
    headers: new Headers(),
  }
}

function resetPrincipal() {
  principal.current = {
    state: 'ready',
    capabilities: [],
    features: { tasks: true },
    effectiveScope: null,
    availableScopes: [],
    revision: 0,
    scopeEpoch: 0,
    scopeReady: true,
    errorCorrelationId: null,
    refresh: () => {},
    selectScope: async () => {},
  }
}

function mount() {
  return render(
    <MemoryRouter initialEntries={['/tasks']}>
      <SessionProvider session={session} locale="ar" setLocale={() => {}}>
        <TasksScreen />
      </SessionProvider>
    </MemoryRouter>,
  )
}

describe('TasksScreen', () => {
  beforeEach(() => {
    taskList.items = [labelledTask]
    taskList.nextCursor = null
    taskList.availableScopes = []
    taskList.hook.mockClear()
    generatedMocks.listTasks.mockReset()
    resetPrincipal()
  })

  it('renders assignee, creator, and owner labels as primary text instead of their UUIDs', () => {
    mount()

    expect(screen.getByText('د. نورة العتيبي')).toBeInTheDocument()
    expect(screen.getByText('م. فهد الغامدي')).toBeInTheDocument()
    expect(screen.getByText('مستشفى التجمع')).toBeInTheDocument()
    expect(screen.queryByText('01980f50-5f0d-7000-8000-000000000012')).not.toBeInTheDocument()
    expect(screen.queryByText('01980f50-5f0d-7000-8000-000000000013')).not.toBeInTheDocument()
    expect(screen.queryByText('01980f50-5f0d-7000-8000-000000000014')).not.toBeInTheDocument()
  })

  it('falls back to raw user and owner identifiers when nested labels are unavailable', () => {
    taskList.items = [fallbackTask]
    mount()

    expect(screen.getByText('01980f50-5f0d-7000-8000-000000000012')).toBeInTheDocument()
    expect(screen.getByText('01980f50-5f0d-7000-8000-000000000013')).toBeInTheDocument()
    expect(screen.getByText('01980f50-5f0d-7000-8000-000000000014')).toBeInTheDocument()
  })

  it('continues past an authorization-empty page when the collection supplies a next cursor', async () => {
    const laterAuthorizedTask = {
      ...labelledTask,
      id: '01980f50-5f0d-7000-8000-000000000099',
      title: 'مهمة مصرح بها في الصفحة التالية',
    }
    taskList.items = []
    taskList.nextCursor = FIRST_CURSOR
    generatedMocks.listTasks.mockResolvedValueOnce(taskCollectionResponse([laterAuthorizedTask], null))

    mount()

    expect(screen.getByText('لا توجد مهام ظاهرة في هذه الصفحة')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'التالي' }))

    expect(await screen.findByText('مهمة مصرح بها في الصفحة التالية')).toBeInTheDocument()
  })

  it('keeps a regular user on the personal list when no named supported scope is available', () => {
    principal.current.availableScopes = [
      { scopeType: 'record_set', scopeId: 'record-set-1', label: 'مجموعة سجلات' },
      { scopeType: 'facility', scopeId: FACILITY_SCOPE_ID, label: FACILITY_SCOPE_ID },
    ]

    mount()

    expect(screen.queryByRole('button', { name: 'مهام نطاقي' })).not.toBeInTheDocument()
    expect(taskList.hook).toHaveBeenLastCalledWith({ limit: 50, view: 'mine' })
  })

  it('requests the selected named scope without relationship filters and exposes labels instead of UUIDs', () => {
    principal.current = {
      ...principal.current,
      effectiveScope: { scopeType: 'facility', scopeId: FACILITY_SCOPE_ID, label: 'مستشفى التجمع' },
      availableScopes: [
        { scopeType: 'facility', scopeId: FACILITY_SCOPE_ID, label: 'مستشفى التجمع' },
        { scopeType: 'unit', scopeId: UNIT_SCOPE_ID, label: 'وحدة الجودة' },
        { scopeType: 'record_set', scopeId: 'record-set-1', label: 'مجموعة سجلات' },
      ],
    }
    taskList.availableScopes = [
      { scope_type: 'facility', scope_id: FACILITY_SCOPE_ID, label: 'مستشفى التجمع' },
      { scope_type: 'unit', scope_id: UNIT_SCOPE_ID, label: 'وحدة الجودة' },
    ]

    mount()
    fireEvent.click(screen.getByRole('button', { name: 'مهام نطاقي' }))

    expect(taskList.hook).toHaveBeenLastCalledWith({
      limit: 50,
      view: 'scope',
      scope_type: 'facility',
      scope_id: FACILITY_SCOPE_ID,
    })
    expect(screen.getByRole('combobox', { name: 'نطاق المهام' })).toHaveTextContent('مستشفى التجمع')
    expect(screen.queryByText(FACILITY_SCOPE_ID)).not.toBeInTheDocument()

    fireEvent.click(screen.getByRole('combobox', { name: 'نطاق المهام' }))
    expect(screen.getByRole('option', { name: 'وحدة الجودة' })).toBeInTheDocument()
    expect(screen.queryByRole('option', { name: FACILITY_SCOPE_ID })).not.toBeInTheDocument()
  })

  it('offers only task-authorized scopes instead of broader organization ancestry', () => {
    principal.current = {
      ...principal.current,
      effectiveScope: { scopeType: 'unit', scopeId: UNIT_SCOPE_ID, label: 'وحدة الجودة' },
      availableScopes: [
        { scopeType: 'cluster', scopeId: '01980f50-5f0d-7000-8000-000000000020', label: 'التجمع الصحي' },
        { scopeType: 'facility', scopeId: FACILITY_SCOPE_ID, label: 'مستشفى التجمع' },
        { scopeType: 'unit', scopeId: UNIT_SCOPE_ID, label: 'وحدة الجودة' },
      ],
    }
    taskList.availableScopes = [
      { scope_type: 'unit', scope_id: UNIT_SCOPE_ID, label: 'وحدة الجودة' },
    ]

    mount()
    fireEvent.click(screen.getByRole('button', { name: 'مهام نطاقي' }))
    fireEvent.click(screen.getByRole('combobox', { name: 'نطاق المهام' }))

    expect(screen.getByRole('option', { name: 'وحدة الجودة' })).toBeInTheDocument()
    expect(screen.queryByRole('option', { name: 'التجمع الصحي' })).not.toBeInTheDocument()
    expect(screen.queryByRole('option', { name: 'مستشفى التجمع' })).not.toBeInTheDocument()
  })

  it('does not offer task creation to a read-only user', () => {
    principal.current = {
      ...principal.current,
      capabilities: ['tasks.list', 'tasks.read'],
    }
    taskList.items = []

    mount()

    expect(screen.queryByRole('button', { name: 'أنشئ مهمة' })).not.toBeInTheDocument()
  })

  it('restores the personal relationship filter after returning from the scope view', () => {
    principal.current = {
      ...principal.current,
      effectiveScope: { scopeType: 'facility', scopeId: FACILITY_SCOPE_ID, label: 'مستشفى التجمع' },
      availableScopes: [{ scopeType: 'facility', scopeId: FACILITY_SCOPE_ID, label: 'مستشفى التجمع' }],
    }
    taskList.availableScopes = [
      { scope_type: 'facility', scope_id: FACILITY_SCOPE_ID, label: 'مستشفى التجمع' },
    ]

    mount()
    fireEvent.click(screen.getByRole('combobox', { name: 'علاقة المهمة' }))
    fireEvent.click(screen.getByRole('option', { name: 'مسندة إليّ' }))
    fireEvent.click(screen.getByRole('button', { name: 'مهام نطاقي' }))

    expect(taskList.hook).toHaveBeenLastCalledWith({
      limit: 50,
      view: 'scope',
      scope_type: 'facility',
      scope_id: FACILITY_SCOPE_ID,
    })

    fireEvent.click(screen.getByRole('button', { name: 'مهامي' }))

    expect(taskList.hook).toHaveBeenLastCalledWith({
      limit: 50,
      view: 'mine',
      relationship: 'assigned',
    })
  })

  it('clears loaded scope pages and ignores a stale next-page response after selecting another scope', async () => {
    const scopeAPage = { ...labelledTask, id: 'task-scope-a', title: 'صفحة النطاق أ' }
    const staleScopeAPage = { ...labelledTask, id: 'task-scope-a-stale', title: 'صفحة قديمة من النطاق أ' }
    principal.current = {
      ...principal.current,
      effectiveScope: { scopeType: 'facility', scopeId: FACILITY_SCOPE_ID, label: 'مستشفى التجمع' },
      availableScopes: [
        { scopeType: 'facility', scopeId: FACILITY_SCOPE_ID, label: 'مستشفى التجمع' },
        { scopeType: 'unit', scopeId: UNIT_SCOPE_ID, label: 'وحدة الجودة' },
      ],
    }
    taskList.availableScopes = [
      { scope_type: 'facility', scope_id: FACILITY_SCOPE_ID, label: 'مستشفى التجمع' },
      { scope_type: 'unit', scope_id: UNIT_SCOPE_ID, label: 'وحدة الجودة' },
    ]
    taskList.nextCursor = FIRST_CURSOR

    mount()
    fireEvent.click(screen.getByRole('button', { name: 'مهام نطاقي' }))

    generatedMocks.listTasks.mockResolvedValueOnce(taskCollectionResponse([scopeAPage], SECOND_CURSOR))
    fireEvent.click(screen.getByRole('button', { name: 'التالي' }))
    expect(await screen.findByText('صفحة النطاق أ')).toBeInTheDocument()

    const staleRequest = deferred<ReturnType<typeof taskCollectionResponse>>()
    generatedMocks.listTasks.mockReturnValueOnce(staleRequest.promise)
    fireEvent.click(screen.getByRole('button', { name: 'التالي' }))

    expect(generatedMocks.listTasks).toHaveBeenLastCalledWith(
      expect.objectContaining({
        view: 'scope',
        scope_type: 'facility',
        scope_id: FACILITY_SCOPE_ID,
        cursor: SECOND_CURSOR,
      }),
      expect.anything(),
    )

    fireEvent.click(screen.getByRole('combobox', { name: 'نطاق المهام' }))
    fireEvent.click(screen.getByRole('option', { name: 'وحدة الجودة' }))

    expect(screen.queryByText('صفحة النطاق أ')).not.toBeInTheDocument()

    await act(async () => {
      staleRequest.resolve(taskCollectionResponse([staleScopeAPage], null))
      await staleRequest.promise
    })

    expect(screen.queryByText('صفحة النطاق أ')).not.toBeInTheDocument()
    expect(screen.queryByText('صفحة قديمة من النطاق أ')).not.toBeInTheDocument()
  })
})

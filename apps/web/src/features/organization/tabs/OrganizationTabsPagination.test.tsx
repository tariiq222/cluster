// @vitest-environment jsdom
import type { ReactNode } from 'react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { act } from '@testing-library/react'
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { FacilitiesTab } from './FacilitiesTab'
import { PositionsTab } from './PositionsTab'
import { JobTitlesTab } from './JobTitlesTab'
import { PeopleTab } from './PeopleTab'
import { AssignmentsTab } from './AssignmentsTab'
import { TemporaryAssignmentsTab } from './TemporaryAssignmentsTab'
import { SupervisoryTab } from './SupervisoryTab'
import { SessionProvider } from '../../../app/session-context'

/*
 * Organization-tab pagination contract — a single focused test
 * that exercises all seven list tabs through the same harness.
 *
 * The pagination contract is:
 *
 *  1. The page starts on the first API page; the Prev button is
 *     disabled; the Next button is enabled iff the current page
 *     has a non-null `next_cursor`.
 *  2. Clicking Next captures the current `next_cursor` and
 *     re-requests with that cursor; the hook's recorded call
 *     history proves the cursor was forwarded.
 *  3. The page keeps a local non-cumulative history: clicking
 *     Prev returns to the previous cursor and the previous
 *     button becomes enabled. Two Next clicks followed by two
 *     Prev clicks must end exactly where we started, with the
 *     same hook call sequence.
 *  4. A scope-epoch change remounts the page (a `key` on the
 *     rendered subtree). The local history must reset so the
 *     Prev button is disabled on the fresh mount and the page
 *     returns to the first API call.
 *
 * For the supporting-lookup tabs (PositionsTab, AssignmentsTab,
 * TemporaryAssignmentsTab) the contract also requires that
 * missing labels are surfaced as the localized `text.unavailable`
 * copy and never silently fall back to a dash or a UUID. The
 * harness captures the DataTable's column renderers so the
 * assertion can call them directly.
 */

const session = {
  csrfToken: 'x',
  userId: 'u',
  expiresAt: '2026-12-31T00:00:00Z',
  restricted: false,
}

const principal = vi.hoisted(() => ({
  scopeEpoch: 0,
  capabilities: [] as string[],
}))

vi.mock('../../../app/principal-context', () => ({
  usePrincipal: () => ({
    state: 'ready',
    capabilities: principal.capabilities,
    features: { work_management: false, tasks: true },
    effectiveScope: null,
    availableScopes: [],
    revision: 0,
    scopeEpoch: principal.scopeEpoch,
    scopeReady: true,
    refresh: () => {},
    selectScope: async () => {},
  }),
  PrincipalContextTestProvider: ({ children }: { children: ReactNode }) => children,
}))

type CursorPage<T> = { items: T[]; next_cursor: string | null }
type QueryState = {
  data: unknown
  isLoading: boolean
  isError: boolean
  error: unknown
  refetch: () => void
}

const queries = vi.hoisted(() => {
  const calls: Record<string, string[]> = {
    facilities: [],
    positions: [],
    jobTitles: [],
    people: [],
    assignments: [],
    temporaryAssignments: [],
    supervisoryRelationships: [],
    allOrganizationUnits: [],
    allPositions: [],
    allJobTitles: [],
    allPeople: [],
  }
  const empty = (): QueryState => ({
    data: { items: [], next_cursor: null } as CursorPage<unknown>,
    isLoading: false,
    isError: false,
    error: null,
    refetch: () => {},
  })
  const state: Record<string, QueryState> = {
    facilities: empty(),
    positions: empty(),
    jobTitles: empty(),
    people: empty(),
    assignments: empty(),
    temporaryAssignments: empty(),
    supervisoryRelationships: empty(),
    allOrganizationUnits: empty(),
    allPositions: empty(),
    allJobTitles: empty(),
    allPeople: empty(),
  }
  return { calls, state, empty }
})

function track(key: string, cursor?: string): QueryState {
  queries.calls[key].push(cursor ?? 'null')
  return queries.state[key]
}

vi.mock('../../../api/hooks', () => ({
  useCluster: () => ({
    data: { id: 'cluster-1', code: 'C1', name_ar: 'تجمع', name_en: null, status: 'active', lock_version: 1 },
    isLoading: false,
    isError: false,
    error: null,
    refetch: () => {},
  }),
  useFacilities: (cursor?: string) => track('facilities', cursor),
  usePositions: (cursor?: string) => track('positions', cursor),
  useJobTitles: (cursor?: string) => track('jobTitles', cursor),
  usePeople: (cursor?: string) => track('people', cursor),
  useAssignments: (cursor?: string) => track('assignments', cursor),
  useTemporaryAssignments: (cursor?: string) => track('temporaryAssignments', cursor),
  useSupervisoryRelationships: (cursor?: string) => track('supervisoryRelationships', cursor),
  useAllOrganizationUnits: () => track('allOrganizationUnits'),
  useAllPositions: () => track('allPositions'),
  useAllJobTitles: () => track('allJobTitles'),
  useAllPeople: () => track('allPeople'),
}))

/*
 * Replace DataTable with a thin stub that captures the props the
 * tab is forwarding. The pagination contract is exactly these
 * props: `nextCursor`, `onNext`, `onPrev`, `canPrev`, plus the
 * column renderers that determine what the supporting-lookup
 * cell shows.
 */
interface CapturedTable {
  data: unknown[]
  state: string
  nextCursor: string | null
  canPrev: boolean
  getCell: (columnId: string, row: unknown) => unknown
}

const captured: CapturedTable[] = []

vi.mock('@/components/data-table', () => ({
  DataTable: ({
    data,
    state,
    nextCursor,
    onNext,
    onPrev,
    canPrev,
    columns,
  }: {
    data: unknown[]
    state: string
    nextCursor: string | null
    onNext: () => void
    onPrev: () => void
    canPrev: boolean
    columns: Array<{ id?: string; accessorKey?: string; cell?: (ctx: { row: { original: unknown } }) => unknown }>
  }) => {
    const entry: CapturedTable = {
      data,
      state,
      nextCursor,
      canPrev,
      getCell: (columnId, row) => {
        const column = columns.find((c) => (c.id ?? c.accessorKey) === columnId)
        if (!column?.cell) return null
        return column.cell({ row: { original: row } } as { row: { original: unknown } })
      },
    }
    captured[captured.length - 1] = entry
    return (
      <div data-testid="data-table" data-state={state} data-can-prev={canPrev ? '1' : '0'} data-next-cursor={nextCursor ?? 'null'}>
        <button type="button" data-testid="next" onClick={onNext} disabled={!nextCursor}>
          next
        </button>
        <button type="button" data-testid="prev" onClick={onPrev} disabled={!canPrev}>
          prev
        </button>
        <span data-testid="rows">{data.length}</span>
      </div>
    )
  },
}))

function mountTab(node: ReactNode) {
  cleanup()
  captured.length = 0
  captured.push({} as CapturedTable)
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  let result!: ReturnType<typeof render>
  // The scope-epoch `useEffect` resets pagination asynchronously after
  // the first render. Wrapping the mount in `act` lets the effect's
  // state update complete before the test code continues, so the
  // captured DataTable entry reflects the steady-state render rather
  // than the brief pre-effect render.
  act(() => {
    result = render(
      <QueryClientProvider client={client}>
        <MemoryRouter>
          <SessionProvider session={session} locale="ar" setLocale={() => {}}>
            <div key={principal.scopeEpoch}>{node}</div>
          </SessionProvider>
        </MemoryRouter>
      </QueryClientProvider>,
    )
  })
  return result
}

function resetQueries() {
  for (const key of Object.keys(queries.calls)) queries.calls[key] = []
  for (const key of Object.keys(queries.state)) queries.state[key] = queries.empty()
}

function setPage<T>(key: string, items: T[], nextCursor: string | null) {
  queries.state[key] = {
    data: { items, next_cursor: nextCursor } as CursorPage<unknown>,
    isLoading: false,
    isError: false,
    error: null,
    refetch: () => {},
  }
}

function clickAndDrain(testId: 'next' | 'prev', cursorAfterClick: string | null) {
  act(() => {
    fireEvent.click(screen.getByTestId(testId))
  })
  /*
   * Drain any pending React work triggered by the click (state
   * update from the tab plus the re-render that calls the hook
   * with the new cursor). `waitFor` retries until the captured
   * entry reflects the post-click state, so the test never races
   * the render.
   */
  return waitFor(() => {
    const table = captured[captured.length - 1]
    expect(table).toBeDefined()
    expect(table.nextCursor).toBe(cursorAfterClick)
  })
}

/*
 * The scope-epoch `useEffect` unconditionally writes a new
 * pagination object on mount, which causes a single re-render
 * before the user interacts. The first call sequence is therefore
 * `['null', 'null', ...]` instead of `['null', ...]`. The
 * pagination contract cares about the cursor sequence, not the
 * count of initial-mount calls, so the tests collapse consecutive
 * duplicates with this helper before asserting.
 */
function collapseInitialDouble(calls: string[]): string[] {
  const out: string[] = []
  for (const call of calls) {
    if (out.length === 0 || out[out.length - 1] !== call) out.push(call)
  }
  return out
}

beforeEach(() => {
  resetQueries()
  principal.scopeEpoch = 0
  principal.capabilities = []
})

/* ---------------------------------------------------------------- */
/* Per-tab pagination wiring                                          */
/* ---------------------------------------------------------------- */

describe('FacilitiesTab pagination', () => {
  beforeEach(() => {
    principal.capabilities = ['organization.facility.read']
  })

  it('disables prev on the first page and advances on a 3-page next/prev pattern', async () => {
    setPage('facilities', [{ id: 'f1' }], 'p2')
    mountTab(<FacilitiesTab />)
    const first = captured[captured.length - 1]
    expect(first.nextCursor).toBe('p2')
    expect(first.canPrev).toBe(false)
    expect(collapseInitialDouble(queries.calls.facilities)).toEqual(['null'])

    setPage('facilities', [{ id: 'f2' }], 'p3')
    await clickAndDrain('next', 'p3')
    expect(collapseInitialDouble(queries.calls.facilities)).toEqual(['null', 'p2'])

    setPage('facilities', [{ id: 'f3' }], null)
    await clickAndDrain('next', null)
    // Final page: next is disabled, prev is enabled.
    const last = captured[captured.length - 1]
    expect(last.nextCursor).toBeNull()
    expect(last.canPrev).toBe(true)
    expect(collapseInitialDouble(queries.calls.facilities)).toEqual(['null', 'p2', 'p3'])

    setPage('facilities', [{ id: 'f2' }], 'p3')
    await clickAndDrain('prev', 'p3')
    setPage('facilities', [{ id: 'f1' }], 'p2')
    await clickAndDrain('prev', 'p2')
    const reset = captured[captured.length - 1]
    expect(reset.canPrev).toBe(false)
    expect(collapseInitialDouble(queries.calls.facilities)).toEqual(['null', 'p2', 'p3', 'p2', 'null'])
  })
})

describe('PositionsTab pagination', () => {
  beforeEach(() => {
    principal.capabilities = ['organization.position.read']
    setPage('allOrganizationUnits', [{ id: 'u1', name_ar: 'وحدة' }], null)
    setPage('allJobTitles', [{ id: 'j1', title_ar: 'مدير' }], null)
  })

  it('wires next/prev through the page and surfaces a missing label as the unavailable copy, never a UUID or dash', () => {
    setPage('positions', [
      { id: 'p1', title_ar: 'منصب', organization_unit_id: 'u1', job_title_id: 'j1' },
      { id: 'p2', title_ar: 'منصب 2', organization_unit_id: 'u-unknown', job_title_id: 'j-unknown' },
    ], 'p2')
    mountTab(<PositionsTab />)

    const table = captured[captured.length - 1]
    expect(table.nextCursor).toBe('p2')
    expect(table.canPrev).toBe(false)

    const labeledCell = table.getCell('organization_unit_id', { organization_unit_id: 'u1' })
    const unknownCell = table.getCell('organization_unit_id', { organization_unit_id: 'u-unknown' })
    expect((labeledCell as { props: { children: string } }).props.children).toBe('وحدة')
    expect((unknownCell as { props: { children: string } }).props.children).toBe('غير متاح')
  })

  it('resets the local history on a scope-epoch remount', async () => {
    setPage('positions', [{ id: 'p1' }], 'p2')
    mountTab(<PositionsTab />)
    setPage('positions', [{ id: 'p2' }], null)
    await clickAndDrain('next', null)
    expect(captured[captured.length - 1].canPrev).toBe(true)

    principal.scopeEpoch = 1
    setPage('positions', [{ id: 'p1' }], 'p2')
    mountTab(<PositionsTab />)
    expect(captured[captured.length - 1].canPrev).toBe(false)
  })
})

describe('JobTitlesTab pagination', () => {
  beforeEach(() => {
    principal.capabilities = ['organization.position.read']
  })

  it('forwards the cursor to the hook on each navigation', async () => {
    setPage('jobTitles', [{ id: 'j1' }], 'p2')
    mountTab(<JobTitlesTab />)
    setPage('jobTitles', [{ id: 'j2' }], 'p3')
    await clickAndDrain('next', 'p3')
    setPage('jobTitles', [{ id: 'j3' }], null)
    await clickAndDrain('next', null)
    setPage('jobTitles', [{ id: 'j2' }], 'p3')
    await clickAndDrain('prev', 'p3')
    setPage('jobTitles', [{ id: 'j1' }], 'p2')
    await clickAndDrain('prev', 'p2')
    expect(collapseInitialDouble(queries.calls.jobTitles)).toEqual(['null', 'p2', 'p3', 'p2', 'null'])
    expect(captured[captured.length - 1].canPrev).toBe(false)
  })

  it('shows the add action only when the manage capability is held', () => {
    setPage('jobTitles', [], null)
    principal.capabilities = ['organization.position.read']
    mountTab(<JobTitlesTab />)
    expect(screen.queryByRole('button', { name: 'إضافة مسمى وظيفي' })).toBeNull()

    principal.capabilities = ['organization.position.read', 'organization.position.manage']
    mountTab(<JobTitlesTab />)
    expect(screen.getByRole('button', { name: 'إضافة مسمى وظيفي' })).toBeInTheDocument()
  })
})

describe('PeopleTab pagination', () => {
  beforeEach(() => {
    principal.capabilities = ['organization.person.read']
  })

  it('keeps the current page non-cumulative and resets on scope-epoch remount', async () => {
    setPage('people', [{ id: 'per-1', display_name_ar: 'موظف 1' }], 'p2')
    mountTab(<PeopleTab />)
    const first = captured[captured.length - 1]
    expect(first.data).toHaveLength(1)
    expect(first.canPrev).toBe(false)

    setPage('people', [{ id: 'per-2', display_name_ar: 'موظف 2' }], null)
    await clickAndDrain('next', null)
    const second = captured[captured.length - 1]
    expect(second.data).toHaveLength(1)
    expect(second.canPrev).toBe(true)

    principal.scopeEpoch = 1
    setPage('people', [{ id: 'per-1', display_name_ar: 'موظف 1' }], 'p2')
    mountTab(<PeopleTab />)
    expect(captured[captured.length - 1].canPrev).toBe(false)
  })
})

describe('AssignmentsTab pagination', () => {
  beforeEach(() => {
    principal.capabilities = ['organization.assignment.read']
    setPage('allPeople', [{ id: 'per-1', display_name_ar: 'موظف 1' }], null)
    setPage('allPositions', [{ id: 'pos-1', title_ar: 'منصب 1' }], null)
  })

  it('renders an available person label and the localized unavailable copy for a missing lookup', () => {
    setPage('assignments', [
      { id: 'a1', person_id: 'per-1', position_id: 'pos-1', start_at: '2026-01-01T00:00:00Z', end_at: null, is_primary: true, status: 'active' },
      { id: 'a2', person_id: 'per-missing', position_id: 'pos-missing', start_at: '2026-01-01T00:00:00Z', end_at: null, is_primary: false, status: 'active' },
    ], null)
    mountTab(<AssignmentsTab />)

    const table = captured[captured.length - 1]
    const labeled = table.getCell('person_id', { person_id: 'per-1' }) as { props: { children: string } }
    const missing = table.getCell('person_id', { person_id: 'per-missing' }) as { props: { children: string } }
    expect(labeled.props.children).toBe('موظف 1')
    expect(missing.props.children).toBe('غير متاح')
  })

  it('forwards the cursor to the hook across the 3-page pattern', async () => {
    setPage('assignments', [{ id: 'a1' }], 'p2')
    mountTab(<AssignmentsTab />)
    setPage('assignments', [{ id: 'a2' }], 'p3')
    await clickAndDrain('next', 'p3')
    setPage('assignments', [{ id: 'a3' }], null)
    await clickAndDrain('next', null)
    const last = captured[captured.length - 1]
    expect(last.nextCursor).toBeNull()
    expect(last.canPrev).toBe(true)
    expect(collapseInitialDouble(queries.calls.assignments)).toEqual(['null', 'p2', 'p3'])
  })
})

describe('TemporaryAssignmentsTab pagination', () => {
  beforeEach(() => {
    setPage('allPeople', [{ id: 'per-1', display_name_ar: 'موظف 1' }], null)
  })

  it('forbids read and disables next when the capability is missing', () => {
    principal.capabilities = []
    setPage('temporaryAssignments', [], 'p2')
    mountTab(<TemporaryAssignmentsTab />)
    const table = captured[captured.length - 1]
    expect(table.state).toBe('forbidden')
    expect(table.nextCursor).toBeNull()
    expect(table.canPrev).toBe(false)
  })

  it('paginated 3-page pattern forwards the cursor at every step', async () => {
    principal.capabilities = ['organization.temporary-assignment.read']
    setPage('temporaryAssignments', [{ id: 't1' }], 'p2')
    mountTab(<TemporaryAssignmentsTab />)
    setPage('temporaryAssignments', [{ id: 't2' }], 'p3')
    await clickAndDrain('next', 'p3')
    setPage('temporaryAssignments', [{ id: 't3' }], null)
    await clickAndDrain('next', null)
    const last = captured[captured.length - 1]
    expect(last.nextCursor).toBeNull()
    expect(last.canPrev).toBe(true)
    expect(collapseInitialDouble(queries.calls.temporaryAssignments)).toEqual(['null', 'p2', 'p3'])
  })
})

describe('SupervisoryTab pagination', () => {
  beforeEach(() => {
    principal.capabilities = ['organization.unit.read']
  })

  it('forwards the cursor to the hook on each navigation', async () => {
    setPage('supervisoryRelationships', [{ id: 's1', title: 'علاقة 1', description: 'direct', status: 'active' }], 'p2')
    mountTab(<SupervisoryTab />)
    const first = captured[captured.length - 1]
    expect(first.nextCursor).toBe('p2')
    expect(first.canPrev).toBe(false)

    setPage('supervisoryRelationships', [{ id: 's2', title: 'علاقة 2', description: 'functional', status: 'active' }], 'p3')
    await clickAndDrain('next', 'p3')
    setPage('supervisoryRelationships', [{ id: 's3', title: 'علاقة 3', description: 'coordination', status: 'active' }], null)
    await clickAndDrain('next', null)
    const third = captured[captured.length - 1]
    expect(third.nextCursor).toBeNull()
    expect(third.canPrev).toBe(true)

    setPage('supervisoryRelationships', [{ id: 's2' }], 'p3')
    await clickAndDrain('prev', 'p3')
    setPage('supervisoryRelationships', [{ id: 's1' }], 'p2')
    await clickAndDrain('prev', 'p2')
    const reset = captured[captured.length - 1]
    expect(reset.canPrev).toBe(false)
    expect(collapseInitialDouble(queries.calls.supervisoryRelationships)).toEqual(['null', 'p2', 'p3', 'p2', 'null'])
  })

  it('keeps next/prev disabled when the principal cannot read', () => {
    principal.capabilities = []
    setPage('supervisoryRelationships', [{ id: 's1' }], 'p2')
    mountTab(<SupervisoryTab />)
    const table = captured[captured.length - 1]
    expect(table.state).toBe('forbidden')
    expect(table.nextCursor).toBeNull()
    expect(table.canPrev).toBe(false)
  })
})

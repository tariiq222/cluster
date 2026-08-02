// @vitest-environment jsdom
import { describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { HomeDashboard } from './HomeDashboard'
import { SessionProvider } from '../../app/session-context'
import { PrincipalContextTestProvider } from '../../app/principal-context'

const session = { csrfToken: 'x', userId: 'u', expiresAt: '2026-12-31T00:00:00Z', restricted: false }

vi.mock('../../api/query', () => ({
  useScopeEpoch: () => 0,
  useApiQuery: (key: readonly unknown[]) => {
    if (key[0] === 'tasks') {
      return {
        data: undefined,
        isLoading: false,
        isError: true,
        error: new Error('tasks exploded'),
        refetch: vi.fn(),
      }
    }
    return { data: { items: [], next_cursor: null }, isLoading: false, isError: false, error: null, refetch: vi.fn() }
  },
}))

vi.mock('../../api/hooks', () => ({
  useNotificationsList: () => ({
    data: {
      items: [
        { id: 'n1', title: 'إشعار مهم', is_read: false, created_at: '2026-07-01T00:00:00Z' },
      ],
      next_cursor: null,
    },
    isLoading: false,
    isError: false,
    error: null,
    refetch: vi.fn(),
  }),
}))

function mount() {
  return render(
    <MemoryRouter>
      <SessionProvider session={session} locale="ar" setLocale={() => {}}>
        <PrincipalContextTestProvider capabilities={['tasks.list']} features={{ work_management: false, tasks: true }}>
          <HomeDashboard />
        </PrincipalContextTestProvider>
      </SessionProvider>
    </MemoryRouter>,
  )
}

describe('home dashboard', () => {
  it('renders the remaining cards when one query fails', () => {
    mount()
    expect(screen.getByText('إشعار مهم')).toBeInTheDocument()
    const alerts = screen.getAllByRole('alert')
    expect(alerts.length).toBeGreaterThan(0)
    const tasksCard = screen.getByText('مهامي').closest('[data-slot="card"]')
    expect(tasksCard).not.toBeNull()
    expect(tasksCard!.querySelector('[role="alert"]')).not.toBeNull()
    const notificationsCard = screen.getByText('إشعارات غير مقروءة').closest('[data-slot="card"]')
    expect(notificationsCard!.querySelector('[role="alert"]')).toBeNull()
  })

  it('keeps the KPIs group as an accessible group', () => {
    mount()
    const group = screen.getByRole('group', { name: 'مؤشرات الأداء' })
    expect(group).toBeInTheDocument()
    // The shared PageLayout shell is the centered max-width wrapper.
    expect(group.closest('.mx-auto.w-full.max-w-6xl.min-w-0.space-y-6')).not.toBeNull()
  })

  it('renders the KPI tiles through the shared Card surfaces, never bespoke divs', () => {
    mount()
    const group = screen.getByRole('group', { name: 'مؤشرات الأداء' })
    const cards = group.querySelectorAll('[data-slot="card"]')
    // The principal in this test cannot decide, so the two always-on KPIs are
    // rendered as shared Card surfaces inside the <dl> group.
    expect(cards.length).toBe(2)
    // The <dl> remains the accessible group: the Card surfaces are children,
    // not a replacement for the description-list semantics.
    expect(group.querySelectorAll('dt')).toHaveLength(2)
    expect(group.querySelectorAll('dd')).toHaveLength(2)
  })

  it('renders the effective-scope card with the scope label', () => {
    mount()
    expect(screen.getByText('نطاق عملك الحالي')).toBeInTheDocument()
  })
})

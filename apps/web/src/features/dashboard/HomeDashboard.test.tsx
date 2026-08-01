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
    expect(screen.getByRole('group', { name: 'مؤشرات الأداء' })).toBeInTheDocument()
  })

  it('renders the effective-scope card with the scope label', () => {
    mount()
    expect(screen.getByText('نطاق عملك الحالي')).toBeInTheDocument()
  })
})

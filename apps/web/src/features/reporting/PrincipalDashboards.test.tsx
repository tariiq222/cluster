// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'

import { PrincipalDashboards } from './PrincipalDashboards'
import { getDashboard, listDashboards } from '../../api/r1'

vi.mock('../../app/session-context', () => ({
  useLocale: () => 'ar',
  useToken: () => 'test-token',
}))

vi.mock('../../api/r1', () => ({
  getDashboard: vi.fn(),
  listDashboards: vi.fn(),
}))

const listDashboardsMock = vi.mocked(listDashboards)
const getDashboardMock = vi.mocked(getDashboard)

beforeEach(() => {
  vi.resetAllMocks()
})

afterEach(() => {
  cleanup()
})

describe('the home indicator band', () => {
  it('renders nothing for a principal who holds no dashboards', async () => {
    listDashboardsMock.mockResolvedValue({ items: [], total: 0 })

    const { container } = render(<PrincipalDashboards onOpen={vi.fn()} />)

    await waitFor(() => expect(listDashboardsMock).toHaveBeenCalled())
    expect(container.textContent).toBe('')
  })

  it('renders nothing when the principal may not list dashboards at all', async () => {
    listDashboardsMock.mockRejectedValue(new Error('forbidden'))

    const { container } = render(<PrincipalDashboards onOpen={vi.fn()} />)

    await waitFor(() => expect(listDashboardsMock).toHaveBeenCalled())
    expect(container.textContent).toBe('')
  })

  it('renders a card per dashboard with the count the server scoped to the principal', async () => {
    listDashboardsMock.mockResolvedValue({
      items: [
        { id: 'dash-1', title: 'المعاملات المتأخرة' },
        { id: 'dash-2', title: 'الالتزام بالمواعيد' },
      ],
      total: 2,
    })
    getDashboardMock.mockImplementation(async (_token: string, id: string) =>
      id === 'dash-1' ? { items: [], total: 7 } : { items: [], total: 12 },
    )

    render(<PrincipalDashboards onOpen={vi.fn()} />)

    expect(await screen.findByText('المعاملات المتأخرة')).toBeTruthy()
    expect(screen.getByText('7')).toBeTruthy()
    expect(screen.getByText('الالتزام بالمواعيد')).toBeTruthy()
    expect(screen.getByText('12')).toBeTruthy()
  })

  it('drops a dashboard the principal may list but not read, and keeps the rest', async () => {
    listDashboardsMock.mockResolvedValue({
      items: [
        { id: 'dash-1', title: 'مسموح' },
        { id: 'dash-2', title: 'ممنوع' },
      ],
      total: 2,
    })
    getDashboardMock.mockImplementation(async (_token: string, id: string) => {
      if (id === 'dash-2') throw new Error('forbidden')
      return { items: [], total: 3 }
    })

    render(<PrincipalDashboards onOpen={vi.fn()} />)

    expect(await screen.findByText('مسموح')).toBeTruthy()
    expect(screen.queryByText('ممنوع')).toBeNull()
  })

  it('falls back to the item count when the server sends no total', async () => {
    listDashboardsMock.mockResolvedValue({ items: [{ id: 'dash-1', title: 'مؤشر' }], total: 1 })
    getDashboardMock.mockResolvedValue({ items: [{ id: 'a' }, { id: 'b' }] } as never)

    render(<PrincipalDashboards onOpen={vi.fn()} />)

    expect(await screen.findByText('2')).toBeTruthy()
  })

  it('sends the user to the reports screen for the detail behind an indicator', async () => {
    const onOpen = vi.fn()
    listDashboardsMock.mockResolvedValue({ items: [{ id: 'dash-1', title: 'مؤشر' }], total: 1 })
    getDashboardMock.mockResolvedValue({ items: [], total: 1 })

    render(<PrincipalDashboards onOpen={onOpen} />)

    fireEvent.click(await screen.findByRole('button', { name: 'فتح التفاصيل' }))
    expect(onOpen).toHaveBeenCalledTimes(1)
  })
})

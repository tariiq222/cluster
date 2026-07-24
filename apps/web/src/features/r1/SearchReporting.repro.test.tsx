// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'

import { SessionProvider } from '../../app/session-context'
import { SearchScreen, ReportsScreen } from './R1Screens'
import { ApiError } from '../../api'
import * as r1 from '../../api/r1'

vi.mock('../../api/r1', async () => {
  const actual = await vi.importActual<typeof import('../../api/r1')>('../../api/r1')
  return { ...actual, searchRecords: vi.fn(), listReports: vi.fn(), listDashboards: vi.fn(), getReport: vi.fn(), getDashboard: vi.fn(), requestReportExport: vi.fn(), getReportExport: vi.fn() }
})

function renderWithSession(ui: React.ReactNode) {
  return render(<SessionProvider locale="en" session={{ access_token: 'token' } as never}>{ui}</SessionProvider>)
}
if (!Element.prototype.scrollIntoView) Element.prototype.scrollIntoView = () => {}

beforeEach(() => vi.resetAllMocks())
afterEach(() => cleanup())

describe('search behavioral contracts', () => {
  it('passes type and status filters to the search API instead of filtering only after an unscoped query', async () => {
    renderWithSession(<SearchScreen />)
    vi.mocked(r1.searchRecords).mockResolvedValue({ items: [{ id: 'task-1', resource_type: 'task', status: 'open' }], total: 1 })
    fireEvent.change(screen.getByRole('textbox', { name: 'Search text' }), { target: { value: 'invoice' } })
    fireEvent.click(screen.getByRole('button', { name: 'Type' }))
    fireEvent.click(await screen.findByRole('option', { name: 'Task' }))
    fireEvent.click(screen.getByRole('button', { name: 'Status' }))
    fireEvent.click(await screen.findByRole('option', { name: 'Submitted' }))
    fireEvent.click(screen.getByRole('button', { name: 'Search' }))
    await waitFor(() => expect(r1.searchRecords).toHaveBeenCalledWith('token', 'invoice', { type: 'task', status: 'submitted' }))
  })

  it('does not expose action links for denied search results', async () => {
    vi.mocked(r1.searchRecords).mockResolvedValue({ items: [{ id: 'hidden', title: 'Secret', allowed_actions: [] }], total: 1 })
    renderWithSession(<SearchScreen />)
    fireEvent.change(screen.getByRole('textbox', { name: 'Search text' }), { target: { value: 'secret' } })
    fireEvent.click(screen.getByRole('button', { name: 'Search' }))
    expect(screen.queryByRole('link', { name: /open|view|edit/i })).toBeNull()
  })

  it('renders an explicit alert for search failures and preserves retry', async () => {
    vi.mocked(r1.searchRecords).mockRejectedValueOnce(new ApiError(500, { type: 'about:blank', status: 500, title: 'failed' }))
      .mockResolvedValueOnce({ items: [{ id: 'r1', title: 'Recovered' }], total: 1 })
    renderWithSession(<SearchScreen />)
    fireEvent.change(screen.getByRole('textbox', { name: 'Search text' }), { target: { value: 'x' } })
    fireEvent.click(screen.getByRole('button', { name: 'Search' }))
    expect(await screen.findByRole('alert')).toBeTruthy()
    fireEvent.click(screen.getByRole('button', { name: 'Try again' }))
    expect(await screen.findByText('Recovered')).toBeTruthy()
  })
})

describe('reports behavioral contracts', () => {
  it('does not render export actions for dashboard selections', async () => {
    vi.mocked(r1.listReports).mockResolvedValue({ items: [], total: 0 })
    vi.mocked(r1.listDashboards).mockResolvedValue({ items: [{ id: 'd1', title: 'Dashboard' }], total: 1 })
    vi.mocked(r1.getDashboard).mockResolvedValue({ items: [{ id: 'x', name: 'row' }], total: 1 })
    renderWithSession(<ReportsScreen />)
    expect(await screen.findByText('Dashboard')).toBeTruthy()
    expect(screen.queryByRole('button', { name: 'Request export' })).toBeNull()
  })

  it('replaces stale report data when a new selection load fails', async () => {
    vi.mocked(r1.listReports).mockResolvedValue({ items: [{ id: 'r1', title: 'First' }, { id: 'r2', title: 'Second' }], total: 2 })
    vi.mocked(r1.listDashboards).mockResolvedValue({ items: [], total: 0 })
    vi.mocked(r1.getReport).mockResolvedValueOnce({ items: [{ id: 'a', name: 'Old row' }], total: 1 }).mockRejectedValueOnce(new ApiError(403, { type: 'about:blank', status: 403, title: 'denied' }))
    renderWithSession(<ReportsScreen />)
    expect(await screen.findByText('Old row')).toBeTruthy()
    fireEvent.click(screen.getByRole('button', { name: 'Report or dashboard' }))
    fireEvent.click(await screen.findByRole('option', { name: 'Second' }))
    await waitFor(() => expect(screen.queryByText('Old row')).toBeNull())
    expect(screen.getByText('You do not have permission to view this screen.')).toBeTruthy()
  })
})

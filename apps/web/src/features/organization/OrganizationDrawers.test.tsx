// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import type * as ApiModule from '../../api'
const api = vi.hoisted(() => ({
  endAssignment: vi.fn(),
  updateCluster: vi.fn(),
  updateFacility: vi.fn(),
  updatePerson: vi.fn(),
}))

vi.mock('../../api', async (importOriginal) => {
  const actual = await importOriginal<typeof ApiModule>()
  return { ...actual, ...api }
})

import { ApiError } from '../../api'
import { ClusterDrawer } from './ClusterDrawer'
import { EndAssignmentDrawer } from './EndAssignmentDrawer'
import { FacilityDrawer } from './FacilityDrawer'
import { PersonDrawer } from './PersonDrawer'

const cluster = {
  id: '018f6f7d-0c00-7000-8000-000000000001', code: 'THC3', name_ar: 'التجمع الثالث',
  name_en: 'Third cluster', status: 'active', lock_version: 3,
} as const
const facility = {
  id: '018f6f7d-0c00-7000-8000-000000000002', cluster_id: cluster.id, type_code: 'hospital',
  code: 'HOSP-01', name_ar: 'مستشفى', name_en: 'Hospital', status: 'active', lock_version: 4,
} as const
const person = {
  id: '018f6f7d-0c00-7000-8000-000000000003', employee_number: 'EMP-1', display_name_ar: 'موظف',
  display_name_en: 'Employee', status: 'active', person_version: 2,
} as const
const assignment = {
  id: '018f6f7d-0c00-7000-8000-000000000004', person_id: person.id,
  position_id: '018f6f7d-0c00-7000-8000-000000000005', start_at: '2026-07-01T08:00:00Z',
  end_at: null, is_primary: true, status: 'active', end_reason: null, lock_version: 5,
} as const

function problem(status: 409 | 412, detail?: string): ApiError {
  return new ApiError(status, { type: 'about:blank', title: status === 409 ? 'Conflict' : 'Precondition Failed', status, ...(detail ? { detail } : {}) })
}

afterEach(() => {
  cleanup()
  vi.clearAllMocks()
})

describe('organization drawer error semantics', () => {
  it('keeps a person 409 conflict distinct and preserves server detail', async () => {
    api.updatePerson.mockRejectedValueOnce(problem(409, 'Employee number is already assigned.'))
    render(<PersonDrawer open person={person} locale="en" token="csrf" onClose={vi.fn()} onSaved={vi.fn()} />)

    fireEvent.click(screen.getByRole('button', { name: 'Save employee' }))

    const alert = await screen.findByTestId('org-drawer-alert')
    expect(alert.textContent).toContain('Employee number is already assigned.')
    expect(alert.textContent).not.toContain('changed elsewhere')
  })

  it('renders a person 412 as stale rather than conflict', async () => {
    api.updatePerson.mockRejectedValueOnce(problem(412))
    render(<PersonDrawer open person={person} locale="en" token="csrf" onClose={vi.fn()} onSaved={vi.fn()} />)

    fireEvent.click(screen.getByRole('button', { name: 'Save employee' }))

    const alert = await screen.findByTestId('org-drawer-alert')
    expect(alert.textContent).toContain('changed elsewhere')
  })

  it('keeps a cluster 409 conflict distinct and preserves server detail', async () => {
    api.updateCluster.mockRejectedValueOnce(problem(409, 'Cluster code already exists.'))
    render(<ClusterDrawer open cluster={cluster} locale="en" token="csrf" onClose={vi.fn()} onSaved={vi.fn()} />)

    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))

    const alert = await screen.findByTestId('org-drawer-alert')
    expect(alert.textContent).toContain('Cluster code already exists.')
    expect(alert.textContent).not.toContain('changed elsewhere')
  })

  it('renders a cluster 412 as stale rather than conflict', async () => {
    api.updateCluster.mockRejectedValueOnce(problem(412))
    render(<ClusterDrawer open cluster={cluster} locale="en" token="csrf" onClose={vi.fn()} onSaved={vi.fn()} />)

    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))

    const alert = await screen.findByTestId('org-drawer-alert')
    expect(alert.textContent).toContain('changed elsewhere')
  })

  it('keeps a facility 409 conflict distinct and preserves server detail', async () => {
    api.updateFacility.mockRejectedValueOnce(problem(409, 'Facility code already exists.'))
    render(<FacilityDrawer open cluster={cluster} facility={facility} locale="en" token="csrf" onClose={vi.fn()} onSaved={vi.fn()} />)

    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))

    const alert = await screen.findByTestId('org-drawer-alert')
    expect(alert.textContent).toContain('Facility code already exists.')
    expect(alert.textContent).not.toContain('changed elsewhere')
  })

  it('renders a facility 412 as stale rather than conflict', async () => {
    api.updateFacility.mockRejectedValueOnce(problem(412))
    render(<FacilityDrawer open cluster={cluster} facility={facility} locale="en" token="csrf" onClose={vi.fn()} onSaved={vi.fn()} />)

    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))

    const alert = await screen.findByTestId('org-drawer-alert')
    expect(alert.textContent).toContain('changed elsewhere')
  })

  it('keeps an assignment 409 conflict distinct and preserves server detail', async () => {
    api.endAssignment.mockRejectedValueOnce(problem(409, 'Assignment overlaps another active assignment.'))
    render(<EndAssignmentDrawer open assignment={assignment} locale="en" token="csrf" onClose={vi.fn()} onEnded={vi.fn()} />)
    fireEvent.change(screen.getByLabelText('Assignment end'), { target: { value: '2026-07-02T08:00' } })
    fireEvent.change(screen.getByLabelText('End reason'), { target: { value: 'Transfer' } })

    fireEvent.click(screen.getByRole('button', { name: 'End assignment' }))

    const alert = await screen.findByTestId('org-drawer-alert')
    expect(alert.textContent).toContain('Assignment overlaps another active assignment.')
    expect(alert.textContent).not.toContain('changed elsewhere')
  })

  it('renders an assignment 412 as stale rather than conflict', async () => {
    api.endAssignment.mockRejectedValueOnce(problem(412))
    render(<EndAssignmentDrawer open assignment={assignment} locale="en" token="csrf" onClose={vi.fn()} onEnded={vi.fn()} />)
    fireEvent.change(screen.getByLabelText('Assignment end'), { target: { value: '2026-07-02T08:00' } })
    fireEvent.change(screen.getByLabelText('End reason'), { target: { value: 'Transfer' } })

    fireEvent.click(screen.getByRole('button', { name: 'End assignment' }))

    const alert = await screen.findByTestId('org-drawer-alert')
    await waitFor(() => expect(alert.textContent).toContain('changed elsewhere'))
  })
})
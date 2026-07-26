// @vitest-environment jsdom
import { type ReactElement } from 'react'
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { type Mock, afterEach, describe, expect, it, vi } from 'vitest'

import type * as ApiModule from '../../api'
import { ApiError } from '../../api'
import { ClusterDrawer } from './ClusterDrawer'
import { EndAssignmentDrawer } from './EndAssignmentDrawer'
import { FacilityDrawer } from './FacilityDrawer'
import { PersonDrawer } from './PersonDrawer'

interface Spies {
  readonly close: Mock
  readonly saved: Mock
  readonly ended: Mock
}

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
  return new ApiError(status, {
    type: 'about:blank',
    title: status === 409 ? 'Conflict' : 'Precondition Failed',
    status,
    ...(detail ? { detail } : {}),
  })
}

function makeSpies(): Spies {
  return { close: vi.fn(), saved: vi.fn(), ended: vi.fn() }
}

interface DrawerControllerProps {
  readonly open: boolean
  readonly spies: Spies
}

function PersonDrawerHarness({ open, spies }: DrawerControllerProps): ReactElement {
  return (
    <PersonDrawer
      open={open}
      person={person}
      locale="en"
      token="csrf"
      onClose={() => {
        spies.close()
      }}
      onSaved={(saved) => {
        spies.saved(saved)
      }}
    />
  )
}

function ClusterDrawerHarness({ open, spies }: DrawerControllerProps): ReactElement {
  return (
    <ClusterDrawer
      open={open}
      cluster={cluster}
      locale="en"
      token="csrf"
      onClose={() => {
        spies.close()
      }}
      onSaved={(saved) => {
        spies.saved(saved)
      }}
    />
  )
}

function FacilityDrawerHarness({ open, spies }: DrawerControllerProps): ReactElement {
  return (
    <FacilityDrawer
      open={open}
      cluster={cluster}
      facility={facility}
      locale="en"
      token="csrf"
      onClose={() => {
        spies.close()
      }}
      onSaved={(saved) => {
        spies.saved(saved)
      }}
    />
  )
}

function EndAssignmentDrawerHarness({ open, spies }: DrawerControllerProps): ReactElement {
  return (
    <EndAssignmentDrawer
      open={open}
      assignment={assignment}
      locale="en"
      token="csrf"
      onClose={() => {
        spies.close()
      }}
      onEnded={(updated) => {
        spies.ended(updated)
      }}
    />
  )
}

async function expectMutationFailure(
  expectedMessage: string,
  dialogName: string,
  expectedKind: 'conflict' | 'stale',
  ...spies: ReadonlyArray<Mock>
): Promise<HTMLElement> {
  const alert = await screen.findByRole('alert')
  expect(alert.getAttribute('data-testid')).toBe('org-drawer-alert')
  expect(alert.textContent).toContain(expectedMessage)
  if (expectedKind === 'conflict') {
    expect(alert.textContent).not.toContain('changed elsewhere')
  }
  await waitFor(() => expect(document.activeElement).toBe(alert))
  expect(screen.getByRole('dialog', { name: dialogName })).toBeTruthy()
  for (const spy of spies) {
    expect(spy).not.toHaveBeenCalled()
  }
  return alert
}

async function fillAssignmentInputs(): Promise<void> {
  fireEvent.change(screen.getByLabelText('Assignment end'), { target: { value: '2026-07-02T08:00' } })
  fireEvent.change(screen.getByLabelText('End reason'), { target: { value: 'Transfer' } })
}

afterEach(() => {
  cleanup()
  vi.clearAllMocks()
})

describe('organization drawer error semantics', () => {
  it('keeps a person 409 conflict distinct and preserves server detail', async () => {
    api.updatePerson.mockRejectedValueOnce(problem(409, 'Employee number is already assigned.'))
    const spies = makeSpies()
    render(<PersonDrawerHarness open spies={spies} />)

    fireEvent.click(screen.getByRole('button', { name: 'Save employee' }))

    await expectMutationFailure(
      'Employee number is already assigned.',
      'Edit employee details',
      'conflict',
      spies.close,
      spies.saved,
    )
  })

  it('renders a person 412 as stale rather than conflict', async () => {
    api.updatePerson.mockRejectedValueOnce(problem(412))
    const spies = makeSpies()
    render(<PersonDrawerHarness open spies={spies} />)

    fireEvent.click(screen.getByRole('button', { name: 'Save employee' }))

    await expectMutationFailure(
      'changed elsewhere',
      'Edit employee details',
      'stale',
      spies.close,
      spies.saved,
    )
  })

  it('keeps a cluster 409 conflict distinct and preserves server detail', async () => {
    api.updateCluster.mockRejectedValueOnce(problem(409, 'Cluster code already exists.'))
    const spies = makeSpies()
    render(<ClusterDrawerHarness open spies={spies} />)

    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))

    await expectMutationFailure(
      'Cluster code already exists.',
      'Edit cluster information',
      'conflict',
      spies.close,
      spies.saved,
    )
  })

  it('renders a cluster 412 as stale rather than conflict', async () => {
    api.updateCluster.mockRejectedValueOnce(problem(412))
    const spies = makeSpies()
    render(<ClusterDrawerHarness open spies={spies} />)

    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))

    await expectMutationFailure(
      'changed elsewhere',
      'Edit cluster information',
      'stale',
      spies.close,
      spies.saved,
    )
  })

  it('keeps a facility 409 conflict distinct and preserves server detail', async () => {
    api.updateFacility.mockRejectedValueOnce(problem(409, 'Facility code already exists.'))
    const spies = makeSpies()
    render(<FacilityDrawerHarness open spies={spies} />)

    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))

    await expectMutationFailure(
      'Facility code already exists.',
      'Edit facility',
      'conflict',
      spies.close,
      spies.saved,
    )
  })

  it('renders a facility 412 as stale rather than conflict', async () => {
    api.updateFacility.mockRejectedValueOnce(problem(412))
    const spies = makeSpies()
    render(<FacilityDrawerHarness open spies={spies} />)

    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))

    await expectMutationFailure(
      'changed elsewhere',
      'Edit facility',
      'stale',
      spies.close,
      spies.saved,
    )
  })

  it('keeps an assignment 409 conflict distinct and preserves server detail', async () => {
    api.endAssignment.mockRejectedValueOnce(problem(409, 'Assignment overlaps another active assignment.'))
    const spies = makeSpies()
    render(<EndAssignmentDrawerHarness open spies={spies} />)
    await fillAssignmentInputs()

    fireEvent.click(screen.getByRole('button', { name: 'End assignment' }))

    await expectMutationFailure(
      'Assignment overlaps another active assignment.',
      'End assignment',
      'conflict',
      spies.close,
      spies.ended,
    )
  })

  it('renders an assignment 412 as stale rather than conflict', async () => {
    api.endAssignment.mockRejectedValueOnce(problem(412))
    const spies = makeSpies()
    render(<EndAssignmentDrawerHarness open spies={spies} />)
    await fillAssignmentInputs()

    fireEvent.click(screen.getByRole('button', { name: 'End assignment' }))

    await expectMutationFailure(
      'changed elsewhere',
      'End assignment',
      'stale',
      spies.close,
      spies.ended,
    )
  })
})

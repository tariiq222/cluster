// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { cleanup, fireEvent, render, screen } from '@testing-library/react'

const api = vi.hoisted(() => ({
  getCluster: vi.fn(),
  listFacilities: vi.fn(),
  listJobTitles: vi.fn(),
  listOrganizationUnits: vi.fn(),
  listPositions: vi.fn(),
  reorderOrganizationUnits: vi.fn(),
}))

vi.mock('../../api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../api')>()
  return { ...actual, ...api }
})

vi.mock('./OrganizationBoard', () => ({
  OrganizationBoard: ({
    onAddPosition,
  }: {
    readonly onAddPosition: (unitId: string) => void
  }) => (
    <button type="button" onClick={() => onAddPosition(unit.id)}>
      إضافة منصب هنا
    </button>
  ),
}))

import { SessionProvider } from '../../app/session-context'
import { ApiError } from '../../api'
import { OrganizationStructure } from './OrganizationStructure'

const cluster = {
  id: '018f6f7d-0c00-7000-8000-000000000001',
  code: 'THC3',
  name_ar: 'التجمع الصحي الثالث',
  name_en: 'Third Health Cluster',
  status: 'active',
  lock_version: 1,
}

const unit = {
  id: '018f6f7d-0c00-7000-8000-000000000002',
  cluster_id: cluster.id,
  parent_id: cluster.id,
  parent_type: 'cluster' as const,
  type_code: 'department',
  code: 'DEPT-01',
  name_ar: 'إدارة الخدمات',
  name_en: 'Services Department',
  status: 'active',
  path_cache: '/DEPT-01',
  depth: 1,
  lock_version: 1,
}

function renderStructure() {
  return render(
    <SessionProvider
      locale="ar"
      session={{
        csrf_token: 'csrf-token',
        access_token: 'csrf-token',
        user_id: '018f6f7d-0c00-7000-8000-000000000021',
        expires_at: '2026-07-22T12:00:00Z',
        restricted: false,
        principal: { user_id: '018f6f7d-0c00-7000-8000-000000000021' },
      }}
    >
      <OrganizationStructure />
    </SessionProvider>,
  )
}

beforeEach(() => {
  api.getCluster.mockResolvedValue(cluster)
  api.listFacilities.mockResolvedValue({ items: [], next_cursor: null })
  api.listJobTitles.mockResolvedValue({ items: [], next_cursor: null })
  api.listOrganizationUnits.mockResolvedValue({ items: [unit], next_cursor: null })
  api.listPositions.mockResolvedValue({ items: [], next_cursor: null })
})

afterEach(() => {
  cleanup()
  vi.clearAllMocks()
})

describe('OrganizationStructure', () => {
  it('shows one plain primary action when the structure loads', async () => {
    renderStructure()

    expect(
      await screen.findByRole('heading', { name: 'الهيكل التنظيمي' }),
    ).toBeTruthy()
    expect(
      screen.getByText('استعرض الوحدات والمناصب، واختر أي عنصر لعرض تفاصيله.'),
    ).toBeTruthy()
    expect(
      screen.getByRole('button', { name: 'إضافة إدارة أو قسم' }),
    ).toBeTruthy()
    expect(screen.queryByRole('button', { name: 'إضافة منصب' })).toBeNull()
  })

  it('opens position creation from the selected unit with an understandable identifier label', async () => {
    renderStructure()

    fireEvent.click(await screen.findByRole('button', { name: 'إضافة منصب هنا' }))

    expect(screen.getByRole('dialog', { name: 'إضافة منصب' })).toBeTruthy()
    expect(screen.getByRole('textbox', { name: 'الرقم التعريفي' })).toBeTruthy()
    expect(screen.queryByRole('textbox', { name: 'الرمز' })).toBeNull()
  })

  it('reorders units with the current cluster lock version', async () => {
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    api.reorderOrganizationUnits.mockResolvedValue({
      updated: 1,
      policy: 'type-priority-then-code',
    })
    renderStructure()

    fireEvent.click(await screen.findByRole('button', { name: 'ترتيب الوحدات' }))

    expect(await screen.findByText('تم ترتيب 1 وحدة.')).toBeTruthy()
    expect(api.reorderOrganizationUnits).toHaveBeenCalledWith('csrf-token', 1)
  })

  it('surfaces a stale message when reorder fails with 412', async () => {
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    api.reorderOrganizationUnits.mockRejectedValue(
      new ApiError(412, {
        type: 'https://cluster.example/problems/precondition-required',
        title: 'Precondition Failed',
        status: 412,
        detail: 'If-Match is required.',
      }),
    )
    renderStructure()

    fireEvent.click(await screen.findByRole('button', { name: 'ترتيب الوحدات' }))

    expect(
      await screen.findByText('بيانات قديمة، حدّث الصفحة ثم أعد المحاولة.'),
    ).toBeTruthy()
    expect(api.reorderOrganizationUnits).toHaveBeenCalledWith('csrf-token', 1)
  })
})

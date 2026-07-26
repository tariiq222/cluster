// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'

const api = vi.hoisted(() => ({
  createCluster: vi.fn(),
  createFacility: vi.fn(),
  getCluster: vi.fn(),
  listFacilities: vi.fn(),
  updateCluster: vi.fn(),
  updateFacility: vi.fn(),
}))

vi.mock('../../api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../api')>()
  return { ...actual, ...api }
})

import { ApiError } from '../../api'
import { SessionProvider } from '../../app/session-context'
import { OrganizationOverview } from './OrganizationOverview'

const cluster = {
  id: '018f6f7d-0c00-7000-8000-000000000001',
  code: 'THC3',
  name_ar: 'التجمع الصحي الثالث',
  name_en: 'Third Health Cluster',
  status: 'active',
  lock_version: 3,
}

const facility = {
  id: '018f6f7d-0c00-7000-8000-000000000002',
  cluster_id: cluster.id,
  type_code: 'hospital',
  code: 'HOSP-01',
  name_ar: 'مستشفى التجمع',
  name_en: 'Cluster Hospital',
  status: 'active',
  lock_version: 4,
}

function renderOverview(locale: 'ar' | 'en' = 'ar') {
  return render(
    <SessionProvider
      locale={locale}
      session={{
        csrf_token: 'csrf-token',
        access_token: 'csrf-token',
        user_id: '018f6f7d-0c00-7000-8000-000000000021',
        expires_at: '2026-07-22T12:00:00Z',
        restricted: false,
        principal: { user_id: '018f6f7d-0c00-7000-8000-000000000021' },
      }}
    >
      <OrganizationOverview />
    </SessionProvider>,
  )
}

function apiError(status: number, detail?: string): ApiError {
  return new ApiError(status, {
    type: 'about:blank',
    title: 'Request failed',
    status,
    ...(detail ? { detail } : {}),
  })
}

beforeEach(() => {
  api.getCluster.mockResolvedValue(cluster)
  api.listFacilities.mockResolvedValue({ items: [facility], next_cursor: null })
})

afterEach(() => {
  cleanup()
  vi.clearAllMocks()
})

describe('OrganizationOverview', () => {
  it('shows the Arabic overview with readable names before secondary identifiers', async () => {
    renderOverview()

    expect(await screen.findByRole('heading', { name: 'منشآت التجمع' })).toBeTruthy()
    expect(screen.getByText(cluster.name_ar)).toBeTruthy()
    expect(screen.getByText(facility.name_ar)).toBeTruthy()
    expect(screen.getByText(`الرقم التعريفي: ${facility.code}`).getAttribute('dir')).toBe('ltr')
  })

  it('prefers English organization names when the interface locale is English', async () => {
    renderOverview('en')

    expect(await screen.findByText(cluster.name_en)).toBeTruthy()
    expect(screen.getByText(facility.name_en)).toBeTruthy()
  })

  it('keeps required creation fields inside the cluster drawer', async () => {
    api.getCluster.mockRejectedValue(apiError(404))
    api.listFacilities.mockResolvedValue({ items: [], next_cursor: null })
    renderOverview()

    expect(await screen.findByRole('button', { name: 'إضافة تجمع' })).toBeTruthy()
    expect(screen.queryByRole('textbox', { name: 'الرقم التعريفي' })).toBeNull()
    fireEvent.click(screen.getByRole('button', { name: 'إضافة تجمع' }))
    expect(screen.getByRole('dialog', { name: 'إضافة تجمع' })).toBeTruthy()
    fireEvent.click(screen.getByRole('button', { name: 'حفظ التجمع' }))
    expect(screen.getAllByRole('alert').some((alert) => alert.textContent?.includes('أكمل الحقول المطلوبة'))).toBe(true)
  })

  it('shows a single facility creation action when the cluster has no facilities', async () => {
    api.listFacilities.mockResolvedValue({ items: [], next_cursor: null })
    renderOverview()

    expect(await screen.findByRole('button', { name: 'إضافة منشأة' })).toBeTruthy()
    expect(screen.queryByRole('textbox', { name: 'الرقم التعريفي' })).toBeNull()
  })

  it('reports forbidden and retryable loading states', async () => {
    api.getCluster.mockRejectedValueOnce(apiError(403))
    renderOverview()
    expect(await screen.findByText('لا تملك صلاحية إدارة بيانات التجمع.')).toBeTruthy()

    cleanup()
    api.getCluster.mockRejectedValueOnce(apiError(500))
    renderOverview()
    expect(await screen.findByRole('button', { name: 'إعادة المحاولة' })).toBeTruthy()
  })

  it('keeps a stale facility update in the drawer with understandable feedback', async () => {
    api.updateFacility.mockRejectedValue(apiError(412))
    renderOverview()

    fireEvent.click(await screen.findByRole('button', { name: 'تعديل المنشأة' }))
    fireEvent.change(screen.getByRole('textbox', { name: 'اسم المنشأة بالعربية' }), {
      target: { value: 'مستشفى محدّث' },
    })
    fireEvent.click(screen.getByRole('button', { name: 'حفظ التعديل' }))

    expect((await screen.findByRole('alert')).textContent).toContain('تغيّرت البيانات')
  })

  it('keeps a conflicting facility update in the drawer with understandable feedback', async () => {
    api.updateFacility.mockRejectedValue(apiError(409, 'الرقم التعريفي للمنشأة مستخدم بالفعل.'))
    renderOverview()

    fireEvent.click(await screen.findByRole('button', { name: 'تعديل المنشأة' }))
    fireEvent.change(screen.getByRole('textbox', { name: 'اسم المنشأة بالعربية' }), {
      target: { value: 'مستشفى محدّث' },
    })
    fireEvent.click(screen.getByRole('button', { name: 'حفظ التعديل' }))

    expect((await screen.findByRole('alert')).textContent).toContain('الرقم التعريفي للمنشأة مستخدم بالفعل.')
  })

  it('updates the overview after a successful cluster creation', async () => {
    api.getCluster.mockRejectedValue(apiError(404))
    api.listFacilities.mockResolvedValue({ items: [], next_cursor: null })
    api.createCluster.mockResolvedValue(cluster)
    renderOverview()

    fireEvent.click(await screen.findByRole('button', { name: 'إضافة تجمع' }))
    fireEvent.change(screen.getByRole('textbox', { name: 'الرقم التعريفي' }), { target: { value: cluster.code } })
    fireEvent.change(screen.getByRole('textbox', { name: 'اسم التجمع بالعربية' }), { target: { value: cluster.name_ar } })
    fireEvent.click(screen.getByRole('button', { name: 'حفظ التجمع' }))

    await waitFor(() => {
      expect(api.createCluster).toHaveBeenCalledWith('csrf-token', {
        code: cluster.code,
        name: cluster.name_ar,
        name_en: null,
      })
    })
    expect(screen.queryByRole('dialog', { name: 'إضافة تجمع' })).toBeNull()
    expect(screen.getByRole('button', { name: 'إضافة منشأة' })).toBeTruthy()
  })
})

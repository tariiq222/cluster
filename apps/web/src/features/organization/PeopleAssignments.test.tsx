// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react'

const api = vi.hoisted(() => ({
  createAssignment: vi.fn(),
  createPerson: vi.fn(),
  endAssignment: vi.fn(),
  listAssignments: vi.fn(),
  listPeople: vi.fn(),
  listPositions: vi.fn(),
  updatePerson: vi.fn(),
}))

vi.mock('../../api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../api')>()
  return { ...actual, ...api }
})

import { SessionProvider } from '../../app/session-context'
import { PeopleAssignments } from './PeopleAssignments'

const person = {
  id: '018f6f7d-0c00-7000-8000-000000000001',
  employee_number: 'EMP-001',
  display_name_ar: 'أحمد العتيبي',
  display_name_en: 'Ahmed Alotaibi',
  status: 'active',
  person_version: 2,
}

const position = {
  id: '018f6f7d-0c00-7000-8000-000000000002',
  title_ar: 'مدير العمليات',
  is_active: true,
}

const assignment = {
  id: '018f6f7d-0c00-7000-8000-000000000003',
  person_id: person.id,
  position_id: position.id,
  start_at: '2026-07-01T08:00:00Z',
  status: 'active',
  lock_version: 3,
}

function renderPeopleAssignments() {
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
      <PeopleAssignments />
    </SessionProvider>,
  )
}

beforeEach(() => {
  api.listPeople.mockResolvedValue({ items: [person], next_cursor: null })
  api.listPositions.mockResolvedValue({ items: [position], next_cursor: null })
  api.listAssignments.mockResolvedValue({ items: [assignment], next_cursor: null })
})

afterEach(() => {
  cleanup()
  vi.clearAllMocks()
})

describe('PeopleAssignments', () => {
  it('separates employees and assignments with localized names before employee numbers', async () => {
    renderPeopleAssignments()

    expect(await screen.findByRole('heading', { name: 'الموظفون والتكليفات' })).toBeTruthy()
    expect(screen.getByRole('heading', { name: 'الموظفون' })).toBeTruthy()
    expect(screen.getByRole('heading', { name: 'التكليفات' })).toBeTruthy()
    const employeesRegion = screen.getByRole('region', { name: 'الموظفون' })
    expect(within(employeesRegion).getByRole('cell', { name: person.display_name_ar })).toBeTruthy()
    expect(within(employeesRegion).getAllByRole('columnheader')[0]?.textContent).toBe('الموظف')
    expect(within(employeesRegion).getByText(person.employee_number).getAttribute('dir')).toBe('ltr')
  })

  it('keeps employee creation fields inside an employee drawer', async () => {
    renderPeopleAssignments()

    await screen.findByRole('button', { name: 'إضافة موظف' })
    expect(screen.queryByRole('textbox', { name: 'الرقم الوظيفي' })).toBeNull()
    fireEvent.click(screen.getByRole('button', { name: 'إضافة موظف' }))
    expect(screen.getByRole('dialog', { name: 'إضافة موظف' })).toBeTruthy()
    expect(screen.getByRole('textbox', { name: 'الرقم الوظيفي' })).toBeTruthy()
  })

  it('keeps assignment creation and ending actions inside their own drawers', async () => {
    renderPeopleAssignments()

    await screen.findByRole('button', { name: 'إنشاء تكليف' })
    expect(screen.queryByRole('button', { name: 'حفظ التكليف' })).toBeNull()
    fireEvent.click(screen.getByRole('button', { name: 'إنشاء تكليف' }))
    expect(screen.getByRole('dialog', { name: 'إنشاء تكليف' })).toBeTruthy()
    fireEvent.click(screen.getByRole('button', { name: 'إغلاق' }))
    fireEvent.click(screen.getByRole('button', { name: 'إنهاء التكليف' }))
    expect(screen.getByRole('dialog', { name: 'إنهاء التكليف' })).toBeTruthy()
  })

  it('explains missing prerequisites without hiding employee management', async () => {
    api.listPositions.mockResolvedValue({ items: [], next_cursor: null })
    renderPeopleAssignments()

    expect(await screen.findByRole('button', { name: 'إضافة موظف' })).toBeTruthy()
    expect(screen.queryByRole('button', { name: 'إنشاء تكليف' })).toBeNull()
    expect(screen.getByText('أضف منصباً نشطاً واحداً على الأقل قبل إنشاء تكليف.')).toBeTruthy()
  })
})

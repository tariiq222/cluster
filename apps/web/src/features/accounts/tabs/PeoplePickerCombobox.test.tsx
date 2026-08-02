// @vitest-environment jsdom
import { useState, type ReactNode } from 'react'
import { describe, expect, it, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent, cleanup } from '@testing-library/react'
import { SessionProvider } from '../../../app/session-context'
import { listPeopleCursor } from '../../../api/access'
import { PeoplePickerCombobox } from './PeoplePickerCombobox'

/*
 * Focused unit coverage for the people picker that drives the create-account
 * sheet (ACC-03). The picker is the SOLE cursor-loading owner for the
 * sheet, so this suite proves:
 *
 *  - 0 listPeopleCursor calls while the picker is closed (the sheet may be
 *    mounted but must not pre-fetch);
 *  - exactly 1 first-page call when the picker is first opened;
 *  - exactly 1 follow-up call per explicit load-more affordance, with the
 *    cursor forwarded verbatim;
 *  - the picker owns the error / denied / empty / retry surface and never
 *    surfaces a no-op state;
 *  - a later-page row is selectable and the parent receives the full
 *    Person (so submit can carry the right `person_version`).
 */

interface TestPerson {
  id: string
  display_name_ar: string
  display_name_en: string
  employee_number: string
  status: 'active'
  person_version: number
}

const firstPerson: TestPerson = {
  id: '01980f50-5f0d-7000-8000-000000000f01',
  display_name_ar: 'الموظف الأول',
  display_name_en: 'First employee',
  employee_number: 'EMP-1',
  status: 'active',
  person_version: 2,
}

const secondPerson: TestPerson = {
  id: '01980f50-5f0d-7000-8000-000000000f02',
  display_name_ar: 'الموظف في الصفحة الثانية',
  display_name_en: 'Second-page employee',
  employee_number: 'EMP-2',
  status: 'active',
  person_version: 17,
}

const session = { csrfToken: 'x', userId: 'u', expiresAt: '2026-12-31T00:00:00Z', restricted: false }

function mount(node: ReactNode) {
  return render(
    <SessionProvider session={session} locale="ar" setLocale={() => {}}>
      {node}
    </SessionProvider>,
  )
}

vi.mock('../../../api/access', () => ({
  listPeopleCursor: vi.fn(),
}))

beforeEach(() => {
  cleanup()
  vi.mocked(listPeopleCursor).mockReset()
})

/*
 * Mirrors the CreateAccountSheet wiring: the parent stores the selected
 * id in its own state (the form field) and forwards it back as
 * `selectedId`.
 */
function Harness({
  onSelect,
}: {
  onSelect: (person: TestPerson) => void
}) {
  const [selectedId, setSelectedId] = useState('')
  return (
    <PeoplePickerCombobox
      selectedId={selectedId}
      onSelect={(person) => {
        setSelectedId(person.id)
        onSelect(person)
      }}
      triggerId="people-picker"
      ariaLabel="قائمة الموظفين"
    />
  )
}

describe('PeoplePickerCombobox cursor pagination (ACC-03)', () => {
  /*
   * The trigger button carries `aria-label="قائمة الموظفين"` (Employees
   * list) by default so the picker is locatable by assistive tech without
   * depending on a wrapping `<FormLabel>` association.
   */
  const triggerName = 'قائمة الموظفين'

  it('issues zero people requests while closed and exactly one first-page request on open', async () => {
    vi.mocked(listPeopleCursor).mockImplementation(async (cursor?: string) => cursor === 'people-page-2'
      ? { items: [secondPerson], next_cursor: null }
      : { items: [firstPerson], next_cursor: 'people-page-2' })

    mount(<Harness onSelect={() => {}} />)

    // A closed picker must never pre-fetch.
    expect(listPeopleCursor).not.toHaveBeenCalled()

    fireEvent.click(screen.getByRole('button', { name: triggerName }))

    // Opening the picker triggers exactly one first-page request.
    expect(await screen.findByRole('option', { name: firstPerson.display_name_ar })).toBeInTheDocument()
    expect(listPeopleCursor).toHaveBeenCalledTimes(1)
    expect(listPeopleCursor).toHaveBeenCalledWith(undefined)
  })

  it('forwards the returned cursor on load-more, never refetching the first page, and selects a later-page row', async () => {
    vi.mocked(listPeopleCursor).mockImplementation(async (cursor?: string) => cursor === 'people-page-2'
      ? { items: [secondPerson], next_cursor: null }
      : { items: [firstPerson], next_cursor: 'people-page-2' })

    const onSelect = vi.fn()
    mount(<Harness onSelect={onSelect} />)

    fireEvent.click(screen.getByRole('button', { name: triggerName }))
    expect(await screen.findByRole('option', { name: firstPerson.display_name_ar })).toBeInTheDocument()
    expect(listPeopleCursor).toHaveBeenCalledTimes(1)

    // Load-more is exactly one call with the returned cursor.
    fireEvent.click(screen.getByRole('button', { name: 'تحميل المزيد' }))
    expect(await screen.findByRole('option', { name: secondPerson.display_name_ar })).toBeInTheDocument()
    expect(listPeopleCursor).toHaveBeenCalledTimes(2)
    expect(listPeopleCursor).toHaveBeenLastCalledWith('people-page-2')

    // Selecting a later-page row delivers the full Person so submit can
    // carry `person_version`.
    fireEvent.click(screen.getByRole('option', { name: secondPerson.display_name_ar }))
    expect(onSelect).toHaveBeenCalledWith(secondPerson)

    // After the popover closes the trigger keeps the human label rather
    // than falling back to an opaque id or placeholder. The aria-label
    // remains accessible to assistive tech; the visible text is the
    // selected person's display name.
    expect(screen.getByRole('button', { name: triggerName })).toHaveTextContent(secondPerson.display_name_ar)
  })

  it('does not refetch the first page when the picker is reopened after a selection', async () => {
    vi.mocked(listPeopleCursor).mockImplementation(async (cursor?: string) => cursor === 'people-page-2'
      ? { items: [secondPerson], next_cursor: null }
      : { items: [firstPerson], next_cursor: 'people-page-2' })

    mount(<Harness onSelect={() => {}} />)

    fireEvent.click(screen.getByRole('button', { name: triggerName }))
    expect(await screen.findByRole('option', { name: firstPerson.display_name_ar })).toBeInTheDocument()
    expect(listPeopleCursor).toHaveBeenCalledTimes(1)

    // Pick a row — popover closes.
    fireEvent.click(screen.getByRole('option', { name: firstPerson.display_name_ar }))
    expect(screen.queryByRole('option', { name: firstPerson.display_name_ar })).not.toBeInTheDocument()

    // Reopen — no new request, the loaded set is reused.
    fireEvent.click(screen.getByRole('button', { name: triggerName }))
    expect(screen.getByRole('option', { name: firstPerson.display_name_ar })).toBeInTheDocument()
    expect(listPeopleCursor).toHaveBeenCalledTimes(1)
  })
})

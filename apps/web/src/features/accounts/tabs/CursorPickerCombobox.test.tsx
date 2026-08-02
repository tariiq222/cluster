// @vitest-environment jsdom
import { useState, type ReactNode } from 'react'
import { describe, expect, it, vi } from 'vitest'
import { render, screen, fireEvent } from '@testing-library/react'
import { SessionProvider } from '../../../app/session-context'
import { CursorPickerCombobox, type CursorCollection } from './CursorPickerCombobox'

/*
 * Focused unit coverage for the generic cursor-aware picker used by the
 * /access assignment sheet.
 *
 * The full-sheet journey (Sheet -> nested Popover) cannot drive a second
 * nested picker in jsdom, so the picker behavior itself is proven here in
 * isolation: closed pickers never fetch, the first bounded page loads on
 * open, the load-more affordance forwards the returned cursor, second-page
 * rows are selectable, and the closed trigger keeps the human label of the
 * selected row. AssignmentSheet's cursor wiring (listAccounts / role
 * loaders) is asserted separately in AccessScreen.test.tsx.
 */

interface TestItem {
  id: string
  name_ar: string
  name_en: string
  version: number
}

const firstPage: TestItem = {
  id: '01980f50-5f0d-7000-8000-000000000e01',
  name_ar: 'الخيار الأول',
  name_en: 'First option',
  version: 1,
}

const secondPage: TestItem = {
  id: '01980f50-5f0d-7000-8000-000000000e02',
  name_ar: 'الخيار في الصفحة الثانية',
  name_en: 'Second-page option',
  version: 2,
}

function getLabel(item: TestItem, locale: 'ar' | 'en'): string {
  return locale === 'en' ? item.name_en : item.name_ar
}

const session = { csrfToken: 'x', userId: 'u', expiresAt: '2026-12-31T00:00:00Z', restricted: false }

function mount(node: ReactNode) {
  return render(
    <SessionProvider session={session} locale="ar" setLocale={() => {}}>
      {node}
    </SessionProvider>,
  )
}

function pickerProps(loadPage: (cursor: string | null) => Promise<CursorCollection<TestItem>>) {
  return {
    selectedId: '',
    onSelect: () => {},
    loadPage,
    getLabel,
    triggerId: 'test-picker',
    ariaLabel: 'قائمة الحسابات',
    searchPlaceholder: 'ابحث عن الحساب أو اختره…',
    emptyLabel: 'لا يطابق البحث أي حساب.',
    deniedLabel: 'لا يمكن الوصول إلى قائمة الحسابات.',
    errorLabel: 'تعذّر تحميل الحسابات.',
    loadingLabel: 'جارٍ تحميل الحسابات…',
    loadMoreLabel: 'تحميل المزيد من الحسابات',
  }
}

/*
 * Mirrors the AssignmentSheet wiring: the parent stores the selected id in
 * its own state (the form field) and forwards it back as `selectedId`.
 */
function Harness({
  onSelect,
  loadPage,
}: {
  onSelect: (item: TestItem) => void
  loadPage: (cursor: string | null) => Promise<CursorCollection<TestItem>>
}) {
  const [selectedId, setSelectedId] = useState('')
  return (
    <CursorPickerCombobox
      {...pickerProps(loadPage)}
      selectedId={selectedId}
      onSelect={(item) => {
        setSelectedId(item.id)
        onSelect(item)
      }}
    />
  )
}

describe('CursorPickerCombobox cursor pagination', () => {
  it('never fetches while closed and loads the bounded first page on open', async () => {
    const loadPage = vi.fn(async (cursor: string | null): Promise<CursorCollection<TestItem>> =>
      cursor === null
        ? { items: [firstPage], next_cursor: 'page-2' }
        : { items: [secondPage], next_cursor: null })

    mount(<Harness onSelect={() => {}} loadPage={loadPage} />)

    // A closed picker must not pre-fetch the catalog.
    expect(loadPage).not.toHaveBeenCalled()

    fireEvent.click(screen.getByRole('button', { name: 'قائمة الحسابات' }))
    expect(await screen.findByRole('option', { name: firstPage.name_ar })).toBeInTheDocument()
    expect(loadPage).toHaveBeenCalledTimes(1)
    expect(loadPage).toHaveBeenCalledWith(null)
  })

  it('forwards the returned cursor on load more, selects a second-page option, and keeps the human label', async () => {
    const onSelect = vi.fn()
    const loadPage = vi.fn(async (cursor: string | null): Promise<CursorCollection<TestItem>> =>
      cursor === null
        ? { items: [firstPage], next_cursor: 'page-2' }
        : { items: [secondPage], next_cursor: null })

    mount(<Harness onSelect={onSelect} loadPage={loadPage} />)

    fireEvent.click(screen.getByRole('button', { name: 'قائمة الحسابات' }))
    expect(await screen.findByRole('option', { name: firstPage.name_ar })).toBeInTheDocument()

    // The load-more affordance must pass the returned cursor to the loader.
    fireEvent.click(screen.getByRole('button', { name: 'تحميل المزيد من الحسابات' }))
    expect(await screen.findByRole('option', { name: secondPage.name_ar })).toBeInTheDocument()
    expect(loadPage).toHaveBeenLastCalledWith('page-2')

    // A second-page row is selectable and the parent receives the full item
    // (so the form can carry per-item fields such as lock_version).
    fireEvent.click(screen.getByRole('option', { name: secondPage.name_ar }))
    expect(onSelect).toHaveBeenCalledWith(secondPage)

    // After the popover closes the trigger keeps the human label of the
    // selected row rather than falling back to an id or the placeholder.
    expect(screen.getByRole('button', { name: 'قائمة الحسابات' })).toHaveTextContent(secondPage.name_ar)
  })
})

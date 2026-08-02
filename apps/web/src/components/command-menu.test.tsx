// @vitest-environment jsdom
import {
  act,
  fireEvent,
  render,
  screen,
  waitFor,
  within,
} from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, useLocation } from 'react-router-dom'
import { Home, ListTodo } from 'lucide-react'
import { CommandMenu } from './command-menu'

const hooks = vi.hoisted(() => ({ useSearch: vi.fn() }))

vi.mock('@/api/hooks', () => hooks)

function LocationProbe() {
  return <output data-testid="location">{useLocation().pathname}</output>
}

function mount() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  })
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <CommandMenu
          locale="ar"
          navigationEntries={[
            { path: '/', label: 'الرئيسية', icon: Home },
            { path: '/tasks', label: 'المهام', icon: ListTodo },
          ]}
        />
        <LocationProbe />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  hooks.useSearch.mockReturnValue({
    data: { items: [], next_cursor: null },
    isLoading: false,
    isError: false,
    refetch: vi.fn(),
  })
})

afterEach(() => {
  vi.useRealTimers()
})

describe('command menu', () => {
  it('is closed until the keyboard shortcut fires', () => {
    const { queryByRole } = mount()
    expect(queryByRole('dialog')).toBeNull()
    fireEvent.keyDown(document, { key: 'k', metaKey: true })
    expect(queryByRole('dialog')).not.toBeNull()
  })

  it('closes on Escape', () => {
    const { queryByRole } = mount()
    fireEvent.keyDown(document, { key: 'k', metaKey: true })
    fireEvent.keyDown(document, { key: 'Escape' })
    expect(queryByRole('dialog')).toBeNull()
  })

  it('opens with Control+K on non-Apple keyboards', () => {
    const { queryByRole } = mount()
    fireEvent.keyDown(document, { key: 'k', ctrlKey: true })
    expect(queryByRole('dialog')).not.toBeNull()
  })

  it('localizes routable results and uses source_id from search projections', async () => {
    vi.useFakeTimers()
    hooks.useSearch.mockReturnValue({
      data: {
        items: [
          {
            id: 'projection-1',
            source_type: 'task',
            source_id: 'task-1',
            title: 'مهمة نتيجة',
          },
          {
            id: 'projection-2',
            source_type: 'audit_event',
            source_id: 'audit-1',
            title: 'نتيجة غير قابلة للفتح',
          },
        ],
        next_cursor: null,
      },
      isLoading: false,
      isError: false,
      refetch: vi.fn(),
    })
    mount()
    fireEvent.keyDown(document, { key: 'k', metaKey: true })
    fireEvent.change(screen.getByPlaceholderText('ابحث في المنصة…'), {
      target: { value: 'نتيجة' },
    })
    await act(async () => {
      await vi.advanceTimersByTimeAsync(250)
    })

    expect(screen.getByText('مهمة')).toBeTruthy()
    expect(screen.queryByText('audit_event')).toBeNull()
    expect(screen.queryByText('نتيجة غير قابلة للفتح')).toBeNull()
    fireEvent.click(screen.getByText('مهمة نتيجة'))
    expect(screen.getByTestId('location')).toHaveTextContent('/tasks/task-1')
    expect(screen.queryByRole('dialog')).toBeNull()
  })

  it('distinguishes remote loading and error states from empty results', async () => {
    vi.useFakeTimers()
    const refetch = vi.fn()
    hooks.useSearch.mockReturnValue({
      data: undefined,
      isLoading: false,
      isError: true,
      refetch,
    })
    mount()
    fireEvent.keyDown(document, { key: 'k', metaKey: true })
    fireEvent.change(screen.getByPlaceholderText('ابحث في المنصة…'), {
      target: { value: 'تعذر' },
    })
    await act(async () => {
      await vi.advanceTimersByTimeAsync(250)
    })

    expect(screen.getByRole('alert')).toHaveTextContent('تعذر إكمال البحث.')
    fireEvent.click(screen.getByRole('button', { name: 'إعادة المحاولة' }))
    expect(refetch).toHaveBeenCalledOnce()
  })

  /* DIALOG-NAME-01: the controlled command dialog must surface its
   * localized accessible name and description so screen readers announce
   * "Search …" instead of falling back to the raw placeholder. The Radix
   * DialogHeader carries the sr-only title/description; the DialogContent
   * wires aria-labelledby/aria-describedby to those ids. */
  it('exposes the localized accessible name and description on the dialog', () => {
    mount()
    fireEvent.keyDown(document, { key: 'k', metaKey: true })
    const dialog = screen.getByRole('dialog')
    expect(dialog).toHaveAccessibleName('بحث')
    expect(dialog).toHaveAccessibleDescription('ابحث في المنصة…')
  })

  /* QUERY-RESET-01: Escape must reset the visible query (and the
   * debounced remote-query that drives the result list) so reopening
   * the menu does not resurrect the previous in-flight search. The
   * item title matches the typed query so cmdk does not filter it out
   * before the assertion runs. */
  it('clears the visible query and the result list when the menu closes and reopens', async () => {
    vi.useFakeTimers()
    const remoteItemTitle = 'مهمة مسودة'
    hooks.useSearch.mockImplementation((query: string) => ({
      data: query.length >= 2
        ? {
            items: [
              {
                id: 'projection-1',
                source_type: 'task',
                source_id: 'task-1',
                title: remoteItemTitle,
              },
            ],
            next_cursor: null,
          }
        : { items: [], next_cursor: null },
      isLoading: false,
      isError: false,
      refetch: vi.fn(),
    }))
    mount()
    fireEvent.keyDown(document, { key: 'k', metaKey: true })
    const input = screen.getByPlaceholderText(
      'ابحث في المنصة…',
    ) as HTMLInputElement
    fireEvent.change(input, { target: { value: 'مسودة' } })
    await act(async () => {
      await vi.advanceTimersByTimeAsync(250)
    })
    expect(input.value).toBe('مسودة')
    expect(screen.getByText(remoteItemTitle)).toBeTruthy()

    fireEvent.keyDown(document, { key: 'Escape' })
    expect(screen.queryByRole('dialog')).toBeNull()

    // Reopen — the previous typed query and its result must be gone.
    fireEvent.keyDown(document, { key: 'k', metaKey: true })
    const reopened = screen.getByPlaceholderText(
      'ابحث في المنصة…',
    ) as HTMLInputElement
    expect(reopened.value).toBe('')
    expect(screen.queryByText(remoteItemTitle)).toBeNull()
    expect(within(screen.getByRole('dialog')).getByText('القائمة')).toBeTruthy()
  })

  /* TOUCH-TARGETS-01: the compact command palette must keep its search
   * input and result rows at >=44px so the design system satisfies WCAG
   * 2.5.5 on touch. The fix is feature-level classes on the Command
   * root so the generated ui/command primitive stays untouched. Tailwind
   * compiles arbitrary variants into selectors that target descendants
   * without mutating the descendant's className, so the assertion checks
   * the parent's className for the descendant variants. */
  it('enforces 44px minimum heights via feature-level descendant classes on the command root', () => {
    mount()
    fireEvent.keyDown(document, { key: 'k', metaKey: true })
    const dialog = screen.getByRole('dialog')
    const command = dialog.querySelector('[data-slot="command"]')
    expect(command).not.toBeNull()
    // The Command root must carry arbitrary-variant descendants that target
    // both input-group and command-item slots without editing the
    // generated primitive.
    expect(command!.className).toMatch(
      /\[&_\[data-slot=input-group\]\]:min-h-11/,
    )
    // The generated InputGroup enforces h-8; the feature class must
    // override it with the important modifier so 44px wins regardless of
    // utility order.
    expect(command!.className).toMatch(/\[&_\[data-slot=input-group\]\]:h-11!/)
    expect(command!.className).toMatch(
      /\[&_\[data-slot=command-item\]\]:min-h-11/,
    )
  })

  /* LABEL-TRUNCATE-01: long navigation and result labels must remain
   * contained inside the row instead of hard-clipping past the dialog
   * border. The feature-level class contract on the label span enforces
   * the visible ellipsis and a tooltip fallback for the full text. */
  it('truncates navigation labels with the min-w-0 flex-1 truncate contract', () => {
    mount()
    fireEvent.keyDown(document, { key: 'k', metaKey: true })
    const dialog = screen.getByRole('dialog')
    const navItem = within(dialog)
      .getByRole('option', { name: 'الرئيسية' })
      .closest('[data-slot="command-item"]') as HTMLElement
    expect(navItem).not.toBeNull()
    const labelSpan = Array.from(navItem.querySelectorAll('span')).find(
      (node) => node.textContent === 'الرئيسية',
    ) as HTMLElement | undefined
    expect(labelSpan).toBeDefined()
    expect(labelSpan!.className).toMatch(/\bmin-w-0\b/)
    expect(labelSpan!.className).toMatch(/\bflex-1\b/)
    expect(labelSpan!.className).toMatch(/\btruncate\b/)
    expect(labelSpan!.getAttribute('title')).toBe('الرئيسية')
  })

  /* FOCUS-RESTORE-01: Escape must return focus to the element that was
   * active when the menu opened (the keyboard shortcut's previous
   * focus). The dialog is controlled from outside CommandMenu, so the
   * trigger is not a Radix DialogTrigger; the menu must capture and
   * restore focus itself. */
  it('restores focus to the element active before the menu opened after close', async () => {
    const client = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    })
    render(
      <QueryClientProvider client={client}>
        <MemoryRouter>
          <button data-testid="external-trigger">External</button>
          <CommandMenu
            locale="ar"
            navigationEntries={[
              { path: '/', label: 'الرئيسية', icon: Home },
            ]}
          />
        </MemoryRouter>
      </QueryClientProvider>,
    )
    const trigger = screen.getByTestId('external-trigger')
    trigger.focus()
    expect(document.activeElement).toBe(trigger)

    fireEvent.keyDown(document, { key: 'k', metaKey: true })
    await waitFor(() => {
      expect(screen.queryByRole('dialog')).not.toBeNull()
    })
    // Radix moved focus into the input.
    const input = screen.getByPlaceholderText('ابحث في المنصة…')
    expect(document.activeElement).toBe(input)

    fireEvent.keyDown(document, { key: 'Escape' })
    await waitFor(() => {
      expect(screen.queryByRole('dialog')).toBeNull()
    })
    await waitFor(() => {
      expect(document.activeElement).toBe(trigger)
    })
  })
})

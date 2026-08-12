// @vitest-environment jsdom
import { describe, expect, it, vi } from 'vitest'
import { render, fireEvent, screen, createEvent } from '@testing-library/react'
import type { ColumnDef } from '@tanstack/react-table'
import { DataTable } from './data-table'

interface Row { id: string; name: string }
const columns: ColumnDef<Row>[] = [{ accessorKey: 'name', header: 'Name' }]

describe('data table', () => {
  it('never renders page numbers or a total count', () => {
    const { container } = render(
      <DataTable columns={columns} data={[{ id: '1', name: 'a' }]} state="ready"
                 nextCursor={null} onNext={() => {}} onPrev={() => {}} canPrev={false} locale="ar" />,
    )
    expect(container.textContent).not.toMatch(/\d+\s*\/\s*\d+/)
    expect(container.textContent).not.toMatch(/of\s+\d+/i)
  })

  it('disables next when there is no cursor', () => {
    const onNext = vi.fn()
    const { getByRole } = render(
      <DataTable columns={columns} data={[{ id: '1', name: 'a' }]} state="ready"
                 nextCursor={null} onNext={onNext} onPrev={() => {}} canPrev={false} locale="ar" />,
    )
    const next = getByRole('button', { name: /التالي|next/i })
    expect(next).toBeDisabled()
    fireEvent.click(next)
    expect(onNext).not.toHaveBeenCalled()
  })

  it('advances when a cursor is present', () => {
    const onNext = vi.fn()
    const { getByRole } = render(
      <DataTable columns={columns} data={[{ id: '1', name: 'a' }]} state="ready"
                 nextCursor="abc" onNext={onNext} onPrev={() => {}} canPrev={false} locale="ar" />,
    )
    fireEvent.click(getByRole('button', { name: /التالي|next/i }))
    expect(onNext).toHaveBeenCalledOnce()
  })

  it('delegates non-ready states to the boundary and hides the table', () => {
    const { container } = render(
      <DataTable columns={columns} data={[]} state="forbidden"
                 nextCursor={null} onNext={() => {}} onPrev={() => {}} canPrev={false} locale="ar" />,
    )
    expect(container.querySelector('table')).toBeNull()
  })

  /*
   * ACC-01-HARDEN: a failed query must surface a real retry button bound to
   * the supplied callback. The boundary alone used to render a button bound
   * to `() => {}`; forwarding `onRetry` through the table removes the
   * no-op affordance.
   */
  it('renders the error retry button and forwards clicks to the supplied callback', () => {
    const onRetry = vi.fn()
    render(
      <DataTable
        columns={columns}
        data={[]}
        state="error"
        nextCursor={null}
        onNext={() => {}}
        onPrev={() => {}}
        canPrev={false}
        locale="ar"
        onRetry={onRetry}
        correlationId="corr-123"
      />,
    )
    expect(screen.getByText('corr-123')).toBeInTheDocument()
    const button = screen.getByRole('button', { name: /أعد المحاولة|try again/i })
    fireEvent.click(button)
    expect(onRetry).toHaveBeenCalledOnce()
  })

  /*
   * ACC-01-CORRECTION: a retry button without a real callback is a fake
   * affordance. ResourceBoundary must not render the retry control when
   * `onRetry` is absent — the error copy stays for the user, but no
   * clickable no-op is shown.
   */
  it('does not render a retry button when no onRetry callback is supplied', () => {
    render(
      <DataTable
        columns={columns}
        data={[]}
        state="error"
        nextCursor={null}
        onNext={() => {}}
        onPrev={() => {}}
        canPrev={false}
        locale="ar"
      />,
    )
    expect(screen.queryByRole('button', { name: /أعد المحاولة|try again/i })).toBeNull()
  })

  it('does not render a refresh control when no onRefresh callback is supplied', () => {
    render(
      <DataTable
        columns={columns}
        data={[]}
        state="stale"
        nextCursor={null}
        onNext={() => {}}
        onPrev={() => {}}
        canPrev={false}
        locale="ar"
      />,
    )
    expect(screen.queryByRole('button', { name: /حدّث|Refresh/i })).toBeNull()
  })

  /*
   * ACC-01-HARDEN: forbidden and not-found must render the same
   * non-disclosing copy even when retry wiring is present, so the user
   * cannot infer the existence of a resource from a differential surface.
   */
  it('renders identical forbidden and not-found copy', () => {
    const forbidden = render(
      <DataTable columns={columns} data={[]} state="forbidden"
                 nextCursor={null} onNext={() => {}} onPrev={() => {}} canPrev={false} locale="ar"
                 onRetry={vi.fn()} />,
    )
    const notFound = render(
      <DataTable columns={columns} data={[]} state="not-found"
                 nextCursor={null} onNext={() => {}} onPrev={() => {}} canPrev={false} locale="ar"
                 onRetry={vi.fn()} />,
    )
    expect(forbidden.container.textContent).toBe(notFound.container.textContent)
  })

  /*
   * ACC-02-ADAPT: the data-table's outermost wrapper must carry the
   * `min-w-0 max-w-full` clamps and the inner scroll container must own
   * horizontal scrolling. Without these, a long username or UUID forces
   * the document wider than the viewport (audit-confirmed: 148px overflow
   * on 390px viewport before this regression).
   */
  it('clamps the outer wrapper with min-w-0 / max-w-full and owns horizontal scrolling in the inner container', () => {
    const { container } = render(
      <DataTable columns={columns} data={[{ id: '1', name: 'a' }]} state="ready"
                 nextCursor={null} onNext={() => {}} onPrev={() => {}} canPrev={false} locale="ar" />,
    )
    /*
     * First <div> is the outermost flex column. It must declare the
     * width clamps so a wide table cannot push the page wider than the
     * viewport.
     */
    const outer = container.firstElementChild as HTMLElement | null
    expect(outer).not.toBeNull()
    expect(outer!.className).toMatch(/\bmin-w-0\b/)
    expect(outer!.className).toMatch(/\bmax-w-full\b/)

    /*
     * The inner scroll wrapper is the explicit horizontal scroll owner;
     * it carries the stable test hook and `overflow-x-auto`. Without
     * the inner scroll the table would force the outer wrapper to grow.
     */
    const scrollContainer = container.querySelector<HTMLElement>('[data-testid="data-table-scroll"]')
    expect(scrollContainer).not.toBeNull()
    expect(scrollContainer!.className).toMatch(/\boverflow-x-auto\b/)
    expect(scrollContainer!.className).toMatch(/\bmin-w-0\b/)
  })
})

/*
 * WAVE4-DATATABLE: keyboard accessibility for clickable rows.
 *
 * A `<tr>` is not natively focusable and Enter/Space do nothing on it;
 * the original implementation only attached `onClick`, which left keyboard
 * users without a way to activate the row. The fix gives the row
 * `tabindex="0"` and `role="button"` when `onRowClick` is supplied, so
 * the row joins the tab order and is announced as interactive. The
 * row's visible cell text becomes the accessible name (no `aria-label`
 * needed); activation is gated on Enter and Space, isolated from any
 * interactive descendant so a button inside a cell keeps its own
 * semantics.
 */
describe('data table row keyboard accessibility', () => {
  it('makes rows focusable and announces them as buttons when onRowClick is supplied', () => {
    const { container } = render(
      <DataTable
        columns={columns}
        data={[{ id: '1', name: 'a' }]}
        state="ready"
        nextCursor={null}
        onNext={() => {}}
        onPrev={() => {}}
        canPrev={false}
        locale="ar"
        onRowClick={() => {}}
      />,
    )
    const row = container.querySelector('tbody tr')
    expect(row).not.toBeNull()
    expect(row!.tagName).toBe('TR')
    expect(row).toHaveAttribute('tabindex', '0')
    expect(row).toHaveAttribute('role', 'button')
  })

  it('does not add interactive semantics to rows when onRowClick is absent', () => {
    const { container } = render(
      <DataTable
        columns={columns}
        data={[{ id: '1', name: 'a' }]}
        state="ready"
        nextCursor={null}
        onNext={() => {}}
        onPrev={() => {}}
        canPrev={false}
        locale="ar"
      />,
    )
    const rows = container.querySelectorAll('tbody tr')
    rows.forEach((row) => {
      expect(row.getAttribute('role')).toBeNull()
      expect(row.getAttribute('tabindex')).toBeNull()
    })
  })

  it('fires onRowClick once when the row receives Enter', () => {
    const onRowClick = vi.fn()
    const { container } = render(
      <DataTable
        columns={columns}
        data={[{ id: '1', name: 'a' }]}
        state="ready"
        nextCursor={null}
        onNext={() => {}}
        onPrev={() => {}}
        canPrev={false}
        locale="ar"
        onRowClick={onRowClick}
      />,
    )
    const row = container.querySelector('tbody tr')!
    fireEvent.keyDown(row, { key: 'Enter' })
    expect(onRowClick).toHaveBeenCalledOnce()
    expect(onRowClick).toHaveBeenCalledWith({ id: '1', name: 'a' })
  })

  it('fires onRowClick once when the row receives Space (and prevents page scroll)', () => {
    const onRowClick = vi.fn()
    const { container } = render(
      <DataTable
        columns={columns}
        data={[{ id: '1', name: 'a' }]}
        state="ready"
        nextCursor={null}
        onNext={() => {}}
        onPrev={() => {}}
        canPrev={false}
        locale="ar"
        onRowClick={onRowClick}
      />,
    )
    const row = container.querySelector('tbody tr')!
    /*
     * Build the event explicitly so the test can read `defaultPrevented`
     * synchronously. `fireEvent.keyDown` discards the underlying event
     * object before it can be inspected.
     */
    const event = createEvent.keyDown(row, { key: ' ' })
    fireEvent(row, event)
    expect(onRowClick).toHaveBeenCalledOnce()
    /*
     * Space is the page-scroll trigger in browsers; the row handler must
     * call preventDefault so activating the row does not also scroll the
     * page.
     */
    expect(event.defaultPrevented).toBe(true)
  })

  it('still fires onRowClick when the row body is clicked with a pointer', () => {
    const onRowClick = vi.fn()
    const { container } = render(
      <DataTable
        columns={columns}
        data={[{ id: '1', name: 'a' }]}
        state="ready"
        nextCursor={null}
        onNext={() => {}}
        onPrev={() => {}}
        canPrev={false}
        locale="ar"
        onRowClick={onRowClick}
      />,
    )
    const row = container.querySelector('tbody tr')!
    fireEvent.click(row)
    expect(onRowClick).toHaveBeenCalledOnce()
    expect(onRowClick).toHaveBeenCalledWith({ id: '1', name: 'a' })
  })

  it('does not fire onRowClick when keyboard activation originates from a descendant button', () => {
    const onRowClick = vi.fn()
    const actionColumns: ColumnDef<Row>[] = [
      { accessorKey: 'name', header: 'Name' },
      {
        id: 'actions',
        header: 'Actions',
        cell: () => (
          <button type="button">Open</button>
        ),
      },
    ]
    const { container } = render(
      <DataTable
        columns={actionColumns}
        data={[{ id: '1', name: 'a' }]}
        state="ready"
        nextCursor={null}
        onNext={() => {}}
        onPrev={() => {}}
        canPrev={false}
        locale="ar"
        onRowClick={onRowClick}
      />,
    )
    const innerButton = container.querySelector('tbody tr button')!
    fireEvent.keyDown(innerButton, { key: 'Enter' })
    expect(onRowClick).not.toHaveBeenCalled()
  })

  it('does not fire onRowClick when a descendant button is clicked with a pointer', () => {
    const onRowClick = vi.fn()
    const actionColumns: ColumnDef<Row>[] = [
      { accessorKey: 'name', header: 'Name' },
      {
        id: 'actions',
        header: 'Actions',
        cell: () => (
          <button type="button">Open</button>
        ),
      },
    ]
    const { container } = render(
      <DataTable
        columns={actionColumns}
        data={[{ id: '1', name: 'a' }]}
        state="ready"
        nextCursor={null}
        onNext={() => {}}
        onPrev={() => {}}
        canPrev={false}
        locale="ar"
        onRowClick={onRowClick}
      />,
    )
    const innerButton = container.querySelector('tbody tr button')!
    fireEvent.click(innerButton)
    expect(onRowClick).not.toHaveBeenCalled()
  })

  it('does not fire onRowClick when keyboard activation originates from a descendant link', () => {
    const onRowClick = vi.fn()
    const linkColumns: ColumnDef<Row>[] = [
      { accessorKey: 'name', header: 'Name' },
      {
        id: 'docs',
        header: 'Docs',
        cell: () => (
          <a href="/x">Open docs</a>
        ),
      },
    ]
    const { container } = render(
      <DataTable
        columns={linkColumns}
        data={[{ id: '1', name: 'a' }]}
        state="ready"
        nextCursor={null}
        onNext={() => {}}
        onPrev={() => {}}
        canPrev={false}
        locale="ar"
        onRowClick={onRowClick}
      />,
    )
    const link = container.querySelector('tbody tr a')!
    fireEvent.keyDown(link, { key: 'Enter' })
    expect(onRowClick).not.toHaveBeenCalled()
  })

  it('does not add a keyDown handler when onRowClick is absent (Enter does not throw)', () => {
    /*
     * Without `onRowClick` the row has no keyDown handler; pressing
     * Enter must not throw, and the row must remain non-interactive
     * (no role, no tabindex) so the contract is held.
     */
    const { container } = render(
      <DataTable
        columns={columns}
        data={[{ id: '1', name: 'a' }]}
        state="ready"
        nextCursor={null}
        onNext={() => {}}
        onPrev={() => {}}
        canPrev={false}
        locale="ar"
      />,
    )
    const row = container.querySelector('tbody tr')!
    expect(() => fireEvent.keyDown(row, { key: 'Enter' })).not.toThrow()
    expect(row.getAttribute('role')).toBeNull()
    expect(row.getAttribute('tabindex')).toBeNull()
  })
})

/*
 * WAVE4-DATATABLE: toolbar survives the empty state.
 *
 * The shared `ResourceBoundary` swallowed the toolbar in the empty
 * branch — a filtered list with zero rows lost its filters, so the user
 * had no recovery affordance. The toolbar now sits beside the boundary
 * and renders whenever filters / recovery controls are useful
 * (loading / ready / empty). It is still hidden for forbidden,
 * not-found, conflict, stale, and error, where re-rendering mutating
 * controls would either leak resource existence or duplicate the
 * boundary's own affordance.
 */
describe('data table toolbar visibility', () => {
  const toolbar = <div data-testid="custom-toolbar">filter row</div>
  const empty = <p data-testid="custom-empty">no rows</p>

  it('renders the toolbar alongside empty guidance for the empty state', () => {
    render(
      <DataTable
        columns={columns}
        data={[]}
        state="empty"
        nextCursor={null}
        onNext={() => {}}
        onPrev={() => {}}
        canPrev={false}
        locale="ar"
        toolbar={toolbar}
        empty={empty}
      />,
    )
    expect(screen.getByTestId('custom-toolbar')).toBeInTheDocument()
    expect(screen.getByTestId('custom-empty')).toBeInTheDocument()
  })

  it('renders the toolbar alongside the loading skeleton', () => {
    render(
      <DataTable
        columns={columns}
        data={[]}
        state="loading"
        nextCursor={null}
        onNext={() => {}}
        onPrev={() => {}}
        canPrev={false}
        locale="ar"
        toolbar={toolbar}
      />,
    )
    expect(screen.getByTestId('custom-toolbar')).toBeInTheDocument()
    expect(screen.getByTestId('loading-state')).toBeInTheDocument()
  })

  it('renders the toolbar for the ready state', () => {
    render(
      <DataTable
        columns={columns}
        data={[{ id: '1', name: 'a' }]}
        state="ready"
        nextCursor={null}
        onNext={() => {}}
        onPrev={() => {}}
        canPrev={false}
        locale="ar"
        toolbar={toolbar}
      />,
    )
    expect(screen.getByTestId('custom-toolbar')).toBeInTheDocument()
  })

  it('hides the toolbar for forbidden state so mutating controls never leak', () => {
    const { container } = render(
      <DataTable
        columns={columns}
        data={[]}
        state="forbidden"
        nextCursor={null}
        onNext={() => {}}
        onPrev={() => {}}
        canPrev={false}
        locale="ar"
        toolbar={toolbar}
      />,
    )
    expect(container.querySelector('[data-testid="custom-toolbar"]')).toBeNull()
  })

  it('hides the toolbar for not-found state so mutating controls never leak', () => {
    const { container } = render(
      <DataTable
        columns={columns}
        data={[]}
        state="not-found"
        nextCursor={null}
        onNext={() => {}}
        onPrev={() => {}}
        canPrev={false}
        locale="ar"
        toolbar={toolbar}
      />,
    )
    expect(container.querySelector('[data-testid="custom-toolbar"]')).toBeNull()
  })

  it('hides the toolbar for error state — boundary retry button is the sole affordance', () => {
    const { container } = render(
      <DataTable
        columns={columns}
        data={[]}
        state="error"
        nextCursor={null}
        onNext={() => {}}
        onPrev={() => {}}
        canPrev={false}
        locale="ar"
        toolbar={toolbar}
        onRetry={vi.fn()}
      />,
    )
    expect(container.querySelector('[data-testid="custom-toolbar"]')).toBeNull()
  })
})

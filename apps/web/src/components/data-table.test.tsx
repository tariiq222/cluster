// @vitest-environment jsdom
import { describe, expect, it, vi } from 'vitest'
import { render, fireEvent, screen } from '@testing-library/react'
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

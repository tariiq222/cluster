// @vitest-environment jsdom
import { describe, expect, it, vi } from 'vitest'
import { render, fireEvent } from '@testing-library/react'
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
})

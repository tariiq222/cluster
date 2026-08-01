import {
  flexRender,
  getCoreRowModel,
  useReactTable,
  type ColumnDef,
} from '@tanstack/react-table'
import { ChevronLeft, ChevronRight } from 'lucide-react'
import type { ResourceState } from '@/api/http'
import { Button } from '@/components/ui/button'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { ResourceBoundary } from '@/components/states'
import type { Locale } from '@/i18n'

const NEXT: Record<Locale, string> = { ar: 'التالي', en: 'Next' }
const PREVIOUS: Record<Locale, string> = { ar: 'السابق', en: 'Previous' }

/*
 * Cursor-paginated table. The API exposes no page numbers and no totals, so
 * the footer must never imply them — exactly two buttons, previous and next.
 */
export function DataTable<T>({
  columns,
  data,
  state,
  nextCursor,
  onNext,
  onPrev,
  canPrev,
  locale,
  toolbar,
  onRowClick,
}: {
  columns: ColumnDef<T>[]
  data: T[]
  state: ResourceState
  nextCursor: string | null
  onNext: () => void
  onPrev: () => void
  canPrev: boolean
  locale: Locale
  toolbar?: React.ReactNode
  onRowClick?: (row: T) => void
}) {
  const table = useReactTable({
    data,
    columns,
    getCoreRowModel: getCoreRowModel(),
  })

  return (
    <ResourceBoundary state={state} locale={locale}>
      {toolbar}
      <Table>
        <TableHeader>
          {table.getHeaderGroups().map((headerGroup) => (
            <TableRow key={headerGroup.id}>
              {headerGroup.headers.map((header) => (
                <TableHead key={header.id}>
                  {header.isPlaceholder ? null : flexRender(header.column.columnDef.header, header.getContext())}
                </TableHead>
              ))}
            </TableRow>
          ))}
        </TableHeader>
        <TableBody>
          {table.getRowModel().rows.length ? (
            table.getRowModel().rows.map((row) => (
              <TableRow
                key={row.id}
                data-state={row.getIsSelected() ? 'selected' : undefined}
                className={onRowClick ? 'cursor-pointer' : undefined}
                onClick={onRowClick ? () => onRowClick(row.original) : undefined}
              >
                {row.getVisibleCells().map((cell) => (
                  <TableCell key={cell.id}>
                    {flexRender(cell.column.columnDef.cell, cell.getContext())}
                  </TableCell>
                ))}
              </TableRow>
            ))
          ) : (
            <TableRow>
              <TableCell colSpan={columns.length} className="h-24 text-center" />
            </TableRow>
          )}
        </TableBody>
      </Table>
      <div className="flex items-center justify-end gap-2 pt-2">
        <Button variant="outline" size="sm" onClick={onPrev} disabled={!canPrev}>
          <ChevronRight aria-hidden="true" className="ltr:rotate-180" />
          {PREVIOUS[locale]}
        </Button>
        <Button variant="outline" size="sm" onClick={onNext} disabled={!nextCursor}>
          {NEXT[locale]}
          <ChevronLeft aria-hidden="true" className="ltr:rotate-180" />
        </Button>
      </div>
    </ResourceBoundary>
  )
}

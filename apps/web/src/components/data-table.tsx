import {
  flexRender,
  getCoreRowModel,
  useReactTable,
  type ColumnDef,
} from '@tanstack/react-table'
import { ChevronLeft, ChevronRight } from 'lucide-react'
import type { MouseEvent as ReactMouseEvent, KeyboardEvent as ReactKeyboardEvent } from 'react'
import type { ResourceState } from '@/api/http'
import { Button } from '@/components/ui/button'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { ResourceBoundary } from '@/components/states'
import type { Locale } from '@/i18n'

const NEXT: Record<Locale, string> = { ar: 'التالي', en: 'Next' }
const PREVIOUS: Record<Locale, string> = { ar: 'السابق', en: 'Previous' }

/*
 * Interactive elements that should swallow the row-level activation. A
 * pointer click or keyboard activation whose target (or any ancestor up to
 * the row) matches one of these selectors means the user meant to act on
 * the descendant control — the row's click handler must not also fire.
 *
 * The selector intentionally covers native form elements, common ARIA
 * roles, and any explicit tabindex so Radix/shadcn composites (which often
 * render with `role="button"` / `role="combobox"` rather than a native
 * `<button>`) are excluded too.
 */
const INTERACTIVE_SELECTOR = [
  'button',
  'a[href]',
  'input',
  'select',
  'textarea',
  'label[for]',
  '[role="button"]',
  '[role="link"]',
  '[role="checkbox"]',
  '[role="menuitem"]',
  '[role="menuitemcheckbox"]',
  '[role="menuitemradio"]',
  '[role="radio"]',
  '[role="switch"]',
  '[role="tab"]',
  '[role="option"]',
  '[role="textbox"]',
  '[role="combobox"]',
  '[role="searchbox"]',
  '[role="slider"]',
  '[role="spinbutton"]',
  '[tabindex]:not([tabindex="-1"])',
].join(', ')

function isInteractiveTarget(target: EventTarget | null, current: EventTarget | null): boolean {
  if (!(target instanceof HTMLElement)) return false
  if (target === current) return false
  return target.closest(INTERACTIVE_SELECTOR) !== null
}

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
  empty,
  onRetry,
  onRefresh,
  correlationId,
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
  empty?: React.ReactNode
  onRetry?: () => void
  onRefresh?: () => void
  correlationId?: string | null
}) {
  const table = useReactTable({
    data,
    columns,
    getCoreRowModel: getCoreRowModel(),
  })

  /*
   * Keep the toolbar visible in states where the user can usefully interact
   * with filters or recovery controls (loading, ready, empty). For
   * forbidden, not-found, conflict, stale, and error the boundary already
   * exposes its own affordance — re-rendering mutating controls there
   * would either leak resource existence (403/404 render identical copy
   * on purpose) or duplicate the boundary's recovery button. Callers who
   * genuinely need toolbar actions in those states must place them
   * outside DataTable, which is the documented escape hatch.
   */
  const showToolbar = state === 'loading' || state === 'ready' || state === 'empty'

  const handleRowClick = (
    event: ReactMouseEvent<HTMLTableRowElement>,
    row: T,
  ) => {
    if (!onRowClick) return
    if (isInteractiveTarget(event.target, event.currentTarget)) return
    onRowClick(row)
  }

  const handleRowKeyDown = (
    event: ReactKeyboardEvent<HTMLTableRowElement>,
    row: T,
  ) => {
    if (!onRowClick) return
    if (event.key !== 'Enter' && event.key !== ' ' && event.key !== 'Spacebar') return
    if (isInteractiveTarget(event.target, event.currentTarget)) return
    /*
     * preventDefault on Space stops the page from scrolling when the
     * focused row receives a Space activation. Enter needs it too so a
     * nested form (rare, but reachable through the toolbar) does not
     * submit.
     */
    event.preventDefault()
    onRowClick(row)
  }

  return (
    /*
     * The outermost flex column is clamped to the parent column width with
     * `min-w-0 max-w-full` so a long identifier or a wide cell cannot push
     * the page wider than the viewport: the inner `overflow-x-auto` owns
     * horizontal scrolling, never the document.
     */
    <div className="flex min-w-0 max-w-full flex-col gap-3">
      <div className="min-h-40 min-w-0">
        {/*
         * Toolbar sits outside ResourceBoundary so it survives the empty
         * state. The boundary replaces its children with the appropriate
         * affordance for non-ready states; rendering the toolbar as a
         * sibling means filters remain reachable exactly when recovery
         * controls are useful — when the result set is empty.
         */}
        {showToolbar ? toolbar : null}
        <ResourceBoundary
          state={state}
          locale={locale}
          empty={empty}
          rows={5}
          onRetry={onRetry}
          onRefresh={onRefresh}
          correlationId={correlationId}
        >
          <div
            data-testid="data-table-scroll"
            className="min-w-0 overflow-x-auto rounded-lg border"
          >
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
                  table.getRowModel().rows.map((row) => {
                    const interactive = onRowClick !== undefined
                    return (
                      <TableRow
                        key={row.id}
                        data-state={row.getIsSelected() ? 'selected' : undefined}
                        className={interactive ? 'cursor-pointer' : undefined}
                        /*
                         * When onRowClick is supplied, the row becomes a
                         * keyboard-reachable activation target. tabIndex
                         * pulls it into the focus order; role="button"
                         * announces it as an interactive control to
                         * assistive tech, and the row's visible text
                         * content serves as the accessible name (no
                         * aria-label needed — the cell content already
                         * names the row). Without onRowClick the row is
                         * purely structural and inherits the native `<tr>`
                         * semantics.
                         */
                        tabIndex={interactive ? 0 : undefined}
                        role={interactive ? 'button' : undefined}
                        onClick={interactive
                          ? (event) => handleRowClick(event, row.original)
                          : undefined}
                        onKeyDown={interactive
                          ? (event) => handleRowKeyDown(event, row.original)
                          : undefined}
                      >
                        {row.getVisibleCells().map((cell) => (
                          <TableCell key={cell.id}>
                            {flexRender(cell.column.columnDef.cell, cell.getContext())}
                          </TableCell>
                        ))}
                      </TableRow>
                    )
                  })
                ) : (
                  <TableRow>
                    <TableCell colSpan={columns.length} className="h-24 text-center" />
                  </TableRow>
                )}
              </TableBody>
            </Table>
          </div>
        </ResourceBoundary>
      </div>
      {state === 'ready' || (state === 'empty' && nextCursor) ? (
        <div className="flex items-center justify-end gap-2">
          <Button variant="outline" size="sm" onClick={onPrev} disabled={!canPrev}>
            <ChevronRight aria-hidden="true" className="ltr:rotate-180" />
            {PREVIOUS[locale]}
          </Button>
          <Button variant="outline" size="sm" onClick={onNext} disabled={!nextCursor}>
            {NEXT[locale]}
            <ChevronLeft aria-hidden="true" className="ltr:rotate-180" />
          </Button>
        </div>
      ) : null}
    </div>
  )
}

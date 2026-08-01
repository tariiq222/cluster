import { useMemo, useState } from 'react'
import type { ColumnDef } from '@tanstack/react-table'
import { useLocale } from '../../../app/session-context'
import { formatNumber } from '../../../i18n'
import * as generated from '../../../api/generated/cluster'
import { DataTable } from '@/components/data-table'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { importsCopy } from '../imports-copy'

type ImportStatus = generated.ImportJob['status']

interface RowView {
  id: string
  row_number: number
  proposed_action?: string
  decision?: string | null
  validation_errors: Array<{ code: string; severity: string; field?: string }>
}

function availableActions(status: ImportStatus): Array<'approve' | 'reject'> {
  if (status === 'validated') return ['approve', 'reject']
  return []
}

export function ReviewStep({
  rows,
  status,
  onTransition,
  busy,
}: {
  rows: RowView[]
  status: ImportStatus
  onTransition: (action: 'approve' | 'reject', reason?: string) => void
  busy?: boolean
}) {
  const locale = useLocale()
  const text = importsCopy[locale]
  const [blockingOnly, setBlockingOnly] = useState(true)
  const [reasonAction, setReasonAction] = useState<'approve' | 'reject' | null>(null)
  const [reason, setReason] = useState('')
  const [error, setError] = useState<boolean>(false)

  const visibleRows = useMemo(
    () => (blockingOnly ? rows.filter((row) => row.validation_errors.some((item) => item.severity === 'blocking')) : rows),
    [blockingOnly, rows],
  )

  const columns = useMemo<ColumnDef<RowView>[]>(
    () => [
      {
        accessorKey: 'row_number',
        header: text.row,
        cell: ({ row }) => <span className="font-medium">{text.row} {formatNumber(row.original.row_number, locale)}</span>,
      },
      {
        accessorKey: 'proposed_action',
        header: text.proposed,
        cell: ({ row }) => <span>{row.original.proposed_action ? text[row.original.proposed_action as 'create' | 'skip'] : '—'}</span>,
      },
      {
        accessorKey: 'decision',
        header: text.decision,
        cell: ({ row }) => (
          <span>
            {row.original.decision === 'accepted'
              ? text.accepted
              : row.original.decision === 'rejected'
                ? text.rejectedDecision
                : '—'}
          </span>
        ),
      },
      {
        accessorKey: 'validation_errors',
        header: text.validationErrors,
        cell: ({ row }) =>
          row.original.validation_errors.length === 0 ? (
            <Badge variant="outline">{text.noErrors}</Badge>
          ) : (
            <ul className="space-y-1 text-xs">
              {row.original.validation_errors.map((item) => (
                <li key={`${item.code}-${item.field ?? ''}`} className="text-destructive">
                  {item.code}
                  {item.field ? ` · ${item.field}` : ''}
                </li>
              ))}
            </ul>
          ),
      },
    ],
    [locale, text],
  )

  const actions = availableActions(status)

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <Button variant="outline" size="sm" onClick={() => setBlockingOnly((current) => !current)}>
          {blockingOnly ? text.showAll : text.showBlockingOnly}
        </Button>
        <div className="flex gap-2">
          {actions.map((action) => (
            <Button
              key={action}
              size="sm"
              variant={action === 'approve' ? 'default' : 'outline'}
              disabled={busy}
              onClick={() => {
                if (action === 'reject') {
                  setReasonAction(action)
                  setReason('')
                  setError(false)
                  return
                }
                onTransition(action)
              }}
            >
              {text[action]}
            </Button>
          ))}
        </div>
      </div>

      {visibleRows.length === 0 ? (
        <p className="text-muted-foreground py-8 text-center text-sm">{text.noRows}</p>
      ) : (
        <DataTable
          columns={columns}
          data={visibleRows}
          state="ready"
          nextCursor={null}
          onNext={() => {}}
          onPrev={() => {}}
          canPrev={false}
          locale={locale}
        />
      )}

      {reasonAction ? (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
          <form
            className="w-full max-w-sm rounded-xl bg-background p-4"
            onSubmit={(event) => {
              event.preventDefault()
              if (!reason.trim()) {
                setError(true)
                return
              }
              onTransition(reasonAction, reason.trim())
              setReasonAction(null)
              setReason('')
            }}
          >
            <h3 className="text-base font-medium">{reasonAction === 'reject' ? text.rejectTitle : text.cancelTitle}</h3>
            {error ? <p className="text-destructive mt-2 text-sm" role="alert">{text.reasonRequired}</p> : null}
            <div className="mt-4 grid gap-2">
              <Label htmlFor="import-transition-reason">{text.reason}</Label>
              <Input
                id="import-transition-reason"
                value={reason}
                onChange={(event) => setReason(event.target.value)}
                aria-invalid={error || undefined}
              />
            </div>
            <div className="mt-4 flex justify-end gap-2">
              <Button type="button" variant="outline" onClick={() => setReasonAction(null)}>
                {text.cancel}
              </Button>
              <Button type="submit">{text.execute}</Button>
            </div>
          </form>
        </div>
      ) : null}
    </div>
  )
}

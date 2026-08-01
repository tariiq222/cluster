import { useState } from 'react'
import { useLocale } from '../../../app/session-context'
import { formatNumber } from '../../../i18n'
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader } from '@/components/ui/card'
import { importsCopy } from '../imports-copy'

export function CommitStep({
  totalRows,
  validRows,
  errorRows,
  status,
  onApply,
  onCancelImport,
  busy,
}: {
  totalRows: number
  validRows: number
  errorRows: number
  status: string
  onApply: () => void
  onCancelImport: () => void
  busy?: boolean
}) {
  const locale = useLocale()
  const text = importsCopy[locale]
  const [open, setOpen] = useState(false)

  return (
    <div className="space-y-4">
      <Card>
        <CardHeader>
          <h2 className="text-base font-medium leading-snug">{text.commitSummary}</h2>
        </CardHeader>
        <CardContent>
          <dl className="grid gap-x-6 gap-y-3 sm:grid-cols-3">
            <div>
              <dt className="text-muted-foreground text-sm">{text.total}</dt>
              <dd className="mt-1 text-sm font-medium">{formatNumber(totalRows, locale)}</dd>
            </div>
            <div>
              <dt className="text-muted-foreground text-sm">{text.valid}</dt>
              <dd className="mt-1 text-sm font-medium">{formatNumber(validRows, locale)}</dd>
            </div>
            <div>
              <dt className="text-muted-foreground text-sm">{text.errors}</dt>
              <dd className="mt-1 text-sm font-medium">{formatNumber(errorRows, locale)}</dd>
            </div>
          </dl>
        </CardContent>
      </Card>

      <div className="flex gap-2">
        {status === 'approved' ? (
          <Button onClick={() => setOpen(true)} disabled={busy}>
            {text.apply}
          </Button>
        ) : null}
        {status === 'validated' || status === 'approved' ? (
          <Button variant="outline" onClick={onCancelImport} disabled={busy}>
            {text.cancel}
          </Button>
        ) : null}
      </div>

      <AlertDialog open={open} onOpenChange={setOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{text.commitConfirm}</AlertDialogTitle>
            <AlertDialogDescription>{text.commitConfirmBody}</AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>{text.cancel}</AlertDialogCancel>
            <AlertDialogAction onClick={onApply} disabled={busy}>
              {busy ? text.executing : text.apply}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}

import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Plus } from 'lucide-react'
import type { ColumnDef } from '@tanstack/react-table'
import { useLocale, useSessionToken } from '../../../app/session-context'
import { useJobTitles } from '../../../api/hooks'
import { requestInit, stateFromError, unwrap } from '../../../api/http'
import * as generated from '../../../api/generated/cluster'
import { DataTable } from '@/components/data-table'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet'
import { organizationCopy } from '../organization-copy'
import { CODE_PATTERN, useCapabilities } from '../organization-utils'

export function JobTitlesTab() {
  const locale = useLocale()
  const text = organizationCopy[locale]
  const capabilities = useCapabilities()
  const jobTitlesQuery = useJobTitles()
  const [sheetOpen, setSheetOpen] = useState(false)
  const [notice, setNotice] = useState<string | null>(null)

  const canManage = capabilities.includes('organization.job_title.manage')
  const jobTitles = (jobTitlesQuery.data as generated.JobTitleCollection | undefined)?.items ?? []

  const state = jobTitlesQuery.isError
    ? stateFromError(jobTitlesQuery.error)
    : jobTitlesQuery.isLoading ? 'loading'
    : 'ready'

  const columns = useMemo<ColumnDef<generated.JobTitle>[]>(
    () => [
      {
        accessorKey: 'title_ar',
        header: text.jobTitle,
        cell: ({ row }) => <span className="font-medium">{row.original.title_ar}</span>,
      },
      {
        accessorKey: 'code',
        header: text.identifier,
        cell: ({ row }) => <span className="font-mono text-sm" dir="ltr">{row.original.code}</span>,
      },
      {
        accessorKey: 'status',
        header: text.status,
        cell: ({ row }) => (
          <Badge variant="outline">{row.original.status === 'active' ? text.active : text.inactive}</Badge>
        ),
      },
    ],
    [text],
  )

  return (
    <div className="space-y-4">
      {notice ? <p role="status">{notice}</p> : null}
      <div className="flex justify-end">
        {canManage ? (
          <Button size="sm" onClick={() => setSheetOpen(true)}>
            <Plus aria-hidden="true" />
            {text.addJobTitle}
          </Button>
        ) : null}
      </div>
      <DataTable
        columns={columns}
        data={jobTitles}
        state={state}
        nextCursor={null}
        onNext={() => {}}
        onPrev={() => {}}
        canPrev={false}
        locale={locale}
        empty={<p className="text-muted-foreground py-8 text-center text-sm">{text.noJobTitles}</p>}
      />
      <JobTitleSheet open={sheetOpen} onClose={() => setSheetOpen(false)} onSaved={() => {
        setSheetOpen(false)
        setNotice(text.jobTitleSaved)
      }} />
    </div>
  )
}

function JobTitleSheet({
  open,
  onClose,
  onSaved,
}: {
  open: boolean
  onClose: () => void
  onSaved: () => void
}) {
  const locale = useLocale()
  const token = useSessionToken()
  const text = organizationCopy[locale]
  const queryClient = useQueryClient()
  const [code, setCode] = useState('')
  const [title, setTitle] = useState('')
  const [failure, setFailure] = useState<'validation' | 'save' | null>(null)

  useEffect(() => {
    if (!open) return
    setCode('')
    setTitle('')
    setFailure(null)
  }, [open])

  const mutation = useMutation({
    mutationFn: async ({ nextCode, nextTitle }: { nextCode: string; nextTitle: string }) =>
      unwrap<generated.JobTitle>(
        await generated.createJobTitle(
          { code: nextCode, title_ar: nextTitle },
          requestInit(token, { command: true, idempotency: 'job-title' }),
        ),
      ),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['job-titles'] })
      onSaved()
    },
    onError: () => setFailure('save'),
  })
  const submitting = mutation.isPending

  return (
    <Sheet open={open} onOpenChange={(next) => { if (!next && !submitting) onClose() }}>
      <SheetContent>
        <SheetHeader>
          <SheetTitle>{text.addJobTitle}</SheetTitle>
          <SheetDescription>{text.jobTitle}</SheetDescription>
        </SheetHeader>
        <form
          className="grid gap-4"
          onSubmit={(event) => {
            event.preventDefault()
            if (!title.trim() || !CODE_PATTERN.test(code)) {
              setFailure('validation')
              return
            }
            setFailure(null)
            mutation.mutate({ nextCode: code, nextTitle: title.trim() })
          }}
          noValidate
        >
          {failure === 'validation' ? (
            <p className="text-destructive text-sm" role="alert">{text.validation}</p>
          ) : failure === 'save' ? (
            <p className="text-destructive text-sm" role="alert">{text.saveError}</p>
          ) : null}
          <div className="grid gap-2">
            <Label htmlFor="org-job-title-code">{text.code}</Label>
            <Input
              id="org-job-title-code"
              dir="ltr"
              value={code}
              aria-invalid={failure === 'validation' || undefined}
              onChange={(event) => setCode(event.target.value.toUpperCase())}
            />
            <p className="text-muted-foreground text-xs">{text.codeHint}</p>
          </div>
          <div className="grid gap-2">
            <Label htmlFor="org-job-title-name">{text.jobTitle}</Label>
            <Input
              id="org-job-title-name"
              value={title}
              aria-invalid={failure === 'validation' || undefined}
              onChange={(event) => setTitle(event.target.value)}
            />
          </div>
          <div className="flex justify-end gap-2">
            <Button type="button" variant="outline" onClick={onClose} disabled={submitting}>
              {text.cancel}
            </Button>
            <Button type="submit" disabled={submitting}>
              {submitting ? text.saving : text.save}
            </Button>
          </div>
        </form>
      </SheetContent>
    </Sheet>
  )
}

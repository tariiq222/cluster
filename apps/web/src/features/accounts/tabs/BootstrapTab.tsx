import { useMemo, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useLocale, useSessionToken } from '../../../app/session-context'
import { ApiError, stateFromError } from '../../../api/http'
import * as access from '../../../api/access'
import { formatDate } from '../../../i18n'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Form, FormControl, FormDescription, FormField, FormItem, FormLabel, FormMessage } from '@/components/ui/form'
import { Textarea } from '@/components/ui/textarea'
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
import { ConflictState, ErrorState, StaleState } from '@/components/states'
import { bootstrapCopy } from '../accounts-copy'

const MAX_REASON_LENGTH = 500

function deriveMutationState(error: unknown): 'conflict' | 'stale' | 'error' | null {
  const derived = stateFromError(error)
  if (derived === 'conflict') return 'conflict'
  if (derived === 'stale') return 'stale'
  if (error !== null) return 'error'
  return null
}

export function BootstrapTab({
  bootstrap,
  onRefresh,
}: {
  bootstrap: access.BootstrapState
  onRefresh: () => void
}) {
  const locale = useLocale()
  const csrfToken = useSessionToken()
  const text = bootstrapCopy[locale]
  const queryClient = useQueryClient()
  const [confirmOpen, setConfirmOpen] = useState(false)

  const schema = useMemo(
    () =>
      z.object({
        reason: z
          .string()
          .trim()
          .min(1, text.reasonRequired)
          .max(MAX_REASON_LENGTH, text.reasonTooLong),
      }),
    [text],
  )

  const form = useForm<{ reason: string }>({
    resolver: zodResolver(schema),
    defaultValues: { reason: '' },
  })

  const mutation = useMutation({
    mutationFn: (reason: string) => {
      if (bootstrap.version === null) {
        throw new ApiError(409, {
          type: 'about:blank',
          title: 'Bootstrap version unavailable',
          status: 409,
        })
      }
      return access.completeBootstrap(reason, bootstrap.version, csrfToken)
    },
    onSuccess: (completed) => {
      setConfirmOpen(false)
      /* Update synchronously so the shell drops the tab immediately, then
       * refetch for the authoritative state. */
      queryClient.setQueryData(['authorization-bootstrap'], completed)
      onRefresh()
    },
    onSettled: () => setConfirmOpen(false),
  })

  const submitting = mutation.isPending
  const mutationState = deriveMutationState(mutation.error ?? null)

  /* A missing version means the observed ETag was not preserved: the current
   * state must be refreshed before completion can proceed. */
  if (bootstrap.version === null) {
    return <StaleState onRefresh={onRefresh} locale={locale} />
  }

  return (
    <div className="space-y-4 min-w-0">
      <h2 className="text-xl font-semibold tracking-tight">{text.title}</h2>
      <div className="flex flex-wrap items-center gap-2">
        <Badge variant="outline">{text.pendingStatus}</Badge>
        {bootstrap.expiresAt ? (
          <span className="text-muted-foreground text-sm break-words">
            {text.expiresAt} <span className="break-all" dir="ltr">{formatDate(bootstrap.expiresAt, locale)}</span>
          </span>
        ) : null}
      </div>

      {mutationState === 'conflict' ? (
        <ConflictState onRetry={onRefresh} locale={locale} />
      ) : mutationState === 'stale' ? (
        <StaleState onRefresh={onRefresh} locale={locale} />
      ) : mutationState === 'error' ? (
        <ErrorState locale={locale} onRetry={onRefresh} />
      ) : null}

      <Form {...form}>
        <form
          className="grid gap-4"
          onSubmit={(event) => {
            event.preventDefault()
            void form.handleSubmit(() => setConfirmOpen(true))()
          }}
          noValidate
        >
          <FormField
            control={form.control}
            name="reason"
            render={({ field }) => (
              <FormItem>
                <FormLabel>{text.reason}</FormLabel>
                <FormControl>
                  <Textarea
                    aria-label={text.reason}
                    maxLength={MAX_REASON_LENGTH}
                    disabled={submitting}
                    {...field}
                  />
                </FormControl>
                <FormDescription>{text.reasonHint}</FormDescription>
                <FormMessage role="alert" />
              </FormItem>
            )}
          />
          <Button type="submit" disabled={submitting} className="w-fit">
            {submitting ? text.completing : text.complete}
          </Button>
        </form>
      </Form>

      <AlertDialog
        open={confirmOpen}
        onOpenChange={(next) => { if (!next && !submitting) setConfirmOpen(false) }}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{text.confirmTitle}</AlertDialogTitle>
            <AlertDialogDescription>{text.confirmBody}</AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={submitting}>{text.cancel}</AlertDialogCancel>
            <AlertDialogAction
              disabled={submitting}
              onClick={() => {
                if (bootstrap.version === null) return
                mutation.mutate(form.getValues('reason').trim())
              }}
            >
              {submitting ? text.completing : text.confirm}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}


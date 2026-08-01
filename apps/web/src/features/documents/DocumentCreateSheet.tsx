import { useMemo, useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import * as generated from '../../api/generated/cluster'
import { useLocale, useSessionToken } from '../../app/session-context'
import { usePrincipal } from '../../app/principal-context'
import { ApiError, requestInit, unwrap } from '../../api/http'
import { Button } from '@/components/ui/button'
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/components/ui/form'
import { Input } from '@/components/ui/input'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
} from '@/components/ui/sheet'
import { documentsCopy } from './documents-copy'

export function DocumentCreateSheet({
  open,
  onOpenChange,
  onCreated,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  onCreated: () => void
}) {
  const locale = useLocale()
  const csrfToken = useSessionToken()
  const principal = usePrincipal()
  const t = documentsCopy[locale]
  const [submitting, setSubmitting] = useState(false)

  const ownerUnitId = principal.effectiveScope?.scopeType === 'facility' ? principal.effectiveScope.scopeId : ''
  const canCreate = ownerUnitId.length > 0

  const schema = useMemo(
    () =>
      z.object({
        title: z.string().trim().min(1, t.titleRequired).max(255, t.titleRequired),
        classification: z.string(),
        restrictionPolicyKey: z.string().max(128),
      }),
    [t],
  )

  const form = useForm<{ title: string; classification: string; restrictionPolicyKey: string }>({
    resolver: zodResolver(schema),
    defaultValues: { title: '', classification: 'internal', restrictionPolicyKey: 'restricted' },
  })

  const submit = form.handleSubmit(async (values) => {
    if (!canCreate) {
      form.setError('title', { message: t.ownerMissing })
      return
    }
    setSubmitting(true)
    try {
      const input: generated.DocumentCreate = {
        title: values.title,
        classification: values.classification as generated.Classification,
        owner_organization_unit_id: ownerUnitId,
        restriction_policy_key: values.restrictionPolicyKey.trim() || 'restricted',
      }
      unwrap<generated.Entity>(
        await generated.createDocument(input, requestInit(csrfToken, { command: true, idempotency: 'document-create' })),
      )
      form.reset()
      onOpenChange(false)
      onCreated()
    } catch (cause) {
      if (cause instanceof ApiError && cause.status === 403) {
        form.setError('title', { message: t.forbidden })
      } else {
        form.setError('title', { message: t.createError })
      }
    } finally {
      setSubmitting(false)
    }
  })

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent>
        <SheetHeader>
          <SheetTitle>{t.createTitle}</SheetTitle>
          <SheetDescription>{t.pageDescription}</SheetDescription>
        </SheetHeader>
        {!canCreate ? (
          <p className="text-muted-foreground text-sm">{t.ownerMissing}</p>
        ) : (
          <Form {...form}>
            <form onSubmit={(event) => void submit(event)} className="grid gap-4">
              <FormField
                control={form.control}
                name="title"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel htmlFor="document-create-title">{t.createTitleLabel}</FormLabel>
                    <FormControl>
                      <Input id="document-create-title" maxLength={255} placeholder={t.createTitlePlaceholder} {...field} />
                    </FormControl>
                    <FormMessage role="alert" />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="classification"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel htmlFor="document-create-classification">{t.classificationLabel}</FormLabel>
                    <Select value={field.value} onValueChange={field.onChange}>
                      <FormControl>
                        <SelectTrigger id="document-create-classification">
                          <SelectValue />
                        </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                        <SelectItem value="public">{t.classificationPublic}</SelectItem>
                        <SelectItem value="internal">{t.classificationInternal}</SelectItem>
                        <SelectItem value="confidential">{t.classificationConfidential}</SelectItem>
                        <SelectItem value="top_secret">{t.classificationTopSecret}</SelectItem>
                      </SelectContent>
                    </Select>
                    <FormMessage role="alert" />
                  </FormItem>
                )}
              />
              <div className="grid gap-2">
                <label htmlFor="document-create-owner" className="text-sm font-medium">
                  {t.ownerLabel}
                </label>
                <Input id="document-create-owner" value={ownerUnitId} readOnly disabled />
                <p className="text-muted-foreground text-xs">{t.ownerHelp}</p>
              </div>
              <FormField
                control={form.control}
                name="restrictionPolicyKey"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel htmlFor="document-create-restriction">{t.restrictionPolicyLabel}</FormLabel>
                    <FormControl>
                      <Input id="document-create-restriction" maxLength={128} {...field} />
                    </FormControl>
                    <p className="text-muted-foreground text-xs">{t.restrictionPolicyHelp}</p>
                    <FormMessage role="alert" />
                  </FormItem>
                )}
              />
              <Button type="submit" disabled={submitting}>
                {submitting ? t.creating : t.create}
              </Button>
            </form>
          </Form>
        )}
      </SheetContent>
    </Sheet>
  )
}

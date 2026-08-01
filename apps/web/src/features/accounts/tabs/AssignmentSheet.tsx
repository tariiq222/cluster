import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useLocale, useSessionToken } from '../../../app/session-context'
import { ApiError } from '../../../api/http'
import * as access from '../../../api/access'
import * as generated from '../../../api/generated/cluster'
import { Button } from '@/components/ui/button'
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/components/ui/form'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet'
import { roleCopy } from '../accounts-copy'
import {
  ScopeTargetCombobox,
  type EffectiveScope,
  type ScopeSelection,
} from './ScopeTargetCombobox'

const SCOPE_TYPES = ['cluster', 'facility', 'unit'] as const

function accountLabel(account: generated.UserAccount, locale: 'ar' | 'en'): string {
  return locale === 'en' && account.display_name_en
    ? account.display_name_en
    : account.display_name_ar
}

function roleLabel(role: generated.AuthorizationRole, locale: 'ar' | 'en'): string {
  return locale === 'ar'
    ? (role.name_ar ?? role.name_en ?? role.code)
    : (role.name_en ?? role.name_ar ?? role.code)
}

export function AssignmentSheet({
  open,
  accounts,
  roles,
  effectiveScope,
  onClose,
  onSaved,
}: {
  open: boolean
  accounts: generated.UserAccount[]
  roles: generated.AuthorizationRole[]
  effectiveScope: EffectiveScope | null
  onClose: () => void
  onSaved: () => void
}) {
  const locale = useLocale()
  const csrfToken = useSessionToken()
  const text = roleCopy[locale]
  const queryClient = useQueryClient()
  const [scopeSelection, setScopeSelection] = useState<ScopeSelection | null>(null)
  const [alertMessage, setAlertMessage] = useState<string | null>(null)

  const schema = useMemo(
    () =>
      z
        .object({
          accountId: z.string().min(1, text.assignmentValidation),
          roleId: z.string().min(1, text.assignmentValidation),
          scopeType: z.enum(SCOPE_TYPES, { message: text.assignmentValidation }),
          startAt: z.string().min(1, text.assignmentStartRequired),
          endAt: z.string().optional(),
        })
        .superRefine((values, context) => {
          if (values.endAt && values.endAt.trim() !== '') {
            const start = new Date(values.startAt).getTime()
            const end = new Date(values.endAt).getTime()
            if (Number.isNaN(start) || Number.isNaN(end) || end <= start) {
              context.addIssue({
                code: z.ZodIssueCode.custom,
                path: ['endAt'],
                message: text.assignmentWindowInvalid,
              })
            }
          }
        }),
    [text],
  )

  type AssignmentValues = z.infer<typeof schema>

  const form = useForm<AssignmentValues>({
    resolver: zodResolver(schema),
    defaultValues: { accountId: '', roleId: '', scopeType: 'unit', startAt: '', endAt: '' },
  })

  useEffect(() => {
    if (!open) return
    setScopeSelection(null)
    setAlertMessage(null)
    form.reset({ accountId: '', roleId: '', scopeType: 'unit', startAt: '', endAt: '' })
  }, [open, form])

  const scopeType = form.watch('scopeType')

  const mutation = useMutation({
    mutationFn: async (values: AssignmentValues) => {
      if (!scopeSelection) throw new Error('Scope is not selected')
      return access.createAssignment(
        {
          subject_user_id: values.accountId,
          role_id: values.roleId,
          scope_type: values.scopeType,
          scope_id: scopeSelection.scopeId,
          start_at: new Date(values.startAt).toISOString(),
          ...(values.endAt && values.endAt.trim() !== ''
            ? { end_at: new Date(values.endAt).toISOString() }
            : {}),
        },
        csrfToken,
      )
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['access-admin'] })
      onSaved()
    },
    onError: (caught) => {
      setAlertMessage(
        caught instanceof ApiError ? caught.message : text.assignmentError,
      )
    },
  })

  return (
    <Sheet open={open} onOpenChange={(next) => { if (!next && !mutation.isPending) onClose() }}>
      <SheetContent>
        <SheetHeader>
          <SheetTitle>{text.addAssignmentTitle}</SheetTitle>
          <SheetDescription>{text.assignmentIntro}</SheetDescription>
        </SheetHeader>
        {alertMessage ? (
          <p className="text-destructive text-sm" role="alert">{alertMessage}</p>
        ) : null}
        {accounts.length === 0 ? (
          <p className="text-muted-foreground text-sm">{text.assignmentAccountMissing}</p>
        ) : roles.length === 0 ? (
          <p className="text-muted-foreground text-sm">{text.assignmentRoleMissing}</p>
        ) : (
          <Form {...form}>
            <form
              className="grid gap-4"
              onSubmit={(event) => {
                event.preventDefault()
                setAlertMessage(null)
                void form.handleSubmit((values) => {
                  if (!scopeSelection) {
                    setAlertMessage(text.assignmentValidation)
                    return
                  }
                  mutation.mutate(values)
                })()
              }}
              noValidate
            >
              <FormField
                control={form.control}
                name="accountId"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel htmlFor="assignment-account">{text.assignmentAccount}</FormLabel>
                    <Select value={field.value} onValueChange={field.onChange}>
                      <FormControl>
                        <SelectTrigger id="assignment-account">
                          <SelectValue />
                        </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                        {accounts.map((account) => (
                          <SelectItem key={account.id} value={account.id}>
                            {accountLabel(account, locale)}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                    <FormMessage role="alert" />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="roleId"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel htmlFor="assignment-role">{text.assignmentRoleLabel}</FormLabel>
                    <Select value={field.value} onValueChange={field.onChange}>
                      <FormControl>
                        <SelectTrigger id="assignment-role">
                          <SelectValue />
                        </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                        {roles.map((role) => (
                          <SelectItem key={role.id} value={role.id}>
                            {roleLabel(role, locale)}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                    <FormMessage role="alert" />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="scopeType"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel htmlFor="assignment-scope-type">{text.assignmentScopeTypeLabel}</FormLabel>
                    <Select value={field.value} onValueChange={(next) => {
                      field.onChange(next)
                      setScopeSelection(null)
                    }}>
                      <FormControl>
                        <SelectTrigger id="assignment-scope-type">
                          <SelectValue />
                        </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                        {SCOPE_TYPES.map((type) => (
                          <SelectItem key={type} value={type}>
                            {text[type === 'cluster' ? 'scopeCluster' : type === 'facility' ? 'scopeFacility' : 'scopeUnit']}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                    <FormMessage role="alert" />
                  </FormItem>
                )}
              />
              <FormItem>
                <FormLabel htmlFor="assignment-scope-target">{text.assignmentScopeTargetLabel}</FormLabel>
                <ScopeTargetCombobox
                  triggerId="assignment-scope-target"
                  scopeType={scopeType}
                  effectiveScope={effectiveScope}
                  selection={scopeSelection}
                  onSelect={setScopeSelection}
                />
              </FormItem>
              <FormField
                control={form.control}
                name="startAt"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel htmlFor="assignment-start">{text.assignmentStartLabel}</FormLabel>
                    <FormControl>
                      <Input id="assignment-start" type="datetime-local" {...field} />
                    </FormControl>
                    <p className="text-muted-foreground text-xs">{text.assignmentStartHint}</p>
                    <FormMessage role="alert" />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="endAt"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel htmlFor="assignment-end">{text.assignmentEndLabel}</FormLabel>
                    <FormControl>
                      <Input id="assignment-end" type="datetime-local" {...field} />
                    </FormControl>
                    <p className="text-muted-foreground text-xs">{text.assignmentEndHint}</p>
                    <FormMessage role="alert" />
                  </FormItem>
                )}
              />
              <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" onClick={onClose} disabled={mutation.isPending}>
                  {text.cancel}
                </Button>
                <Button type="submit" disabled={mutation.isPending}>
                  {mutation.isPending ? text.saving : text.assignmentCreate}
                </Button>
              </div>
            </form>
          </Form>
        )}
      </SheetContent>
    </Sheet>
  )
}

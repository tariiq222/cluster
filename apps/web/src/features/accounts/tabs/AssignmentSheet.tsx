import { useCallback, useEffect, useMemo, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useLocale, useSessionToken } from '../../../app/session-context'
import { ApiError } from '../../../api/http'
import * as access from '../../../api/access'
import * as generated from '../../../api/generated/cluster'
import { Button } from '@/components/ui/button'
import { Form, FormControl, FormDescription, FormField, FormItem, FormLabel, FormMessage } from '@/components/ui/form'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Sheet, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet'
import { roleCopy } from '../accounts-copy'
import {
  CursorPickerCombobox,
  type CursorCollection,
} from './CursorPickerCombobox'
import {
  ScopeTargetCombobox,
  type EffectiveScope,
  type ScopeSelection,
} from './ScopeTargetCombobox'
import { AccessSheetSurface } from '../access-overlays'

const SCOPE_TYPES = ['cluster', 'facility', 'unit'] as const

/*
 * Narrow role picker contract. The role picker only renders a localized
 * label, so it only needs `id` plus the optional `code`, `name_ar`, and
 * `name_en` string fields. Both enriched role rows (`RoleWithCapabilities`)
 * and the live admin resource rows (`ResourceRow`) carry those fields on
 * the wire; they are projected into this shape below so the picker is
 * never typed against the broader `generated.AuthorizationRole` contract
 * nor forced into an `as unknown as` cast.
 */
export interface RolePickerOption {
  id: string
  code?: string
  name_ar?: string
  name_en?: string
}

function accountLabel(account: generated.UserAccount, locale: 'ar' | 'en'): string {
  return locale === 'en' && account.display_name_en
    ? account.display_name_en
    : account.display_name_ar
}

function roleLabel(role: RolePickerOption, locale: 'ar' | 'en'): string {
  return locale === 'ar'
    ? (role.name_ar ?? role.name_en ?? role.code ?? role.id)
    : (role.name_en ?? role.name_ar ?? role.code ?? role.id)
}

async function loadAccountsPage(cursor: string | null): Promise<CursorCollection<generated.UserAccount>> {
  return access.listAccounts(cursor ?? undefined)
}

/*
 * Wire → picker projection. The role rows returned by both the enriched
 * and the bare admin resource endpoints are `Record<string, unknown>`
 * intersections, so individual string fields are typed `unknown`. This
 * helper narrows each known label field via a `typeof === 'string'`
 * guard and never claims the row is a `generated.AuthorizationRole`.
 */
function projectRolePickerOption(row: access.ResourceRow | access.RoleWithCapabilities): RolePickerOption {
  const code = typeof row.code === 'string' ? row.code : undefined
  const nameAr = typeof row.name_ar === 'string' ? row.name_ar : undefined
  const nameEn = typeof row.name_en === 'string' ? row.name_en : undefined
  return {
    id: row.id,
    ...(code !== undefined ? { code } : {}),
    ...(nameAr !== undefined ? { name_ar: nameAr } : {}),
    ...(nameEn !== undefined ? { name_en: nameEn } : {}),
  }
}

async function loadRolesPage(
  cursor: string | null,
  enriched: boolean,
): Promise<CursorCollection<RolePickerOption>> {
  if (enriched) {
    const collection = await access.listRolesWithCapabilities(cursor ?? undefined)
    return {
      items: collection.items.map(projectRolePickerOption),
      next_cursor: collection.next_cursor,
    }
  }
  const collection = await access.listAdminResources('roles', cursor ?? undefined)
  return {
    items: collection.items.map(projectRolePickerOption),
    next_cursor: collection.next_cursor,
  }
}

export function AssignmentSheet({
  open,
  effectiveScope,
  enrichRoles = false,
  onClose,
  onSaved,
}: {
  open: boolean
  effectiveScope: EffectiveScope | null
  enrichRoles?: boolean
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

  /*
   * Stable role loader for the picker. The cursor combobox rebuilds its
   * effect graph when `loadPage` identity changes; an inline arrow would
   * tear down the loaded page set on every parent render. The callback
   * is bound only to `enrichRoles`, which is a boolean prop and therefore
   * changes at most when the parent flips the enrichment mode.
   */
  const loadRolesPageForPicker = useCallback(
    (cursor: string | null) => loadRolesPage(cursor, enrichRoles),
    [enrichRoles],
  )

  const mutation = useMutation({
    mutationFn: async (values: AssignmentValues) => {
      /*
       * Defence in depth: the combobox already clears the selection when
       * scopeType changes, but a stale resolved promise or a keyboard
       * navigation race could still leave a mismatched pair here. The
       * scope_id is bound to the row whose scope_type the server
       * authority stamped on it; submitting with a mismatched pair would
       * create an assignment under a wrong scope, so reject client-side.
       */
      if (!scopeSelection || scopeSelection.scopeId === '') {
        throw new Error('Scope is not selected')
      }
      if (scopeSelection.scopeType !== values.scopeType) {
        throw new Error('Selected scope type no longer matches the form scope type')
      }
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
      <AccessSheetSurface>
        <SheetHeader>
          <SheetTitle>{text.addAssignmentTitle}</SheetTitle>
          <SheetDescription>{text.assignmentIntro}</SheetDescription>
        </SheetHeader>
        {alertMessage ? (
          <p className="text-destructive text-sm" role="alert">{alertMessage}</p>
        ) : null}
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
                  <FormControl>
                    <CursorPickerCombobox<generated.UserAccount>
                      triggerId="assignment-account"
                      selectedId={field.value}
                      onSelect={(account) => field.onChange(account.id)}
                      loadPage={loadAccountsPage}
                      getLabel={accountLabel}
                      invalid={!field.value}
                      ariaLabel={text.assignmentAccountPickerLabel}
                      searchPlaceholder={text.assignmentAccountPickerPlaceholder}
                      emptyLabel={text.assignmentAccountPickerEmpty}
                      deniedLabel={text.assignmentAccountPickerDenied}
                      errorLabel={text.assignmentAccountPickerError}
                      loadingLabel={text.assignmentAccountPickerLoading}
                      loadMoreLabel={text.assignmentAccountLoadMore}
                    />
                  </FormControl>
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
                  <FormControl>
                    <CursorPickerCombobox<RolePickerOption>
                      triggerId="assignment-role"
                      selectedId={field.value}
                      onSelect={(role) => field.onChange(role.id)}
                      loadPage={loadRolesPageForPicker}
                      getLabel={roleLabel}
                      invalid={!field.value}
                      ariaLabel={text.assignmentRolePickerLabel}
                      searchPlaceholder={text.assignmentRolePickerPlaceholder}
                      emptyLabel={text.assignmentRolePickerEmpty}
                      deniedLabel={text.assignmentRolePickerDenied}
                      errorLabel={text.assignmentRolePickerError}
                      loadingLabel={text.assignmentRolePickerLoading}
                      loadMoreLabel={text.assignmentRoleLoadMore}
                    />
                  </FormControl>
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
                  <FormDescription>{text.assignmentStartHint}</FormDescription>
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
                  <FormDescription>{text.assignmentEndHint}</FormDescription>
                  <FormMessage role="alert" />
                </FormItem>
              )}
            />
            <div className="flex flex-wrap justify-end gap-2">
              <Button type="button" variant="outline" onClick={onClose} disabled={mutation.isPending}>
                {text.cancel}
              </Button>
              <Button type="submit" disabled={mutation.isPending}>
                {mutation.isPending ? text.saving : text.assignmentCreate}
              </Button>
            </div>
          </form>
        </Form>
      </AccessSheetSurface>
    </Sheet>
  )
}

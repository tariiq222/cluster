import { useCallback, useMemo, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { ArrowLeft, ArrowRight } from 'lucide-react'
import { useLocale, useSessionToken } from '../../app/session-context'
import { usePrincipal } from '../../app/principal-context'
import { useNavigate } from '../../app/navigation-context'
import { ApiError } from '../../api/http'
import * as access from '../../api/access'
import * as generated from '../../api/generated/cluster'
import { PageHeader, PageLayout } from '@/components/page-layout'
import { TwoRegionFormLayout, FormActionStack, FormSection, ReviewSummary } from '@/components/form-page-layout'
import { Button } from '@/components/ui/button'
import {
  Form,
  FormControl,
  FormDescription,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from '@/components/ui/form'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { DeniedState } from '@/components/states'
import { roleCopy } from './accounts-copy'
import {
  CursorPickerCombobox,
  type CursorCollection,
} from './tabs/CursorPickerCombobox'
import {
  ScopeTargetCombobox,
  type EffectiveScope,
  type ScopeSelection,
} from './tabs/ScopeTargetCombobox'

const SCOPE_TYPES = ['cluster', 'facility', 'unit'] as const

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

/*
 * Full-page replacement for the former assignment Sheet
 * (route `/access/role-assignments/new`). The form keeps the cursor
 * pickers, the hierarchical scope search, and the date-window validation
 * unchanged; success returns to the role-assignments resource in the
 * roles workspace.
 */
export function AssignmentCreateScreen() {
  const locale = useLocale()
  const csrfToken = useSessionToken()
  const principal = usePrincipal()
  const navigate = useNavigate()
  const text = roleCopy[locale]
  const queryClient = useQueryClient()
  const [scopeSelection, setScopeSelection] = useState<ScopeSelection | null>(null)
  const [alertMessage, setAlertMessage] = useState<string | null>(null)

  const capabilities = principal.capabilities ?? []
  const canManageAssignments = capabilities.includes('authorization.assignment.manage')
  const canReadAssignments = capabilities.includes('authorization.assignment.read')
  const enrichRoles = canReadAssignments

  const effectiveScope: EffectiveScope | null = principal.effectiveScope
    ? {
        scopeType: principal.effectiveScope.scopeType,
        scopeId: principal.effectiveScope.scopeId,
        label: principal.effectiveScope.label,
      }
    : null

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

  const scopeType = form.watch('scopeType')

  const loadRolesPageForPicker = useCallback(
    (cursor: string | null) => loadRolesPage(cursor, enrichRoles),
    [enrichRoles],
  )

  const mutation = useMutation({
    mutationFn: async (values: AssignmentValues) => {
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
      navigate('/access?tab=roles&resource=role-assignments')
    },
    onError: (caught) => {
      setAlertMessage(
        caught instanceof ApiError ? caught.message : text.assignmentError,
      )
    },
  })

  if (!canManageAssignments) {
    return (
      <PageLayout data-testid="assignment-create-screen">
        <DeniedState locale={locale} />
      </PageLayout>
    )
  }

  const back = () => navigate('/access?tab=roles&resource=role-assignments')

  return (
    <PageLayout data-testid="assignment-create-screen">
      <div>
        <Button variant="ghost" size="sm" onClick={back} className="-ms-2">
          {locale === 'ar' ? (
            <ArrowRight aria-hidden="true" />
          ) : (
            <ArrowLeft aria-hidden="true" />
          )}
          {text.backToAssignments}
        </Button>
      </div>

      <PageHeader title={text.addAssignmentTitle} description={text.assignmentIntro} />

      <Form {...form}>
        <TwoRegionFormLayout
          testId="assignment-create-form"
          mainTestId="assignment-create-main"
          reviewTestId="assignment-create-review"
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
          main={
            <>
              <FormSection headingId="assignment-account-role-heading" title={text.assignmentDetailsHeading}>
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
                        <FormControl><SelectTrigger id="assignment-scope-type"><SelectValue /></SelectTrigger></FormControl>
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
              </FormSection>
              <FormSection headingId="assignment-window-heading" title={text.assignmentWindowHeading} divided>
                <FormField
                  control={form.control}
                  name="startAt"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel htmlFor="assignment-start">{text.assignmentStartLabel}</FormLabel>
                      <FormControl><Input id="assignment-start" type="datetime-local" {...field} /></FormControl>
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
                      <FormControl><Input id="assignment-end" type="datetime-local" {...field} /></FormControl>
                      <FormDescription>{text.assignmentEndHint}</FormDescription>
                      <FormMessage role="alert" />
                    </FormItem>
                  )}
                />
              </FormSection>
            </>
          }
          review={
            <>
              <FormSection headingId="assignment-review-heading" title={text.assignmentReviewHeading}>
                <ReviewSummary
                  rows={[
                    { label: text.assignmentAccount, value: form.watch('accountId'), empty: text.assignmentNotSelected, isolate: true },
                    { label: text.assignmentRoleLabel, value: form.watch('roleId'), empty: text.assignmentNotSelected, isolate: true },
                    { label: text.assignmentScopeTypeLabel, value: scopeType, isolate: true },
                    { label: text.assignmentScopeTargetLabel, value: scopeSelection?.label ?? scopeSelection?.scopeId ?? '', empty: text.assignmentNotSelected, isolate: true },
                    { label: text.assignmentStartLabel, value: form.watch('startAt'), empty: text.assignmentNotSelected, isolate: true },
                    { label: text.assignmentEndLabel, value: form.watch('endAt'), empty: text.assignmentOpenEnded, isolate: true },
                  ]}
                />
              </FormSection>
              {alertMessage ? <p className="text-destructive text-sm" role="alert">{alertMessage}</p> : null}
              <FormActionStack testId="assignment-create-actions">
                <Button type="button" variant="outline" onClick={back} disabled={mutation.isPending}>{text.cancel}</Button>
                <Button type="submit" disabled={mutation.isPending}>{mutation.isPending ? text.saving : text.assignmentCreate}</Button>
              </FormActionStack>
            </>
          }
        />
      </Form>
    </PageLayout>
  )
}

import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { ArrowLeft, ArrowRight, ShieldAlert, Search } from 'lucide-react'
import { useLocale, useSessionToken } from '../../app/session-context'
import { usePrincipal } from '../../app/principal-context'
import { useNavigate } from '../../app/navigation-context'
import { ApiError, stateFromError } from '../../api/http'
import * as access from '../../api/access'
import { PageHeader, PageLayout } from '@/components/page-layout'
import {
  TwoRegionFormLayout,
  FormActionStack,
  FormSection,
  ReviewSummary,
} from '@/components/form-page-layout'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Input } from '@/components/ui/input'
import {
  Form,
  FormControl,
  FormDescription,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from '@/components/ui/form'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { DeniedState, ErrorState, LoadingState } from '@/components/states'
import {
  roleCopy,
  accountsCopy,
  CAPABILITY_MODULE_LABELS,
  CAPABILITY_ACTION_LABELS,
  CAPABILITY_LABELS,
} from './accounts-copy'

const ROLE_CODE_PATTERN = /^[a-z][a-z0-9_.-]{1,95}$/

type CapabilityRow = access.NormalizedCapabilityRow

const EMPTY_CATALOG: CapabilityRow[] = []

function roleDisplayName(role: unknown, locale: 'ar' | 'en'): string {
  const value = role as Record<string, unknown> | null | undefined
  const nameAr = typeof value?.name_ar === 'string' ? value.name_ar : undefined
  const nameEn = typeof value?.name_en === 'string' ? value.name_en : undefined
  const code = typeof value?.code === 'string' ? value.code : undefined
  return locale === 'ar'
    ? (nameAr ?? nameEn ?? code ?? '')
    : (nameEn ?? nameAr ?? code ?? '')
}

function capabilityActionLabel(action: string | undefined, locale: 'ar' | 'en'): string {
  if (!action) return ''
  return CAPABILITY_ACTION_LABELS[action]?.[locale] ?? action
}

function capabilityModuleLabel(moduleCode: string | undefined, locale: 'ar' | 'en'): string {
  if (!moduleCode) return locale === 'ar' ? 'أخرى' : 'Other'
  return CAPABILITY_MODULE_LABELS[moduleCode]?.[locale] ?? moduleCode
}

/*
 * Human-readable label for a capability. The wire's full code (e.g.
 * `platform_settings.calendar.manage`) maps to a bilingual label so the
 * operator reads a permission name instead of a technical identifier;
 * an unknown code falls back to the raw value so nothing is hidden.
 */
function capabilityLabel(code: string | undefined, locale: 'ar' | 'en'): string {
  if (!code) return ''
  return CAPABILITY_LABELS[code]?.[locale] ?? code
}

function isSensitive(sensitivity: string | undefined): boolean {
  if (!sensitivity) return false
  return ['sensitive', 'critical', 'confidential', 'top_secret'].includes(sensitivity)
}

function roleStateFromError(error: unknown): 'loading' | 'ready' | 'denied' | 'error' {
  if (error instanceof ApiError) {
    if (error.status === 403 || error.status === 404) return 'denied'
  }
  return 'error'
}

interface RoleFormScreenProps {
  roleId?: string
}

/*
 * Full-page replacement for the former RoleSheet
 * (routes `/access/roles/new` and `/access/roles/:roleId/edit`).
 *
 * The capability catalog is loaded in full (every cursor page) so the
 * operator can search and group the complete set; the selected set is
 * never guessed — an unresolved edit set keeps submit disabled.
 */
export function RoleFormScreen({ roleId }: RoleFormScreenProps) {
  const locale = useLocale()
  const csrfToken = useSessionToken()
  const principal = usePrincipal()
  const navigate = useNavigate()
  const text = roleCopy[locale]
  const queryClient = useQueryClient()
  const editing = roleId !== undefined
  const capabilities = principal.capabilities ?? []
  const canManageRoles = capabilities.includes('authorization.role.manage')
  const canReadCapabilities = capabilities.includes('authorization.capability.read')
  const canReadAssignments = capabilities.includes('authorization.assignment.read')
  const canReconstructRole = canManageRoles && canReadCapabilities && canReadAssignments

  const [selectedCodes, setSelectedCodes] = useState<string[] | null>(null)
  const [alertMessage, setAlertMessage] = useState<string | null>(null)
  const [query, setQuery] = useState('')

  const schema = useMemo(
    () =>
      z.object({
        code: z
          .string()
          .min(1, text.roleError)
          .regex(ROLE_CODE_PATTERN, text.codeHint),
        name: z.string().min(1, text.roleError),
      }),
    [text],
  )

  const form = useForm<{ code: string; name: string }>({
    resolver: zodResolver(schema),
    defaultValues: { code: '', name: '' },
  })

  /*
   * The role itself is fetched by id so the page works after a direct
   * navigation or refresh. The wire row is a `Record<string, unknown>`
   * intersection; known string fields are narrowed with typeof guards.
   */
  const roleQuery = useQuery({
    queryKey: ['role-detail', roleId ?? null] as const,
    queryFn: () => (roleId ? access.getAdminResource('roles', roleId) : null),
    enabled: editing && canReconstructRole,
  })
  const roleRow = roleQuery.data as
    | (Record<string, unknown> & { id: string; lock_version?: number; code?: string; name_ar?: string; name_en?: string })
    | null

  const roleCodesQuery = useQuery({
    queryKey: ['role-detail-codes', roleId ?? null] as const,
    queryFn: () => (roleId ? access.listRoleCapabilityCodes(roleId) : []),
    enabled: editing && canReconstructRole && roleQuery.isSuccess,
  })

  const catalogQuery = useQuery({
    queryKey: ['access-capabilities-all'] as const,
    queryFn: () => access.listAllCapabilities(),
    enabled: canReconstructRole,
  })
  const catalogState = catalogQuery.isLoading
    ? 'loading'
    : catalogQuery.isError
      ? roleStateFromError(catalogQuery.error)
      : 'ready'

  /*
   * Seed the form and selection once the role (and its capability set)
   * resolves. `null` means the set is not known yet: submit stays
   * disabled until it is resolved from the enriched row or the
   * role-capabilities resource, so an unknown set can never silently
   * become `[]` on save.
   */
  useEffect(() => {
    if (!editing) {
      setSelectedCodes([])
      return
    }
    if (!roleQuery.isSuccess) return
    const row = roleQuery.data
    form.reset({
      code: typeof row?.code === 'string' ? row.code : '',
      name: roleDisplayName(row ?? {}, locale),
    })
    if (Array.isArray(row?.capability_codes)) {
      setSelectedCodes([...(row.capability_codes as string[])])
    } else if (roleCodesQuery.isSuccess) {
      setSelectedCodes([...roleCodesQuery.data])
    } else {
      setSelectedCodes(null)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [editing, roleQuery.isSuccess, roleCodesQuery.isSuccess])

  const catalog: CapabilityRow[] = catalogQuery.data ?? EMPTY_CATALOG
  const lockVersion = editing ? (roleRow?.lock_version ?? 0) : 0

  const groups = useMemo(() => {
    const byModule = new Map<string, CapabilityRow[]>()
    for (const capability of catalog) {
      const module = typeof capability.module_code === 'string' ? capability.module_code : 'other'
      const rows = byModule.get(module) ?? []
      rows.push(capability)
      byModule.set(module, rows)
    }
    return Array.from(byModule.entries())
      .map(([module, items]) => ({
        module,
        label: capabilityModuleLabel(module, locale),
        items: [...items].sort((a, b) =>
          String(a.code).localeCompare(String(b.code)),
        ),
      }))
      .sort((a, b) => a.label.localeCompare(b.label))
  }, [catalog, locale])

  const trimmedQuery = query.trim().toLowerCase()
  const visibleGroups = useMemo(() => {
    if (trimmedQuery.length === 0) return groups
    return groups
      .map((group) => ({
        ...group,
        items: group.items.filter((capability) => {
          const haystack = [
            capability.code,
            capability.module_code,
            capability.action,
            capability.group_label,
            capability.description,
            capabilityLabel(capability.code, locale),
            capabilityModuleLabel(capability.module_code, locale),
            capabilityActionLabel(capability.action, locale),
          ]
            .filter((value): value is string => typeof value === 'string')
            .join(' ')
            .toLowerCase()
          return haystack.includes(trimmedQuery)
        }),
      }))
      .filter((group) => group.items.length > 0)
  }, [groups, trimmedQuery, locale])

  const visibleCount = visibleGroups.reduce((total, group) => total + group.items.length, 0)

  const mutation = useMutation({
    mutationFn: async ({ nextCode, nextName }: { nextCode: string; nextName: string }) => {
      if (selectedCodes === null) {
        throw new Error('Capability set is not loaded')
      }
      if (editing && roleId && roleRow) {
        return access.updateAdminResource(
          'roles',
          roleId,
          { name: nextName.trim(), capability_codes: selectedCodes },
          lockVersion,
          csrfToken,
        )
      }
      return access.createAdminResource(
        'roles',
        {
          resource_type: 'role',
          code: nextCode.trim(),
          name: nextName.trim(),
          capability_codes: selectedCodes,
        },
        csrfToken,
        'authorization-role',
      )
    },
    onSuccess: () => {
      access.invalidateRoleCapabilityCache()
      void queryClient.invalidateQueries({ queryKey: ['access-admin'] })
      void queryClient.invalidateQueries({ queryKey: ['access-roles-enriched'] })
      void queryClient.invalidateQueries({ queryKey: ['access-roles-labels'] })
      void queryClient.invalidateQueries({ queryKey: ['access-roles-labels-enriched'] })
      navigate('/access?tab=roles')
    },
    onError: (caught) => {
      setAlertMessage(
        caught instanceof ApiError ? caught.message : editing ? text.updateError : text.createError,
      )
    },
  })

  if (!canReconstructRole) {
    return (
      <PageLayout data-testid="role-form-screen">
        <DeniedState locale={locale} />
      </PageLayout>
    )
  }

  if (editing && (roleQuery.isLoading || roleQuery.isError)) {
    const derived = roleQuery.isError ? stateFromError(roleQuery.error) : null
    if (roleQuery.isLoading) {
      return (
        <PageLayout data-testid="role-form-screen">
          <LoadingState rows={3} announce={accountsCopy[locale].loading} />
        </PageLayout>
      )
    }
    if (derived === 'forbidden' || derived === 'not-found') {
      return (
        <PageLayout data-testid="role-form-screen">
          <DeniedState locale={locale} />
        </PageLayout>
      )
    }
    return (
      <PageLayout data-testid="role-form-screen">
        <ErrorState locale={locale} onRetry={() => void roleQuery.refetch()} />
      </PageLayout>
    )
  }

  const back = () => navigate('/access?tab=roles')

  function toggleCapability(code: string) {
    setSelectedCodes((current) => {
      if (current === null) return current
      return current.includes(code)
        ? current.filter((item) => item !== code)
        : [...current, code]
    })
  }

  function toggleGroup(group: { items: CapabilityRow[] }) {
    setSelectedCodes((current) => {
      if (current === null) return current
      const codes = group.items
        .map((item) => item.code)
        .filter((code): code is string => typeof code === 'string')
      const allSelected = codes.every((code) => current.includes(code))
      const next = new Set(current)
      for (const code of codes) {
        if (allSelected) next.delete(code)
        else next.add(code)
      }
      return [...next]
    })
  }

  function groupSelectionState(group: { items: CapabilityRow[] }): {
    checked: boolean
    indeterminate: boolean
  } {
    const codes = group.items
      .map((item) => item.code)
      .filter((code): code is string => typeof code === 'string')
    const selected = codes.filter((code) => selectedCodes?.includes(code) ?? false).length
    return {
      checked: selected > 0 && selected === codes.length,
      indeterminate: selected > 0 && selected < codes.length,
    }
  }

  return (
    <PageLayout data-testid="role-form-screen">
      <div>
        <Button variant="ghost" size="sm" onClick={back} className="-ms-2">
          {locale === 'ar' ? (
            <ArrowRight aria-hidden="true" />
          ) : (
            <ArrowLeft aria-hidden="true" />
          )}
          {text.backToRoles}
        </Button>
      </div>

      <PageHeader
        title={editing ? text.editRoleTitle : text.createRoleTitle}
        description={editing ? text.editPageIntro : text.createPageIntro}
        meta={
          catalogState === 'ready' ? (
            <span className="text-muted-foreground text-sm" role="status">
              {selectedCodes?.length ?? 0} / {catalog.length} {text.countCapabilities}
            </span>
          ) : null
        }
      />

      <Form {...form}>
        <TwoRegionFormLayout
          testId="role-form"
          mainTestId="role-form-main"
          reviewTestId="role-form-review"
          onSubmit={(event) => {
            event.preventDefault()
            setAlertMessage(null)
            void form.handleSubmit((values) => {
              mutation.mutate({ nextCode: values.code, nextName: values.name })
            })()
          }}
          main={
            <>
              <FormSection headingId="role-form-fields-heading" title={text.roleInfo}>
                <FormField
                  control={form.control}
                  name="code"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel htmlFor="role-code">{text.code}</FormLabel>
                      <FormControl>
                        <Input id="role-code" dir="ltr" readOnly={editing} disabled={mutation.isPending} className={editing ? 'bg-muted/50' : undefined} {...field} />
                      </FormControl>
                      <FormDescription>{text.codeHint}</FormDescription>
                      <FormMessage role="alert" />
                    </FormItem>
                  )}
                />
                <FormField
                  control={form.control}
                  name="name"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel htmlFor="role-name">{text.name}</FormLabel>
                      <FormControl><Input id="role-name" disabled={mutation.isPending} {...field} /></FormControl>
                      <FormMessage role="alert" />
                    </FormItem>
                  )}
                />
              </FormSection>
              <FormSection headingId="role-capabilities-heading" title={text.capabilities} divided>
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <p className="text-muted-foreground text-sm">{text.capabilityCatalogIntro}</p>
                  <div className="relative w-full max-w-xs">
                    <Search aria-hidden="true" className="text-muted-foreground pointer-events-none absolute start-2 top-1/2 size-4 -translate-y-1/2" />
                    <Input type="search" dir="auto" aria-label={text.capabilitySearchLabel} placeholder={text.capabilitySearchPlaceholder} value={query} onChange={(event) => setQuery(event.target.value)} className="ps-8" />
                  </div>
                </div>
                {catalogState === 'loading' && <div className="rounded-lg border p-4"><LoadingState rows={4} announce={accountsCopy[locale].loading} /></div>}
                {catalogState === 'denied' && <div className="rounded-lg border p-4"><p className="text-muted-foreground text-sm" role="alert">{text.noCatalog}</p></div>}
                {catalogState === 'error' && <div className="rounded-lg border p-4"><p className="text-destructive text-sm" role="alert">{text.capabilityCatalogError}</p><Button type="button" variant="outline" size="sm" className="mt-3" onClick={() => void catalogQuery.refetch()}>{text.capabilityRetry}</Button></div>}
                {catalogState === 'ready' && catalog.length === 0 && <div className="rounded-lg border p-4"><p className="text-muted-foreground text-sm">{text.capabilitiesEmpty}</p></div>}
                {catalogState === 'ready' && catalog.length > 0 && visibleGroups.length === 0 && <div className="rounded-lg border p-4"><p className="text-muted-foreground text-sm" role="status">{text.capabilitySearchEmpty}</p></div>}
                {catalogState === 'ready' && visibleGroups.length > 0 && (
                  <div className="space-y-4">
                    {visibleGroups.map((group) => {
                      const selection = groupSelectionState(group)
                      return (
                        <div key={group.module} className="rounded-lg border p-4">
                          <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                            <label className="flex cursor-pointer items-center gap-2 text-sm font-medium">
                              <Checkbox checked={selection.checked} disabled={mutation.isPending || selectedCodes === null} onCheckedChange={() => toggleGroup(group)} aria-label={selection.checked ? text.clearGroup : text.selectGroup} />
                              <span className="break-words">{group.label}</span><span className="text-muted-foreground text-xs">{group.items.length}</span>
                            </label>
                            {locale === 'en' ? <span className="text-muted-foreground text-xs" dir="ltr">{group.module}</span> : null}
                          </div>
                          <ul className="grid gap-2 md:grid-cols-2">
                            {group.items.map((capability) => {
                              const code = typeof capability.code === 'string' ? capability.code : ''
                              return <li key={capability.id ?? code} className="min-w-0"><label className="flex cursor-pointer items-start gap-2 rounded-md px-1 py-1 text-sm font-normal hover:bg-muted/50"><Checkbox checked={selectedCodes?.includes(code) ?? false} disabled={mutation.isPending || selectedCodes === null} onCheckedChange={() => toggleCapability(code)} className="mt-0.5 shrink-0" /><span className="min-w-0"><span className="flex items-center gap-1.5"><span className="text-sm font-medium break-words whitespace-normal">{capabilityLabel(code, locale)}</span>{isSensitive(capability.sensitivity) ? <ShieldAlert aria-hidden="true" className="text-muted-foreground size-4 shrink-0" /> : null}</span>{locale === 'en' ? <span className="font-mono text-muted-foreground block text-xs break-all whitespace-normal" dir="ltr">{code}</span> : null}</span></label></li>
                            })}
                          </ul>
                        </div>
                      )
                    })}
                  </div>
                )}
              </FormSection>
            </>
          }
          review={
            <>
              <FormSection headingId="role-form-review-heading" title={text.roleReviewHeading}>
                <ReviewSummary rows={[
                  { label: text.code, value: form.watch('code'), empty: text.reviewNotAvailable, isolate: true },
                  { label: text.name, value: form.watch('name'), empty: text.reviewNotAvailable, isolate: true },
                  { label: text.selectedCount, value: String(selectedCodes?.length ?? 0), isolate: true },
                  { label: text.visibleCount, value: String(visibleCount), isolate: true },
                ]} />
              </FormSection>
              {alertMessage ? <Alert role="alert"><AlertTitle>{editing ? text.updateError : text.createError}</AlertTitle><AlertDescription><p className="break-words">{alertMessage}</p><Button type="button" variant="outline" size="sm" className="mt-2" disabled={mutation.isPending} onClick={() => { setAlertMessage(null); if (editing) { void roleQuery.refetch(); void roleCodesQuery.refetch() } }}>{accountsCopy[locale].retry}</Button></AlertDescription></Alert> : null}
              <FormActionStack testId="role-form-actions">
                <Button type="button" variant="outline" onClick={back} disabled={mutation.isPending}>{text.cancel}</Button>
                <Button type="submit" disabled={mutation.isPending || catalogState !== 'ready' || selectedCodes === null}>{mutation.isPending ? accountsCopy[locale].loading : editing ? text.save : text.createSubmit}</Button>
              </FormActionStack>
            </>
          }
        />
      </Form>
     </PageLayout>
   )
 }


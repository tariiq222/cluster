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
import { Checkbox } from '@/components/ui/checkbox'
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/components/ui/form'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet'
import { roleCopy, accountsCopy } from '../accounts-copy'

const ROLE_CODE_PATTERN = /^[a-z][a-z0-9_.-]{1,95}$/

function roleDisplayName(role: generated.AuthorizationRole, locale: 'ar' | 'en'): string {
  return locale === 'ar'
    ? (role.name_ar ?? role.name_en ?? role.code)
    : (role.name_en ?? role.name_ar ?? role.code)
}

function roleStateFromError(error: unknown): 'loading' | 'ready' | 'denied' | 'error' {
  if (error instanceof ApiError) {
    if (error.status === 403 || error.status === 404) return 'denied'
  }
  return 'error'
}

export function RoleSheet({
  open,
  role,
  onClose,
  onSaved,
}: {
  open: boolean
  role: generated.AuthorizationRole | null
  onClose: () => void
  onSaved: () => void
}) {
  const locale = useLocale()
  const csrfToken = useSessionToken()
  const text = roleCopy[locale]
  const queryClient = useQueryClient()
  const editing = role !== null
  const [catalog, setCatalog] = useState<Array<generated.AuthorizationCapability & { id: string }>>([])
  const [catalogState, setCatalogState] = useState<'loading' | 'ready' | 'denied' | 'error'>('loading')
  /*
   * `null` means the role's capability set is not known yet. The edit sheet
   * must never turn an unknown set into `[]`: submit stays disabled until
   * the set is resolved from the enriched row or the role-capabilities
   * resource.
   */
  const [selectedCodes, setSelectedCodes] = useState<string[] | null>(null)
  const [alertMessage, setAlertMessage] = useState<string | null>(null)

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
    defaultValues: { code: role?.code ?? '', name: role ? roleDisplayName(role, locale) : '' },
  })

  useEffect(() => {
    if (!open) return
    setAlertMessage(null)
    setCatalog([])
    setCatalogState('loading')
    if (role && Array.isArray(role.capability_codes)) {
      // The enriched row already carries the known allow set.
      setSelectedCodes([...role.capability_codes])
    } else {
      // Unknown set: stay `null` until resolved (create starts empty).
      setSelectedCodes(role ? null : [])
    }
    form.reset({
      code: role?.code ?? '',
      name: role ? roleDisplayName(role, locale) : '',
    })
    void access
      .listCapabilities()
      .then((collection) => {
        setCatalog(
          collection.items as unknown as Array<generated.AuthorizationCapability & { id: string }>,
        )
        setCatalogState('ready')
      })
      .catch((error) => {
        setCatalog([])
        setCatalogState(roleStateFromError(error))
      })
    if (role && !Array.isArray(role.capability_codes)) {
      void access
        .listRoleCapabilityCodes(role.id)
        .then((codes) => setSelectedCodes(codes))
        .catch(() => {
          // A failed set read must not erase the role's capabilities: keep
          // the selection unknown so the sheet cannot submit an empty set.
          setSelectedCodes(null)
        })
    }
  }, [open, role, locale, form])

  function toggleCapability(code: string) {
    setSelectedCodes((current) => {
      if (current === null) return current
      return current.includes(code) ? current.filter((item) => item !== code) : [...current, code]
    })
  }

  const mutation = useMutation({
    mutationFn: async ({ nextCode, nextName }: { nextCode: string; nextName: string }) => {
      if (selectedCodes === null) {
        throw new Error('Capability set is not loaded')
      }
      if (editing && role) {
        return access.updateAdminResource(
          'roles',
          role.id,
          { name: nextName.trim(), capability_codes: selectedCodes },
          role.lock_version,
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
      void queryClient.invalidateQueries({ queryKey: ['access-admin'] })
      onSaved()
    },
    onError: (caught) => {
      setAlertMessage(
        caught instanceof ApiError ? caught.message : editing ? text.updateError : text.createError,
      )
    },
  })

  return (
    <Sheet open={open} onOpenChange={(next) => { if (!next && !mutation.isPending) onClose() }}>
      <SheetContent>
        <SheetHeader>
          <SheetTitle>{editing ? text.editRoleTitle : text.createRoleTitle}</SheetTitle>
          <SheetDescription>{text.roleSheetIntro}</SheetDescription>
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
                mutation.mutate({ nextCode: values.code, nextName: values.name })
              })()
            }}
            noValidate
          >
            <FormField
              control={form.control}
              name="code"
              render={({ field }) => (
                <FormItem>
                  <FormLabel htmlFor="role-code">{text.code}</FormLabel>
                  <FormControl>
                    <Input id="role-code" dir="ltr" disabled={editing} {...field} />
                  </FormControl>
                  <p className="text-muted-foreground text-xs">{text.codeHint}</p>
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
                  <FormControl>
                    <Input id="role-name" {...field} />
                  </FormControl>
                  <FormMessage role="alert" />
                </FormItem>
              )}
            />
            <fieldset>
              <legend className="mb-2 text-sm font-medium">{text.capabilities}</legend>
              {catalogState === 'loading' && (
                <p className="text-muted-foreground text-sm" role="status">
                  {accountsCopy[locale].loading}
                </p>
              )}
              {catalogState === 'denied' && (
                <p className="text-muted-foreground text-sm" role="alert">{text.noCatalog}</p>
              )}
              {catalogState === 'error' && (
                <p className="text-destructive text-sm" role="alert">{text.roleError}</p>
              )}
              {catalogState === 'ready' && catalog.length === 0 && (
                <p className="text-muted-foreground text-sm">{text.capabilitiesEmpty}</p>
              )}
              {catalogState === 'ready' && catalog.length > 0 && (
                <div className="grid gap-2">
                  {catalog.map((capability) => (
                    <Label key={capability.id} className="flex items-center gap-2 font-normal">
                      <Checkbox
                        checked={selectedCodes?.includes(capability.code) ?? false}
                        disabled={mutation.isPending || selectedCodes === null}
                        onCheckedChange={() => toggleCapability(capability.code)}
                      />
                      <span className="font-mono text-sm" dir="ltr">{capability.code}</span>
                    </Label>
                  ))}
                </div>
              )}
            </fieldset>
            <div className="flex justify-end gap-2">
              <Button type="button" variant="outline" onClick={onClose} disabled={mutation.isPending}>
                {text.cancel}
              </Button>
              <Button
                type="submit"
                disabled={mutation.isPending || catalogState !== 'ready' || selectedCodes === null}
              >
                {mutation.isPending ? accountsCopy[locale].loading : text.save}
              </Button>
            </div>
          </form>
        </Form>
      </SheetContent>
    </Sheet>
  )
}

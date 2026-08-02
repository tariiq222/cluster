import { useEffect, useMemo, useRef, useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { ArrowLeft, ArrowRight } from 'lucide-react'
import { useLocale, useSessionToken } from '../../app/session-context'
import { usePrincipal } from '../../app/principal-context'
import { useNavigate } from '../../app/navigation-context'
import { ApiError } from '../../api/http'
import * as generated from '../../api/generated/cluster'
import * as access from '../../api/access'
import { PageHeader, PageLayout } from '@/components/page-layout'
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
import { SingleRegionFormLayout, FormSection } from '@/components/form-page-layout'
import { Input } from '@/components/ui/input'
import { DeniedState } from '@/components/states'
import { accountCopy, accountsCopy } from './accounts-copy'
import { PeoplePickerCombobox } from './tabs/PeoplePickerCombobox'

const USERNAME_PATTERN = /^[a-zA-Z0-9._-]{3,128}$/

/*
 * Full-page replacement for the former add-account Sheet
 * (route `/access/accounts/new`).
 *
 * The picker stays the sole owner of cursor loading, error/denied/empty
 * state, and the refetch affordance. This page only keeps the full
 * selected Person (with its `person_version`) so submit can carry the
 * right version regardless of which page the row came from.
 */
export function AccountCreateScreen() {
  const locale = useLocale()
  const csrfToken = useSessionToken()
  const principal = usePrincipal()
  const navigate = useNavigate()
  const text = accountCopy[locale]
  const [saveError, setSaveError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)
  const selectedPersonRef = useRef<generated.Person | null>(null)

  const canManage = (principal.capabilities ?? []).includes('identity.account.manage')

  const schema = useMemo(
    () =>
      z.object({
        personId: z.string().min(1, text.validation),
        username: z.string().regex(USERNAME_PATTERN, text.validation),
      }),
    [text],
  )

  const form = useForm<{ personId: string; username: string }>({
    resolver: zodResolver(schema),
    defaultValues: { personId: '', username: '' },
  })

  /*
   * The page unmounts on navigation, so the cached Person never outlives
   * the form; the effect guard exists for the re-render path where the
   * person picker is re-opened after a failed submit.
   */
  useEffect(() => {
    selectedPersonRef.current = null
  }, [])

  if (!canManage) {
    return (
      <PageLayout data-testid="account-create-screen">
        <DeniedState locale={locale} />
      </PageLayout>
    )
  }

  const back = () => navigate('/access?tab=accounts')

  return (
    <PageLayout data-testid="account-create-screen">
      <div>
        <Button variant="ghost" size="sm" onClick={back} className="-ms-2">
          {locale === 'ar' ? (
            <ArrowRight aria-hidden="true" />
          ) : (
            <ArrowLeft aria-hidden="true" />
          )}
          {text.backToAccounts}
        </Button>
      </div>

      <PageHeader title={text.addAccountTitle} description={text.addAccountIntro} />

      <Form {...form}>
        <SingleRegionFormLayout
          testId="account-create-form"
          onSubmit={(event) => {
            event.preventDefault()
            setSaveError(null)
            void form.handleSubmit(async (values) => {
              const cached = selectedPersonRef.current
              if (!cached || cached.id !== values.personId) {
                setSaveError(text.validation)
                return
              }
              setSubmitting(true)
              try {
                await access.createAccount(
                  {
                    person_id: cached.id,
                    person_version: cached.person_version,
                    username: values.username.trim(),
                  },
                  csrfToken,
                )
                navigate('/access?tab=accounts')
              } catch (cause) {
                setSaveError(
                  cause instanceof ApiError && cause.status === 412
                    ? accountsCopy[locale].stale
                    : text.saveError,
                )
              } finally {
                setSubmitting(false)
              }
            })()
          }}
          actions={
            <div className="flex flex-wrap justify-end gap-2">
              <Button type="button" variant="outline" onClick={back} disabled={submitting}>
                {accountsCopy[locale].cancel}
              </Button>
              <Button type="submit" disabled={submitting}>
                {submitting ? text.saving : text.create}
              </Button>
            </div>
          }
        >
          <FormSection headingId="account-create-fields-heading" title={text.addAccountTitle}>
            <FormField
              control={form.control}
              name="personId"
              render={({ field }) => (
                <FormItem>
                  <FormLabel htmlFor="account-person">{text.employee}</FormLabel>
                  <FormControl>
                    <PeoplePickerCombobox
                      triggerId="account-person"
                      selectedId={field.value}
                      onSelect={(person) => {
                        selectedPersonRef.current = person
                        field.onChange(person.id)
                      }}
                      invalid={!field.value}
                    />
                  </FormControl>
                  <FormMessage role="alert" />
                </FormItem>
              )}
            />
            <FormField
              control={form.control}
              name="username"
              render={({ field }) => (
                <FormItem>
                  <FormLabel htmlFor="account-username">{text.username}</FormLabel>
                  <FormControl>
                    <Input id="account-username" dir="ltr" disabled={submitting} {...field} />
                  </FormControl>
                  <FormDescription>{text.usernameHint}</FormDescription>
                  <FormMessage role="alert" />
                </FormItem>
              )}
            />
            {saveError ? <p className="text-destructive text-sm" role="alert">{saveError}</p> : null}
          </FormSection>
        </SingleRegionFormLayout>
      </Form>
    </PageLayout>
  )
}

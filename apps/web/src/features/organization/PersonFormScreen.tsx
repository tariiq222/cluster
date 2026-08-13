import { useCallback, useEffect, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, ArrowRight } from 'lucide-react'
import { useLocale, useSessionToken } from '../../app/session-context'
import { useNavigate } from '../../app/navigation-context'
import { ApiError, requestInit, unwrap } from '../../api/http'
import * as generated from '../../api/generated/cluster'
import { PageHeader, PageLayout } from '@/components/page-layout'
import {
  FormActionStack,
  FormSection,
  ReviewSummary,
  TwoRegionFormLayout,
} from '@/components/form-page-layout'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { DeniedState, EmptyState, ErrorState, LoadingState } from '@/components/states'
import { organizationCopy } from './organization-copy'
import { useCapabilities } from './organization-utils'

/*
 * Full-page replacement for the former PersonSheet
 * (routes `/organization/people/new` and `/organization/people/:id/edit`).
 *
 * The edit path saves against the person version used to seed the form. A
 * submit-time re-fetch would defeat optimistic concurrency. The employee
 * number is read-only; a stale 412 keeps the inputs visible and offers reload.
 */
export function PersonFormScreen({ personId }: { personId?: string }) {
  const locale = useLocale()
  const token = useSessionToken()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const text = organizationCopy[locale]
  const capabilities = useCapabilities()
  const editing = personId !== undefined

  const canManage = capabilities.includes('organization.person.manage')

  const [employeeNumber, setEmployeeNumber] = useState('')
  const [nameAr, setNameAr] = useState('')
  const [nameEn, setNameEn] = useState('')
  const [failure, setFailure] = useState<'validation' | 'stale' | 'save' | null>(null)
  const [seed, setSeed] = useState<generated.Person | null>(null)
  const [loading, setLoading] = useState(editing)
  const [loadError, setLoadError] = useState<unknown>(null)

  const load = useCallback(async () => {
    if (!editing || !personId) return
    setLoading(true)
    setLoadError(null)
    try {
      const fresh = unwrap<generated.Person>(await generated.getPerson(personId, requestInit(token)))
      setSeed(fresh)
      setEmployeeNumber(fresh.employee_number)
      setNameAr(fresh.display_name_ar)
      setNameEn(fresh.display_name_en ?? '')
      setFailure(null)
    } catch (caught) {
      setLoadError(caught)
    } finally {
      setLoading(false)
    }
  }, [editing, personId, token])

  useEffect(() => {
    void load()
  }, [load])

  const mutation = useMutation({
    mutationFn: async ({ nextEmployeeNumber, nextNameAr, nextNameEn }: { nextEmployeeNumber: string; nextNameAr: string; nextNameEn: string }) => {
      if (editing && personId) {
        if (!seed) throw new Error('person_edit_seed_missing')
        return unwrap<generated.Person>(
          await generated.updatePerson(
            personId,
            { display_name_ar: nextNameAr, ...(nextNameEn ? { display_name_en: nextNameEn } : {}) },
            requestInit(token, { command: true, idempotency: 'person-update', lockVersion: seed.person_version }),
          ),
        )
      }
      return unwrap<generated.Person>(
        await generated.registerPerson(
          {
            employee_number: nextEmployeeNumber,
            display_name_ar: nextNameAr,
            display_name_en: nextNameEn.trim() || undefined,
            status: 'active',
          },
          requestInit(token, { command: true, idempotency: 'person' }),
        ),
      )
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['people'], refetchType: 'all' })
      navigate('/organization?tab=people')
    },
    onError: (caught) => {
      setFailure(caught instanceof ApiError && caught.status === 412 ? 'stale' : 'save')
    },
  })
  const submitting = mutation.isPending

  const back = () => navigate('/organization?tab=people')

  if (!canManage) {
    return (
      <PageLayout data-testid="person-form-screen">
        <DeniedState locale={locale} />
      </PageLayout>
    )
  }

  if (editing && loading) {
    return (
      <PageLayout data-testid="person-form-screen">
        <PageHeader title={text.editPersonTitle} />
        <LoadingState rows={2} announce={text.loading} />
      </PageLayout>
    )
  }

  if (editing && loadError) {
    return (
      <PageLayout data-testid="person-form-screen">
        <PageHeader title={text.editPersonTitle} />
        <ErrorState onRetry={() => void load()} correlationId={loadError instanceof ApiError ? loadError.correlationId : null} locale={locale} />
      </PageLayout>
    )
  }

  if (editing && !loading && !loadError && !seed) {
    return (
      <PageLayout data-testid="person-form-screen">
        <PageHeader title={text.editPersonTitle} />
        <EmptyState title={text.noPeople} action={<Button onClick={back}>{text.backToPeople}</Button>} />
      </PageLayout>
    )
  }

  const failureMessage =
    failure === 'validation' ? text.validation : failure === 'stale' ? text.stale : failure === 'save' ? text.saveError : null

  return (
    <PageLayout data-testid="person-form-screen">
      <div>
        <Button variant="ghost" size="sm" onClick={back} className="-ms-2">
          {locale === 'ar' ? <ArrowRight aria-hidden="true" /> : <ArrowLeft aria-hidden="true" />}
          {text.backToPeople}
        </Button>
      </div>

      <PageHeader title={editing ? text.editPersonTitle : text.createPersonTitle} description={text.people} />

      <TwoRegionFormLayout
        testId="person-form"
        mainTestId="person-main"
        reviewTestId="person-review"
        onSubmit={(event) => {
          event.preventDefault()
          if (!nameAr.trim() || (!editing && !employeeNumber.trim())) {
            setFailure('validation')
            return
          }
          setFailure(null)
          mutation.mutate({ nextEmployeeNumber: employeeNumber.trim(), nextNameAr: nameAr.trim(), nextNameEn: nameEn.trim() })
        }}
        main={
          <div className="grid gap-6">
            {failure === 'stale' ? (
              <div role="alert" className="space-y-2">
                <p className="text-destructive text-sm">{failureMessage}</p>
                <Button type="button" variant="outline" size="sm" onClick={() => void load()}>
                  {text.retry}
                </Button>
              </div>
            ) : failureMessage ? (
              <p className="text-destructive text-sm" role="alert">{failureMessage}</p>
            ) : null}
            <FormSection headingId="person-details-heading" title={text.detailsSection}>
              <div className="grid gap-2">
                <Label htmlFor="org-person-employee-number">{text.employeeNumber}</Label>
                <Input
                  id="org-person-employee-number"
                  dir="ltr"
                  value={employeeNumber}
                  readOnly={editing}
                  aria-invalid={failure === 'validation' || undefined}
                  onChange={(event) => setEmployeeNumber(event.target.value)}
                />
              </div>
              <div className="grid gap-2">
                <Label htmlFor="org-person-name-ar">{text.nameAr}</Label>
                <Input
                  id="org-person-name-ar"
                  value={nameAr}
                  aria-invalid={failure === 'validation' || undefined}
                  onChange={(event) => setNameAr(event.target.value)}
                />
              </div>
              <div className="grid gap-2">
                <Label htmlFor="org-person-name-en">{text.nameEn}</Label>
                <Input id="org-person-name-en" value={nameEn} onChange={(event) => setNameEn(event.target.value)} />
              </div>
            </FormSection>
          </div>
        }
        review={
          <div className="grid gap-4">
            <FormSection headingId="person-review-heading" title={text.reviewTitle} density="tight">
              <ReviewSummary
                testId="person-review-summary"
                rows={[
                  { label: text.employeeNumber, value: employeeNumber, empty: text.notProvided, isolate: true },
                  { label: text.nameAr, value: nameAr, empty: text.notProvided, isolate: true },
                  { label: text.nameEn, value: nameEn, empty: text.notProvided, isolate: true },
                ]}
              />
            </FormSection>
            <FormActionStack testId="person-actions">
              <Button type="submit" className="w-full" disabled={submitting}>
                {submitting ? text.saving : text.save}
              </Button>
              <Button type="button" variant="outline" className="w-full" onClick={back} disabled={submitting}>
                {text.cancel}
              </Button>
            </FormActionStack>
          </div>
        }
      />
    </PageLayout>
  )
}

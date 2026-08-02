import { useCallback, useEffect, useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { ArrowLeft, ArrowRight, Building2 } from 'lucide-react'
import { useLocale, useSessionToken } from '../../app/session-context'
import { useNavigate } from '../../app/navigation-context'
import { useCluster } from '../../api/hooks'
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
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { DeniedState, EmptyState, ErrorState, LoadingState } from '@/components/states'
import { organizationCopy } from './organization-copy'
import { CODE_PATTERN, facilityTypes, useCapabilities } from './organization-utils'

/*
 * Full-page replacement for the former FacilitySheet
 * (routes `/organization/facilities/new` and `/organization/facilities/:id/edit`).
 *
 * Create requires the cluster root (404 renders the setup empty state);
 * edit fetches the facility fresh to seed the form AND to obtain the
 * lock version, keeps type/code read-only, and keeps inputs visible
 * with a reload affordance on a stale 412.
 */
export function FacilityFormScreen({ facilityId }: { facilityId?: string }) {
  const locale = useLocale()
  const token = useSessionToken()
  const navigate = useNavigate()
  const text = organizationCopy[locale]
  const capabilities = useCapabilities()
  const editing = facilityId !== undefined

  const canManage = capabilities.includes('organization.facility.manage')

  const clusterQuery = useCluster()
  const clusterMissing = clusterQuery.error instanceof ApiError && clusterQuery.error.status === 404
  const cluster = clusterMissing ? null : ((clusterQuery.data as generated.Cluster | null) ?? null)
  const [typeCode, setTypeCode] = useState('hospital')
  const [code, setCode] = useState('')
  const [name, setName] = useState('')
  const [nameEn, setNameEn] = useState('')
  const [failure, setFailure] = useState<'validation' | 'stale' | 'save' | null>(null)
  const [seed, setSeed] = useState<generated.Facility | null>(null)
  const [loading, setLoading] = useState(editing)
  const [loadError, setLoadError] = useState<unknown>(null)

  const load = useCallback(async () => {
    if (!editing || !facilityId) return
    setLoading(true)
    setLoadError(null)
    try {
      const fresh = unwrap<generated.Facility>(await generated.getFacility(facilityId, requestInit(token)))
      setSeed(fresh)
      setTypeCode(fresh.type_code)
      setCode(fresh.code)
      setName(fresh.name_ar)
      setNameEn(fresh.name_en ?? '')
      setFailure(null)
    } catch (caught) {
      setLoadError(caught)
    } finally {
      setLoading(false)
    }
  }, [editing, facilityId, token])

  useEffect(() => {
    void load()
  }, [load])

  const mutation = useMutation({
    mutationFn: async ({ nextTypeCode, nextCode, nextName, nextNameEn }: { nextTypeCode: string; nextCode: string; nextName: string; nextNameEn: string }) => {
      if (editing && facilityId) {
        const fresh = unwrap<generated.Facility>(await generated.getFacility(facilityId, requestInit(token)))
        return unwrap<generated.Facility>(
          await generated.updateFacility(
            facilityId,
            { name: nextName },
            requestInit(token, { command: true, idempotency: 'facility-update', lockVersion: fresh.lock_version }),
          ),
        )
      }
      return unwrap<generated.Facility>(
        await generated.createFacility(
          { cluster_id: cluster!.id, type_code: nextTypeCode, code: nextCode, name: nextName, name_en: nextNameEn.trim() || null },
          requestInit(token, { command: true, idempotency: 'facility' }),
        ),
      )
    },
    onSuccess: () => {
      navigate('/organization?tab=facilities')
    },
    onError: (caught) => {
      setFailure(caught instanceof ApiError && caught.status === 412 ? 'stale' : 'save')
    },
  })
  const submitting = mutation.isPending

  const back = () => navigate('/organization?tab=facilities')

  if (!canManage) {
    return (
      <PageLayout data-testid="facility-form-screen">
        <DeniedState locale={locale} />
      </PageLayout>
    )
  }

  if (editing && loading) {
    return (
      <PageLayout data-testid="facility-form-screen">
        <PageHeader title={text.editFacilityTitle} />
        <LoadingState rows={2} announce={text.loading} />
      </PageLayout>
    )
  }

  if (editing && loadError) {
    return (
      <PageLayout data-testid="facility-form-screen">
        <PageHeader title={text.editFacilityTitle} />
        <ErrorState onRetry={() => void load()} correlationId={loadError instanceof ApiError ? loadError.correlationId : null} locale={locale} />
      </PageLayout>
    )
  }

  if (editing && !loading && !loadError && !seed) {
    return (
      <PageLayout data-testid="facility-form-screen">
        <PageHeader title={text.editFacilityTitle} />
        <EmptyState title={text.noFacilities} action={<Button onClick={back}>{text.backToFacilities}</Button>} />
      </PageLayout>
    )
  }

  if (!editing && cluster === null) {
    if (clusterQuery.isLoading) {
      return (
        <PageLayout data-testid="facility-form-screen">
          <PageHeader title={text.createFacilityTitle} />
          <LoadingState rows={2} announce={text.loading} />
        </PageLayout>
      )
    }
    return (
      <PageLayout data-testid="facility-form-screen">
        <PageHeader title={text.createFacilityTitle} />
        <EmptyState
          icon={<Building2 aria-hidden="true" />}
          title={text.noCluster}
          action={<Button onClick={back}>{text.backToFacilities}</Button>}
        />
      </PageLayout>
    )
  }

  const failureMessage =
    failure === 'validation' ? text.validation : failure === 'stale' ? text.stale : failure === 'save' ? text.saveError : null
  const selectedFacilityType = facilityTypes.find(([value]) => value === typeCode)
  const facilityTypeLabel = selectedFacilityType
    ? text[selectedFacilityType[1]]
    : text.notProvided

  return (
    <PageLayout data-testid="facility-form-screen">
      <div>
        <Button variant="ghost" size="sm" onClick={back} className="-ms-2">
          {locale === 'ar' ? <ArrowRight aria-hidden="true" /> : <ArrowLeft aria-hidden="true" />}
          {text.backToFacilities}
        </Button>
      </div>

      <PageHeader title={editing ? text.editFacilityTitle : text.createFacilityTitle} description={text.facilities} />

      <TwoRegionFormLayout
        testId="facility-form"
        mainTestId="facility-main"
        reviewTestId="facility-review"
        onSubmit={(event) => {
          event.preventDefault()
          if (!name.trim() || (!editing && (!CODE_PATTERN.test(code) || typeCode === ''))) {
            setFailure('validation')
            return
          }
          setFailure(null)
          mutation.mutate({ nextTypeCode: typeCode, nextCode: code, nextName: name.trim(), nextNameEn: nameEn })
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
            <FormSection headingId="facility-details-heading" title={text.detailsSection}>
              <div className="grid gap-2">
                <Label htmlFor="org-facility-type">{text.type}</Label>
                <Select value={typeCode} onValueChange={setTypeCode} disabled={editing}>
                  <SelectTrigger id="org-facility-type" className="w-full">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {facilityTypes.map(([value, key]) => (
                      <SelectItem key={value} value={value}>
                        {text[key]}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              <div className="grid gap-2">
                <Label htmlFor="org-facility-code">{text.code}</Label>
                <Input
                  id="org-facility-code"
                  dir="ltr"
                  value={code}
                  readOnly={editing}
                  aria-invalid={failure === 'validation' || undefined}
                  onChange={(event) => setCode(event.target.value.toUpperCase())}
                />
                {!editing ? <p className="text-muted-foreground text-xs">{text.codeHint}</p> : null}
              </div>
              <div className="grid gap-2">
                <Label htmlFor="org-facility-name">{text.nameAr}</Label>
                <Input
                  id="org-facility-name"
                  value={name}
                  aria-invalid={failure === 'validation' || undefined}
                  onChange={(event) => setName(event.target.value)}
                />
              </div>
              {!editing ? (
                <div className="grid gap-2">
                  <Label htmlFor="org-facility-name-en">{text.nameEn}</Label>
                  <Input id="org-facility-name-en" value={nameEn} onChange={(event) => setNameEn(event.target.value)} />
                </div>
              ) : null}
            </FormSection>
          </div>
        }
        review={
          <div className="grid gap-4">
            <FormSection headingId="facility-review-heading" title={text.reviewTitle} density="tight">
              <ReviewSummary
                testId="facility-review-summary"
                rows={[
                  { label: text.type, value: facilityTypeLabel },
                  { label: text.code, value: code, empty: text.notProvided, isolate: true },
                  { label: text.nameAr, value: name, empty: text.notProvided, isolate: true },
                  ...(editing
                    ? []
                    : [{ label: text.nameEn, value: nameEn, empty: text.notProvided, isolate: true }]),
                ]}
              />
            </FormSection>
            <FormActionStack testId="facility-actions">
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

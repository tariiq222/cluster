import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { ArrowLeft, ArrowRight } from 'lucide-react'
import { useSearchParams } from 'react-router-dom'
import { useLocale, useSessionToken } from '../../app/session-context'
import { useNavigate } from '../../app/navigation-context'
import { useJobTitles, useOrganizationUnits } from '../../api/hooks'
import { requestInit, unwrap } from '../../api/http'
import * as generated from '../../api/generated/cluster'
import { PageHeader, PageLayout } from '@/components/page-layout'
import { FormSection, SingleRegionFormLayout } from '@/components/form-page-layout'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { DeniedState } from '@/components/states'
import { organizationCopy } from './organization-copy'
import { CODE_PATTERN, displayName, useCapabilities } from './organization-utils'

/*
 * Full-page replacement for the former PositionSheet
 * (route `/organization/positions/new`).
 *
 * `?unitId=` preselects the owning unit. The job title stays optional.
 */
export function PositionCreateScreen() {
  const locale = useLocale()
  const token = useSessionToken()
  const navigate = useNavigate()
  const text = organizationCopy[locale]
  const capabilities = useCapabilities()
  const [searchParams] = useSearchParams()
  const unitsQuery = useOrganizationUnits()
  const jobTitlesQuery = useJobTitles()

  const canManage = capabilities.includes('organization.position.manage')

  const units = (unitsQuery.data as generated.OrganizationUnitCollection | undefined)?.items ?? []
  const jobTitles = (jobTitlesQuery.data as generated.JobTitleCollection | undefined)?.items ?? []

  const [unitId, setUnitId] = useState(() => searchParams.get('unitId') ?? '')
  const [code, setCode] = useState('')
  const [title, setTitle] = useState('')
  const [jobTitleId, setJobTitleId] = useState('')
  const [failure, setFailure] = useState<'validation' | 'save' | null>(null)

  const mutation = useMutation({
    mutationFn: async ({ nextUnitId, nextCode, nextTitle, nextJobTitleId }: { nextUnitId: string; nextCode: string; nextTitle: string; nextJobTitleId: string }) =>
      unwrap<generated.Position>(
        await generated.createPosition(
          {
            organization_unit_id: nextUnitId,
            code: nextCode,
            title: nextTitle,
            ...(nextJobTitleId ? { job_title_id: nextJobTitleId } : {}),
          },
          requestInit(token, { command: true, idempotency: 'position' }),
        ),
      ),
    onSuccess: () => {
      navigate('/organization?tab=positions')
    },
    onError: () => setFailure('save'),
  })
  const submitting = mutation.isPending

  const back = () => navigate('/organization?tab=positions')

  if (!canManage) {
    return (
      <PageLayout data-testid="position-create-screen">
        <DeniedState locale={locale} />
      </PageLayout>
    )
  }

  return (
    <PageLayout data-testid="position-create-screen">
      <div>
        <Button variant="ghost" size="sm" onClick={back} className="-ms-2">
          {locale === 'ar' ? <ArrowRight aria-hidden="true" /> : <ArrowLeft aria-hidden="true" />}
          {text.backToPositions}
        </Button>
      </div>

      <PageHeader title={text.createPositionTitle} description={text.unitPositions} />

      <SingleRegionFormLayout
        testId="position-create-form"
        actionsTestId="position-create-actions"
        onSubmit={(event) => {
          event.preventDefault()
          if (!unitId || !CODE_PATTERN.test(code)) {
            setFailure('validation')
            return
          }
          setFailure(null)
          mutation.mutate({ nextUnitId: unitId, nextCode: code, nextTitle: title.trim(), nextJobTitleId: jobTitleId })
        }}
        actions={
          <div className="flex flex-wrap justify-end gap-2">
            <Button type="button" variant="outline" onClick={back} disabled={submitting}>
              {text.cancel}
            </Button>
            <Button type="submit" disabled={submitting}>
              {submitting ? text.saving : text.save}
            </Button>
          </div>
        }
      >
        {failure === 'validation' ? (
          <p className="text-destructive text-sm" role="alert">{text.validation}</p>
        ) : failure === 'save' ? (
          <p className="text-destructive text-sm" role="alert">{text.saveError}</p>
        ) : null}
        <FormSection headingId="position-create-details-heading" title={text.detailsSection}>
          <div className="grid gap-2">
            <Label htmlFor="org-position-unit">{text.parent}</Label>
            <Select value={unitId} onValueChange={setUnitId}>
              <SelectTrigger id="org-position-unit" className="w-full">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {units.map((unit) => (
                  <SelectItem key={unit.id} value={unit.id}>
                    {displayName(locale, unit)}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          <div className="grid gap-2">
            <Label htmlFor="org-position-code">{text.code}</Label>
            <Input
              id="org-position-code"
              dir="ltr"
              value={code}
              aria-invalid={failure === 'validation' || undefined}
              onChange={(event) => setCode(event.target.value.toUpperCase())}
            />
            <p className="text-muted-foreground text-xs">{text.codeHint}</p>
          </div>
          <div className="grid gap-2">
            <Label htmlFor="org-position-title">{text.positionTitle}</Label>
            <Input id="org-position-title" value={title} onChange={(event) => setTitle(event.target.value)} />
          </div>
          <div className="grid gap-2">
            <Label htmlFor="org-position-job-title">{text.jobTitle}</Label>
            <Select value={jobTitleId} onValueChange={setJobTitleId}>
              <SelectTrigger id="org-position-job-title" className="w-full">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {jobTitles.map((titleItem) => (
                  <SelectItem key={titleItem.id} value={titleItem.id}>
                    {titleItem.title_ar}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
        </FormSection>
      </SingleRegionFormLayout>
    </PageLayout>
  )
}

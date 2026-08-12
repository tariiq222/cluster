import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { ArrowLeft, ArrowRight } from 'lucide-react'
import { useLocale, useSessionToken } from '../../app/session-context'
import { useNavigate } from '../../app/navigation-context'
import { requestInit, unwrap } from '../../api/http'
import * as generated from '../../api/generated/cluster'
import { PageHeader, PageLayout } from '@/components/page-layout'
import { FormSection, SingleRegionFormLayout } from '@/components/form-page-layout'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { DeniedState } from '@/components/states'
import { organizationCopy } from './organization-copy'
import { CODE_PATTERN, useCapabilities } from './organization-utils'

/*
 * Full-page replacement for the former JobTitleSheet
 * (route `/organization/job-titles/new`).
 */
export function JobTitleCreateScreen() {
  const locale = useLocale()
  const token = useSessionToken()
  const navigate = useNavigate()
  const text = organizationCopy[locale]
  const capabilities = useCapabilities()

  const canManage = capabilities.includes('organization.position.manage')

  const [code, setCode] = useState('')
  const [title, setTitle] = useState('')
  const [failure, setFailure] = useState<'validation' | 'save' | null>(null)

  const mutation = useMutation({
    mutationFn: async ({ nextCode, nextTitle }: { nextCode: string; nextTitle: string }) =>
      unwrap<generated.JobTitle>(
        await generated.createJobTitle(
          { code: nextCode, title_ar: nextTitle },
          requestInit(token, { command: true, idempotency: 'job-title' }),
        ),
      ),
    onSuccess: () => {
      navigate('/organization?tab=job-titles')
    },
    onError: () => setFailure('save'),
  })
  const submitting = mutation.isPending

  const back = () => navigate('/organization?tab=job-titles')

  if (!canManage) {
    return (
      <PageLayout data-testid="job-title-create-screen">
        <DeniedState locale={locale} />
      </PageLayout>
    )
  }

  return (
    <PageLayout data-testid="job-title-create-screen">
      <div>
        <Button variant="ghost" size="sm" onClick={back} className="-ms-2">
          {locale === 'ar' ? <ArrowRight aria-hidden="true" /> : <ArrowLeft aria-hidden="true" />}
          {text.backToJobTitles}
        </Button>
      </div>

      <PageHeader title={text.addJobTitle} description={text.jobTitle} />

      <SingleRegionFormLayout
        testId="job-title-create-form"
        actionsTestId="job-title-create-actions"
        onSubmit={(event) => {
          event.preventDefault()
          if (!title.trim() || !CODE_PATTERN.test(code)) {
            setFailure('validation')
            return
          }
          setFailure(null)
          mutation.mutate({ nextCode: code, nextTitle: title.trim() })
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
        <FormSection headingId="job-title-create-details-heading" title={text.detailsSection}>
          <div className="grid gap-2">
            <Label htmlFor="org-job-title-code">{text.code}</Label>
            <Input
              id="org-job-title-code"
              dir="ltr"
              value={code}
              aria-invalid={failure === 'validation' || undefined}
              onChange={(event) => setCode(event.target.value.toUpperCase())}
            />
            <p className="text-muted-foreground text-xs">{text.codeHint}</p>
          </div>
          <div className="grid gap-2">
            <Label htmlFor="org-job-title-name">{text.jobTitle}</Label>
            <Input
              id="org-job-title-name"
              value={title}
              aria-invalid={failure === 'validation' || undefined}
              onChange={(event) => setTitle(event.target.value)}
            />
          </div>
        </FormSection>
      </SingleRegionFormLayout>
    </PageLayout>
  )
}

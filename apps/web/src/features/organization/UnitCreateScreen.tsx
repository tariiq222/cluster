import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { ArrowLeft, ArrowRight, Building2 } from 'lucide-react'
import { useSearchParams } from 'react-router-dom'
import { useLocale, useSessionToken } from '../../app/session-context'
import { useNavigate } from '../../app/navigation-context'
import { useCluster, useOrganizationUnits } from '../../api/hooks'
import { ApiError, requestInit, unwrap } from '../../api/http'
import * as generated from '../../api/generated/cluster'
import { PageHeader, PageLayout } from '@/components/page-layout'
import { FormSection, SingleRegionFormLayout } from '@/components/form-page-layout'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { DeniedState, EmptyState, LoadingState } from '@/components/states'
import { organizationCopy } from './organization-copy'
import { CODE_PATTERN, displayName, unitTypes, useCapabilities } from './organization-utils'

/*
 * Full-page replacement for the former UnitSheet
 * (route `/organization/units/new`).
 *
 * `?parentId=` preselects the parent unit; without it the unit is created
 * at cluster level. The cluster root is required — a missing cluster
 * renders the shared empty state with the return link.
 */
export function UnitCreateScreen() {
  const locale = useLocale()
  const token = useSessionToken()
  const navigate = useNavigate()
  const text = organizationCopy[locale]
  const capabilities = useCapabilities()
  const [searchParams] = useSearchParams()
  const clusterQuery = useCluster()
  const unitsQuery = useOrganizationUnits()

  const canManage = capabilities.includes('organization.unit.manage')

  const clusterMissing = clusterQuery.error instanceof ApiError && clusterQuery.error.status === 404
  const cluster = clusterMissing ? null : ((clusterQuery.data as generated.Cluster | null) ?? null)
  const units = (unitsQuery.data as generated.OrganizationUnitCollection | undefined)?.items ?? []

  const [parentId, setParentId] = useState(() => searchParams.get('parentId') ?? '')
  const [typeCode, setTypeCode] = useState('department')
  const [code, setCode] = useState('')
  const [name, setName] = useState('')
  const [failure, setFailure] = useState<'validation' | 'save' | null>(null)

  const mutation = useMutation({
    mutationFn: async ({ nextParentId, nextTypeCode, nextCode, nextName }: { nextParentId: string; nextTypeCode: string; nextCode: string; nextName: string }) =>
      unwrap<generated.OrganizationUnit>(
        await generated.createOrganizationUnit(
          {
            cluster_id: cluster!.id,
            parent_id: nextParentId || undefined,
            type_code: nextTypeCode,
            code: nextCode,
            name: nextName,
          },
          requestInit(token, { command: true, idempotency: 'organization-unit' }),
        ),
      ),
    onSuccess: () => {
      navigate('/organization?tab=structure')
    },
    onError: () => setFailure('save'),
  })
  const submitting = mutation.isPending

  const back = () => navigate('/organization?tab=structure')

  if (!canManage) {
    return (
      <PageLayout data-testid="unit-create-screen">
        <DeniedState locale={locale} />
      </PageLayout>
    )
  }

  if (cluster === null) {
    if (clusterQuery.isLoading || unitsQuery.isLoading) {
      return (
        <PageLayout data-testid="unit-create-screen">
          <PageHeader title={text.createUnitTitle} />
          <LoadingState rows={2} announce={text.loading} />
        </PageLayout>
      )
    }
    return (
      <PageLayout data-testid="unit-create-screen">
        <PageHeader title={text.createUnitTitle} />
        <EmptyState
          icon={<Building2 aria-hidden="true" />}
          title={text.noCluster}
          action={<Button onClick={back}>{text.backToStructure}</Button>}
        />
      </PageLayout>
    )
  }

  const parentOptions: Array<{ value: string; label: string }> = [
    { value: '', label: text.rootLevel },
    ...units.map((unit) => ({ value: unit.id, label: displayName(locale, unit) })),
  ]

  return (
    <PageLayout data-testid="unit-create-screen">
      <div>
        <Button variant="ghost" size="sm" onClick={back} className="-ms-2">
          {locale === 'ar' ? <ArrowRight aria-hidden="true" /> : <ArrowLeft aria-hidden="true" />}
          {text.backToStructure}
        </Button>
      </div>

      <PageHeader title={text.createUnitTitle} description={text.unitsAtCluster} />

      <SingleRegionFormLayout
        testId="unit-create-form"
        actionsTestId="unit-create-actions"
        onSubmit={(event) => {
          event.preventDefault()
          if (!name.trim() || !CODE_PATTERN.test(code)) {
            setFailure('validation')
            return
          }
          setFailure(null)
          mutation.mutate({ nextParentId: parentId, nextTypeCode: typeCode, nextCode: code, nextName: name.trim() })
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
        <FormSection headingId="unit-create-details-heading" title={text.detailsSection}>
          <div className="grid gap-2">
            <Label htmlFor="org-unit-parent">{text.parent}</Label>
            <Select value={parentId} onValueChange={setParentId}>
              <SelectTrigger id="org-unit-parent" className="w-full">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {parentOptions.map((option) => (
                  <SelectItem key={option.value} value={option.value}>
                    {option.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          <div className="grid gap-2">
            <Label htmlFor="org-unit-type">{text.unitType}</Label>
            <Select value={typeCode} onValueChange={setTypeCode}>
              <SelectTrigger id="org-unit-type" className="w-full">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {unitTypes.map(([value, key]) => (
                  <SelectItem key={value} value={value}>
                    {text[key]}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          <div className="grid gap-2">
            <Label htmlFor="org-unit-code">{text.code}</Label>
            <Input
              id="org-unit-code"
              dir="ltr"
              value={code}
              aria-invalid={failure === 'validation' || undefined}
              onChange={(event) => setCode(event.target.value.toUpperCase())}
            />
            <p className="text-muted-foreground text-xs">{text.codeHint}</p>
          </div>
          <div className="grid gap-2">
            <Label htmlFor="org-unit-name">{text.nameAr}</Label>
            <Input
              id="org-unit-name"
              value={name}
              aria-invalid={failure === 'validation' || undefined}
              onChange={(event) => setName(event.target.value)}
            />
          </div>
        </FormSection>
      </SingleRegionFormLayout>
    </PageLayout>
  )
}

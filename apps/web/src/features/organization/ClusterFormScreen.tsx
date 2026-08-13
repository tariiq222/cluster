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
import { CODE_PATTERN, useCapabilities } from './organization-utils'

/*
 * Full-page replacement for the former ClusterSheet
 * (routes `/organization/cluster/new` and `/organization/cluster/edit`).
 *
 * The edit path saves against the version used to seed the form. Fetching a
 * newer version at submit time would silently defeat optimistic concurrency;
 * a real conflict instead surfaces a 412 whose retry reloads the seed.
 */
export function ClusterFormScreen({ mode = 'create' }: { mode?: 'create' | 'edit' }) {
  const locale = useLocale()
  const token = useSessionToken()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const text = organizationCopy[locale]
  const capabilities = useCapabilities()
  const editing = mode === 'edit'

  const canManage = capabilities.includes('organization.cluster.manage')

  const [code, setCode] = useState('')
  const [name, setName] = useState('')
  const [nameEn, setNameEn] = useState('')
  const [failure, setFailure] = useState<'validation' | 'stale' | 'save' | null>(null)
  const [seed, setSeed] = useState<generated.Cluster | null>(null)
  const [loading, setLoading] = useState(editing)
  const [loadError, setLoadError] = useState<unknown>(null)

  const load = useCallback(async () => {
    if (!editing) return
    setLoading(true)
    setLoadError(null)
    try {
      const fresh = unwrap<generated.Cluster>(await generated.getCluster(requestInit(token)))
      setSeed(fresh)
      setName(fresh.name_ar)
      setFailure(null)
    } catch (caught) {
      setLoadError(caught)
    } finally {
      setLoading(false)
    }
  }, [editing, token])

  useEffect(() => {
    void load()
  }, [load])

  const mutation = useMutation({
    mutationFn: async ({ nextCode, nextName, nextNameEn }: { nextCode: string; nextName: string; nextNameEn: string }) => {
      if (editing) {
        if (!seed) throw new Error('cluster_edit_seed_missing')
        return unwrap<generated.Cluster>(
          await generated.updateCluster(
            { name: nextName },
            requestInit(token, { command: true, idempotency: 'cluster-update', lockVersion: seed.lock_version }),
          ),
        )
      }
      return unwrap<generated.Cluster>(
        await generated.createCluster(
          { code: nextCode, name: nextName, name_en: nextNameEn.trim() || null },
          requestInit(token, { command: true, idempotency: 'cluster' }),
        ),
      )
    },
    onSuccess: (updated) => {
      queryClient.setQueryData(['cluster'], updated)
      navigate('/organization?tab=cluster')
    },
    onError: (caught) => {
      setFailure(caught instanceof ApiError && caught.status === 412 ? 'stale' : 'save')
    },
  })
  const submitting = mutation.isPending

  const back = () => navigate('/organization?tab=cluster')

  if (!canManage) {
    return (
      <PageLayout data-testid="cluster-form-screen">
        <DeniedState locale={locale} />
      </PageLayout>
    )
  }

  if (editing && loading) {
    return (
      <PageLayout data-testid="cluster-form-screen">
        <PageHeader title={text.editClusterTitle} />
        <LoadingState rows={2} announce={text.loading} />
      </PageLayout>
    )
  }

  if (editing && loadError) {
    return (
      <PageLayout data-testid="cluster-form-screen">
        <PageHeader title={text.editClusterTitle} />
        <ErrorState onRetry={() => void load()} correlationId={loadError instanceof ApiError ? loadError.correlationId : null} locale={locale} />
      </PageLayout>
    )
  }

  if (editing && !loading && !loadError && !seed) {
    return (
      <PageLayout data-testid="cluster-form-screen">
        <PageHeader title={text.editClusterTitle} />
        <EmptyState title={text.noCluster} action={<Button onClick={back}>{text.backToCluster}</Button>} />
      </PageLayout>
    )
  }

  const failureMessage =
    failure === 'validation' ? text.validation : failure === 'stale' ? text.stale : failure === 'save' ? text.saveError : null

  return (
    <PageLayout data-testid="cluster-form-screen">
      <div>
        <Button variant="ghost" size="sm" onClick={back} className="-ms-2">
          {locale === 'ar' ? <ArrowRight aria-hidden="true" /> : <ArrowLeft aria-hidden="true" />}
          {text.backToCluster}
        </Button>
      </div>

      <PageHeader title={editing ? text.editClusterTitle : text.createClusterTitle} description={text.cluster} />

      <TwoRegionFormLayout
        testId="cluster-form"
        mainTestId="cluster-main"
        reviewTestId="cluster-review"
        onSubmit={(event) => {
          event.preventDefault()
          if (!name.trim() || (!editing && !CODE_PATTERN.test(code))) {
            setFailure('validation')
            return
          }
          setFailure(null)
          mutation.mutate({ nextCode: code, nextName: name.trim(), nextNameEn: nameEn })
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
            <FormSection headingId="cluster-details-heading" title={text.detailsSection}>
              {!editing ? (
                <div className="grid gap-2">
                  <Label htmlFor="org-cluster-code">{text.code}</Label>
                  <Input
                    id="org-cluster-code"
                    dir="ltr"
                    value={code}
                    aria-invalid={failure === 'validation' || undefined}
                    onChange={(event) => setCode(event.target.value.toUpperCase())}
                  />
                  <p className="text-muted-foreground text-xs">{text.codeHint}</p>
                </div>
              ) : null}
              <div className="grid gap-2">
                <Label htmlFor="org-cluster-name">{text.nameAr}</Label>
                <Input
                  id="org-cluster-name"
                  value={name}
                  aria-invalid={failure === 'validation' || undefined}
                  onChange={(event) => setName(event.target.value)}
                />
              </div>
              {!editing ? (
                <div className="grid gap-2">
                  <Label htmlFor="org-cluster-name-en">{text.nameEn}</Label>
                  <Input id="org-cluster-name-en" value={nameEn} onChange={(event) => setNameEn(event.target.value)} />
                </div>
              ) : null}
            </FormSection>
          </div>
        }
        review={
          <div className="grid gap-4">
            <FormSection headingId="cluster-review-heading" title={text.reviewTitle} density="tight">
              <ReviewSummary
                testId="cluster-review-summary"
                rows={[
                  ...(editing
                    ? []
                    : [{ label: text.code, value: code, empty: text.notProvided, isolate: true }]),
                  { label: text.nameAr, value: name, empty: text.notProvided, isolate: true },
                  ...(editing
                    ? []
                    : [{ label: text.nameEn, value: nameEn, empty: text.notProvided, isolate: true }]),
                ]}
              />
            </FormSection>
            <FormActionStack testId="cluster-actions">
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

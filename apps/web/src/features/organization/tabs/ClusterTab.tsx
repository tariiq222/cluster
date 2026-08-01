import { useEffect, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Building2, Pencil } from 'lucide-react'
import { useLocale, useSessionToken } from '../../../app/session-context'
import { useCluster } from '../../../api/hooks'
import { ApiError, requestInit, unwrap } from '../../../api/http'
import * as generated from '../../../api/generated/cluster'
import { EmptyState } from '@/components/states'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
} from '@/components/ui/sheet'
import { organizationCopy } from '../organization-copy'
import { CODE_PATTERN, displayName, useCapabilities } from '../organization-utils'

export function ClusterTab() {
  const locale = useLocale()
  const text = organizationCopy[locale]
  const capabilities = useCapabilities()
  const clusterQuery = useCluster()
  const [notice, setNotice] = useState<string | null>(null)
  const [drawer, setDrawer] = useState<'closed' | 'create' | 'edit'>('closed')

  const canManage = capabilities.includes('organization.cluster.manage')
  const clusterMissing = clusterQuery.error instanceof ApiError && clusterQuery.error.status === 404
  const cluster = clusterMissing ? null : ((clusterQuery.data as generated.Cluster | null) ?? null)
  const loading = clusterQuery.isLoading
  const loadError = clusterQuery.error && !clusterMissing ? clusterQuery.error : null

  if (loading) {
    return (
      <Card>
        <CardContent className="space-y-3 py-4">
          <div className="h-10 w-full animate-pulse rounded-md bg-muted" />
          <div className="h-10 w-full animate-pulse rounded-md bg-muted" />
        </CardContent>
      </Card>
    )
  }

  if (loadError) {
    return (
      <EmptyState
        icon={<Building2 aria-hidden="true" />}
        title={text.error}
        action={
          <Button variant="outline" size="sm" onClick={() => void clusterQuery.refetch()}>
            {text.retry}
          </Button>
        }
      />
    )
  }

  if (!cluster) {
    // 404 on the cluster is the expected setup path — render a create empty
    // state, never an error alert.
    return (
      <EmptyState
        icon={<Building2 aria-hidden="true" />}
        title={text.noCluster}
        action={
          canManage ? (
            <Button onClick={() => setDrawer('create')}>{text.addCluster}</Button>
          ) : null
        }
      />
    )
  }

  return (
    <>
      {notice ? <p role="status">{notice}</p> : null}
      <Card>
        <CardHeader className="flex flex-row items-center justify-between gap-2">
          <h2 className="text-base font-medium leading-snug">{text.cluster}</h2>
          {canManage ? (
            <Button variant="outline" size="sm" onClick={() => setDrawer('edit')}>
              <Pencil aria-hidden="true" />
              {text.editCluster}
            </Button>
          ) : null}
        </CardHeader>
        <CardContent>
          <dl className="grid gap-x-6 gap-y-3 sm:grid-cols-2">
            <div>
              <dt className="text-muted-foreground text-sm">{text.nameAr}</dt>
              <dd className="mt-1 text-sm font-medium">{displayName(locale, cluster)}</dd>
            </div>
            <div>
              <dt className="text-muted-foreground text-sm">{text.identifier}</dt>
              <dd className="mt-1 font-mono text-sm" dir="ltr">{cluster.code}</dd>
            </div>
          </dl>
        </CardContent>
      </Card>

      <ClusterSheet
        open={drawer !== 'closed'}
        cluster={drawer === 'edit' ? cluster : null}
        onClose={() => setDrawer('closed')}
        onSaved={() => {
          setDrawer('closed')
          setNotice(text.clusterSaved)
        }}
      />
    </>
  )
}

function ClusterSheet({
  open,
  cluster,
  onClose,
  onSaved,
}: {
  open: boolean
  cluster: generated.Cluster | null
  onClose: () => void
  onSaved: (cluster: generated.Cluster) => void
}) {
  const locale = useLocale()
  const token = useSessionToken()
  const text = organizationCopy[locale]
  const queryClient = useQueryClient()
  const editing = cluster !== null
  const [code, setCode] = useState('')
  const [name, setName] = useState('')
  const [nameEn, setNameEn] = useState('')
  const [failure, setFailure] = useState<'validation' | 'stale' | 'save' | null>(null)

  useEffect(() => {
    if (!open) return
    setCode('')
    setName(cluster?.name_ar ?? '')
    setNameEn(cluster?.name_en ?? '')
    setFailure(null)
  }, [open, cluster])

  const mutation = useMutation({
    mutationFn: async ({ nextCode, nextName, nextNameEn }: { nextCode: string; nextName: string; nextNameEn: string }) => {
      if (editing && cluster) {
        const fresh = unwrap<generated.Cluster>(await generated.getCluster(requestInit(token)))
        return unwrap<generated.Cluster>(
          await generated.updateCluster(
            { name: nextName },
            requestInit(token, { command: true, idempotency: 'cluster-update', lockVersion: fresh.lock_version }),
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
    onSuccess: (saved) => {
      void queryClient.invalidateQueries({ queryKey: ['cluster'] })
      onSaved(saved)
    },
    onError: (caught) => {
      setFailure(caught instanceof ApiError && caught.status === 412 ? 'stale' : 'save')
    },
  })
  const submitting = mutation.isPending

  const failureMessage =
    failure === 'validation' ? text.validation : failure === 'stale' ? text.stale : failure === 'save' ? text.saveError : null

  return (
    <Sheet open={open} onOpenChange={(next) => { if (!next && !submitting) onClose() }}>
      <SheetContent>
        <SheetHeader>
          <SheetTitle>{editing ? text.editClusterTitle : text.createClusterTitle}</SheetTitle>
          <SheetDescription>{text.cluster}</SheetDescription>
        </SheetHeader>
        <form
          className="grid gap-4"
          onSubmit={(event) => {
            event.preventDefault()
            if (!name.trim() || (!editing && !CODE_PATTERN.test(code))) {
              setFailure('validation')
              return
            }
            setFailure(null)
            mutation.mutate({ nextCode: code, nextName: name.trim(), nextNameEn: nameEn })
          }}
          noValidate
        >
          {failureMessage ? <p className="text-destructive text-sm" role="alert">{failureMessage}</p> : null}
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
          <div className="flex justify-end gap-2">
            <Button type="button" variant="outline" onClick={onClose} disabled={submitting}>
              {text.cancel}
            </Button>
            <Button type="submit" disabled={submitting}>
              {submitting ? text.saving : text.save}
            </Button>
          </div>
        </form>
      </SheetContent>
    </Sheet>
  )
}

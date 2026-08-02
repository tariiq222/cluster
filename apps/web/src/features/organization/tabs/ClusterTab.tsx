import { Building2, Pencil } from 'lucide-react'
import { useLocale } from '../../../app/session-context'
import { useNavigate } from '../../../app/navigation-context'
import { useCluster } from '../../../api/hooks'
import { ApiError } from '../../../api/http'
import * as generated from '../../../api/generated/cluster'
import { EmptyState } from '@/components/states'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader } from '@/components/ui/card'
import { organizationCopy } from '../organization-copy'
import { displayName, useCapabilities } from '../organization-utils'

export function ClusterTab() {
  const locale = useLocale()
  const text = organizationCopy[locale]
  const navigate = useNavigate()
  const capabilities = useCapabilities()
  const clusterQuery = useCluster()

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
            <Button onClick={() => navigate('/organization/cluster/new')}>{text.addCluster}</Button>
          ) : null
        }
      />
    )
  }

  return (
    <Card>
      <CardHeader className="flex flex-row items-center justify-between gap-2">
        <h2 className="text-base font-medium leading-snug">{text.cluster}</h2>
        {canManage ? (
          <Button variant="outline" size="sm" onClick={() => navigate('/organization/cluster/edit')}>
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
  )
}

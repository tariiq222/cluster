import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { ChevronDown, ListOrdered, Plus } from 'lucide-react'
import { useLocale, useSessionToken } from '../../../app/session-context'
import { useCluster, useFacilities, useJobTitles, useOrganizationUnits, usePositions } from '../../../api/hooks'
import { ApiError, requestInit, stateFromError, unwrap } from '../../../api/http'
import { formatNumber } from '../../../i18n'
import * as generated from '../../../api/generated/cluster'
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog'
import { Button } from '@/components/ui/button'
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from '@/components/ui/collapsible'
import { organizationCopy } from '../organization-copy'
import { displayName, useCapabilities } from '../organization-utils'
import { UnitSheet } from './sheets'

interface UnitNode {
  unit: generated.OrganizationUnit
  children: UnitNode[]
}

function buildUnitForest(
  units: generated.OrganizationUnit[],
  facilities: generated.Facility[],
): {
  roots: UnitNode[]
  byFacility: Map<string, UnitNode[]>
  unitById: Map<string, generated.OrganizationUnit>
} {
  const byParent = new Map<string, generated.OrganizationUnit[]>()
  const unitById = new Map<string, generated.OrganizationUnit>()
  for (const unit of units) {
    unitById.set(unit.id, unit)
    const key = unit.parent_id ?? ''
    const siblings = byParent.get(key) ?? []
    siblings.push(unit)
    byParent.set(key, siblings)
  }
  const attach = (parentId: string): UnitNode[] =>
    (byParent.get(parentId) ?? [])
      .slice()
      .sort((a, b) => a.name_ar.localeCompare(b.name_ar, 'ar'))
      .map((unit) => ({ unit, children: attach(unit.id) }))
  const topLevel = (byParent.get('') ?? [])
    .slice()
    .sort((a, b) => a.name_ar.localeCompare(b.name_ar, 'ar'))
    .map((unit) => ({ unit, children: attach(unit.id) }))
  const byFacility = new Map<string, UnitNode[]>()
  for (const facility of facilities) {
    const children = (byParent.get(`facility:${facility.id}`) ?? [])
      .slice()
      .sort((a, b) => a.name_ar.localeCompare(b.name_ar, 'ar'))
      .map((unit) => ({ unit, children: attach(unit.id) }))
    if (children.length > 0) byFacility.set(facility.id, children)
  }
  return { roots: topLevel, byFacility, unitById }
}

export function StructureTab() {
  const locale = useLocale()
  const token = useSessionToken()
  const text = organizationCopy[locale]
  const capabilities = useCapabilities()
  const queryClient = useQueryClient()
  const clusterQuery = useCluster()
  const facilitiesQuery = useFacilities()
  const unitsQuery = useOrganizationUnits()
  const positionsQuery = usePositions()
  const jobTitlesQuery = useJobTitles()
  const [notice, setNotice] = useState<string | null>(null)
  const [reorderConfirmOpen, setReorderConfirmOpen] = useState(false)
  const [unitSheetOpen, setUnitSheetOpen] = useState(false)

  const canManageUnit = capabilities.includes('organization.unit.manage')

  const cluster = (clusterQuery.data as generated.Cluster | undefined) ?? null
  const facilities = (facilitiesQuery.data as generated.FacilityCollection | undefined)?.items ?? []
  const units = (unitsQuery.data as generated.OrganizationUnitCollection | undefined)?.items ?? []
  const positions = (positionsQuery.data as generated.PositionCollection | undefined)?.items ?? []
  const jobTitles = (jobTitlesQuery.data as generated.JobTitleCollection | undefined)?.items ?? []
  const loading =
    clusterQuery.isLoading || facilitiesQuery.isLoading || unitsQuery.isLoading || positionsQuery.isLoading || jobTitlesQuery.isLoading
  const loadError =
    clusterQuery.error ?? facilitiesQuery.error ?? unitsQuery.error ?? positionsQuery.error ?? jobTitlesQuery.error
  const state = loadError ? (stateFromError(loadError) === 'forbidden' ? 'forbidden' : 'error') : 'ready'
  const retry = () => {
    void clusterQuery.refetch()
    void facilitiesQuery.refetch()
    void unitsQuery.refetch()
    void positionsQuery.refetch()
    void jobTitlesQuery.refetch()
  }

  const reorderMutation = useMutation({
    mutationFn: async () => {
      if (cluster === null) throw new Error('Cluster is not available')
      return unwrap<{ updated: number; policy: string }>(
        await generated.reorderOrganizationUnits(
          { ordered_unit_ids: [] },
          requestInit(token, { command: true, idempotency: 'organization-units-reorder', lockVersion: cluster.lock_version }),
        ),
      )
    },
    onSuccess: (result) => {
      setNotice(text.reorderSuccess(formatNumber(result.updated, locale)))
      void queryClient.invalidateQueries({ queryKey: ['cluster'] })
      void queryClient.invalidateQueries({ queryKey: ['facilities'] })
      void queryClient.invalidateQueries({ queryKey: ['organization-units'] })
      void queryClient.invalidateQueries({ queryKey: ['positions'] })
      void queryClient.invalidateQueries({ queryKey: ['job-titles'] })
    },
    onError: (caught) => {
      if (caught instanceof ApiError && caught.status === 412) {
        setNotice(text.reorderStale)
        void queryClient.invalidateQueries({ queryKey: ['cluster'] })
        void queryClient.invalidateQueries({ queryKey: ['facilities'] })
        void queryClient.invalidateQueries({ queryKey: ['organization-units'] })
        void queryClient.invalidateQueries({ queryKey: ['positions'] })
        void queryClient.invalidateQueries({ queryKey: ['job-titles'] })
      } else {
        setNotice(text.reorderFailed)
      }
    },
  })
  const reordering = reorderMutation.isPending

  const canRead = capabilities.includes('organization.unit.read')
  if (!canRead) return <p className="text-muted-foreground text-sm">{text.unavailable}</p>

  const forest = buildUnitForest(units, facilities)

  return (
    <div className="space-y-4">
      {notice ? <p role="status">{notice}</p> : null}
      <div className="flex gap-2">
        <Button size="sm" onClick={() => setReorderConfirmOpen(true)} disabled={reordering || units.length === 0 || cluster === null}>
          <ListOrdered aria-hidden="true" />
          {reordering ? text.reorderBusy : text.reorder}
        </Button>
        {canManageUnit ? (
          <Button size="sm" variant="outline" onClick={() => setUnitSheetOpen(true)}>
            <Plus aria-hidden="true" />
            {text.addUnit}
          </Button>
        ) : null}
      </div>

      {loading ? (
        <div className="space-y-3">
          <div className="h-10 w-full animate-pulse rounded-md bg-muted" />
          <div className="h-10 w-full animate-pulse rounded-md bg-muted" />
        </div>
      ) : null}
      {!loading && state === 'forbidden' ? <p role="status">{text.unavailable}</p> : null}
      {!loading && state === 'error' ? (
        <p className="text-destructive text-sm" role="alert">
          {text.error}
          <Button variant="outline" size="sm" className="ms-2" onClick={retry}>
            {text.retry}
          </Button>
        </p>
      ) : null}

      {!loading && state === 'ready' ? (
        <div className="space-y-4">
          {forest.roots.length === 0 && forest.byFacility.size === 0 ? (
            <p className="text-muted-foreground py-8 text-center text-sm">{text.noUnits}</p>
          ) : null}
          {forest.roots.length > 0 ? (
            <UnitTreeSection title={text.unitsAtCluster} nodes={forest.roots} positions={positions} jobTitles={jobTitles} />
          ) : null}
          {facilities.map((facility) => {
            const nodes = forest.byFacility.get(facility.id)
            if (!nodes || nodes.length === 0) return null
            return (
              <UnitTreeSection
                key={facility.id}
                title={displayName(locale, facility)}
                nodes={nodes}
                positions={positions}
                jobTitles={jobTitles}
              />
            )
          })}
        </div>
      ) : null}

      <AlertDialog open={reorderConfirmOpen} onOpenChange={setReorderConfirmOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{text.reorder}</AlertDialogTitle>
            <AlertDialogDescription>{text.reorderConfirm}</AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>{text.cancel}</AlertDialogCancel>
            <AlertDialogAction
              onClick={() => {
                setReorderConfirmOpen(false)
                setNotice(null)
                reorderMutation.mutate()
              }}
            >
              {text.confirm}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      {cluster ? (
        <UnitSheet
          open={unitSheetOpen}
          onClose={() => setUnitSheetOpen(false)}
          cluster={cluster}
          units={units}
          onSaved={() => {
            setUnitSheetOpen(false)
            setNotice(text.unitSaved)
          }}
        />
      ) : null}
    </div>
  )
}

function UnitTreeSection({
  title,
  nodes,
  positions,
  jobTitles,
}: {
  title: string
  nodes: UnitNode[]
  positions: generated.Position[]
  jobTitles: generated.JobTitle[]
}) {
  return (
    <div className="rounded-lg border p-3">
      <h3 className="mb-2 text-sm font-medium">{title}</h3>
      <div className="space-y-1">
        {nodes.map((node) => (
          <UnitTree key={node.unit.id} node={node} depth={0} positions={positions} jobTitles={jobTitles} />
        ))}
      </div>
    </div>
  )
}

function UnitTree({
  node,
  depth,
  positions,
  jobTitles,
}: {
  node: UnitNode
  depth: number
  positions: generated.Position[]
  jobTitles: generated.JobTitle[]
}) {
  const locale = useLocale()
  const unitPositions = positions.filter((position) => position.organization_unit_id === node.unit.id)
  const hasChildren = node.children.length > 0 || unitPositions.length > 0
  const [open, setOpen] = useState(depth < 1)

  return (
    <Collapsible open={hasChildren ? open : true} onOpenChange={setOpen} className="rounded-md">
      <CollapsibleTrigger
        className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-start text-sm hover:bg-muted"
        disabled={!hasChildren}
      >
        {hasChildren ? (
          <ChevronDown aria-hidden="true" className={`size-4 shrink-0 transition-transform ${open ? '' : '-rotate-90'}`} />
        ) : (
          <span className="size-4 shrink-0" />
        )}
        <span className="font-medium">{displayName(locale, node.unit)}</span>
        <span className="text-muted-foreground text-xs" dir="ltr">
          {node.unit.code}
        </span>
      </CollapsibleTrigger>
      <CollapsibleContent>
        <div className="ms-5 space-y-1 border-s ps-2">
          {unitPositions.map((position) => (
            <div key={position.id} className="flex items-center gap-2 px-2 py-1 text-sm">
              <span>{position.title_ar}</span>
              {position.job_title_id ? (
                <span className="text-muted-foreground text-xs">
                  {jobTitles.find((title) => title.id === position.job_title_id)?.title_ar ?? ''}
                </span>
              ) : null}
            </div>
          ))}
          {node.children.map((child) => (
            <UnitTree key={child.unit.id} node={child} depth={depth + 1} positions={positions} jobTitles={jobTitles} />
          ))}
        </div>
      </CollapsibleContent>
    </Collapsible>
  )
}

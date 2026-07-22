import { useEffect, useState } from 'react'
import { useLocale, useToken } from '../../app/session-context'
import { ArrowDownNarrowWide, Plus } from 'lucide-react'

import {
  ApiError,
  getCluster,
  listFacilities,
  listOrganizationUnits,
  listJobTitles,
  listPositions,
  reorderOrganizationUnits,
  updateOrganizationUnit,
  updatePosition,
  type Cluster,
  type Facility,
  type JobTitle,
  type OrganizationUnit,
  type Position,
  type UpdateOrganizationUnitInput,
  type UpdatePositionInput,
  stateFromError,
} from '../../api'
import {
  Button,
  InlineError,
  Page,
  PageHeader,
  SkeletonList,
} from '../../ui'
import { AddPositionDrawer } from './AddPositionDrawer'
import { AddUnitDrawer } from './AddUnitDrawer'
import { OrganizationBoard } from './OrganizationBoard'

const copy = {
  ar: {
    title: 'الهيكل التنظيمي',
    intro: 'استعرض الوحدات والمناصب، واختر أي عنصر لعرض تفاصيله.',
    loading: 'جارٍ تحميل الهيكل التنظيمي…',
    forbidden: 'لا تملك صلاحية إدارة الهيكل التنظيمي.',
    error: 'تعذر تحميل الهيكل التنظيمي.',
    retry: 'إعادة المحاولة',
    addUnit: 'إضافة إدارة أو قسم',
    reorder: 'ترتيب الوحدات',
    reorderConfirm:
      'سيُرتَّب كل مستوى من الوحدات بحسب النوع ثم الاسم. هل تريد المتابعة؟',
    reorderBusy: 'جارٍ ترتيب الوحدات…',
    reorderFailed: 'تعذّر ترتيب الوحدات. أعد المحاولة.',
    reorderSuccess: (count: number) => `تم ترتيب ${count} وحدة.`,
  },
  en: {
    title: 'Organization structure',
    intro: 'View units and positions, then select an item to see its details.',
    loading: 'Loading organization structure…',
    forbidden: 'You do not have permission to manage organization structure.',
    error: 'Organization structure could not be loaded.',
    retry: 'Try again',
    addUnit: 'Add department or section',
    reorder: 'Arrange units',
    reorderConfirm:
      'This will arrange units at each level by type, then name. Continue?',
    reorderBusy: 'Arranging units…',
    reorderFailed: 'The units could not be arranged. Try again.',
    reorderSuccess: (count: number) => `${count} units arranged.`,
  },
} as const

export function OrganizationStructure() {
  const locale = useLocale()
  const token = useToken()
  const text = copy[locale]
  const [cluster, setCluster] = useState<Cluster | null>(null)
  const [facilities, setFacilities] = useState<Facility[]>([])
  const [units, setUnits] = useState<OrganizationUnit[]>([])
  const [positions, setPositions] = useState<Position[]>([])
  const [jobTitles, setJobTitles] = useState<JobTitle[]>([])
  const [loading, setLoading] = useState(true)
  const [state, setState] = useState<'ready' | 'forbidden' | 'error'>('ready')
  const [reordering, setReordering] = useState(false)
  const [reorderStatus, setReorderStatus] = useState<{
    kind: 'ok' | 'error'
    message: string
  } | null>(null)
  const [selectedUnitId, setSelectedUnitId] = useState<string | null>(null)
  const [unitDrawerOpen, setUnitDrawerOpen] = useState(false)
  const [positionDrawerOpen, setPositionDrawerOpen] = useState(false)
  const [preselectedParentId, setPreselectedParentId] = useState<
    string | undefined
  >(undefined)
  const [preselectedPositionUnitId, setPreselectedPositionUnitId] = useState<
    string | undefined
  >(undefined)

  async function load() {
    setLoading(true)
    setState('ready')
    try {
      const [clusterValue, facilityPage, unitPage, positionPage, jobTitlePage] =
        await Promise.all([
          getCluster(token),
          listFacilities(token),
          listOrganizationUnits(token),
          listPositions(token),
          listJobTitles(token),
        ])
      setCluster(clusterValue)
      setFacilities(facilityPage.items)
      setUnits(unitPage.items)
      setPositions(positionPage.items)
      setJobTitles(jobTitlePage.items)
      setSelectedUnitId((current) =>
        current === null || unitPage.items.some((unit) => unit.id === current)
          ? current
          : null,
      )
    } catch (error) {
      setCluster(null)
      setFacilities([])
      setUnits([])
      setPositions([])
      setSelectedUnitId(null)
      setState(stateFromError(error) === 'forbidden' ? 'forbidden' : 'error')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    void load()
    // The structure page reloads only when the authenticated session changes.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [token])

  function selectUnit(unitId: string | null) {
    setSelectedUnitId(unitId)
    if (unitId !== null) setPositionDrawerOpen(false)
  }

  function handleUnitCreated(unit: OrganizationUnit) {
    setUnits((current) => {
      const next = [...current, unit]
      setSelectedUnitId(unit.id)
      return next
    })
    setUnitDrawerOpen(false)
    setPreselectedParentId(undefined)
  }

  function handlePositionCreated(position: Position) {
    setPositions((current) => [...current, position])
    setPositionDrawerOpen(false)
    setPreselectedPositionUnitId(undefined)
  }

  async function handleReorder() {
    if (reordering) return
    const confirmed =
      typeof window !== 'undefined' && typeof window.confirm === 'function'
        ? window.confirm(text.reorderConfirm)
        : true
    if (!confirmed) return
    setReordering(true)
    setReorderStatus(null)
    try {
      const result = await reorderOrganizationUnits(token)
      if (
        typeof window !== 'undefined' &&
        typeof window.localStorage !== 'undefined'
      ) {
        window.localStorage.removeItem('cluster.org-board.layout.v2')
      }
      setReorderStatus({
        kind: 'ok',
        message: text.reorderSuccess(result.updated),
      })
      await load()
    } catch (error) {
      setReorderStatus({
        kind: 'error',
        message:
          error instanceof ApiError ? text.reorderFailed : text.reorderFailed,
      })
    } finally {
      setReordering(false)
    }
  }

  function openAddUnitForParent(parentId?: string) {
    setPreselectedParentId(parentId)
    setUnitDrawerOpen(true)
  }

  function openAddPositionForUnit(unitId: string) {
    setPreselectedPositionUnitId(unitId)
    setPositionDrawerOpen(true)
  }

  async function handleUnitUpdated(
    unit: OrganizationUnit,
    patch: UpdateOrganizationUnitInput,
  ): Promise<OrganizationUnit> {
    const updated = await updateOrganizationUnit(token, unit.id, unit.lock_version, patch)
    await load()
    return updated
  }

  async function handlePositionUpdated(
    position: Position,
    patch: UpdatePositionInput,
  ): Promise<Position> {
    const updated = await updatePosition(token, position.id, position.lock_version, patch)
    await load()
    return updated
  }

  return (
    <Page>
      <PageHeader
        id="structure-heading"
        title={text.title}
        description={text.intro}
        actions={
          <div className="org-structure-actions">
            <Button
              variant="primary"
              type="button"
              onClick={() => openAddUnitForParent(selectedUnitId ?? undefined)}
              className="org-panel-action"
              aria-label={text.addUnit}
            >
              <Plus aria-hidden="true" />
              <span>{text.addUnit}</span>
            </Button>
            <Button
              variant="secondary"
              type="button"
              onClick={() => void handleReorder()}
              className="org-panel-action"
              aria-label={text.reorder}
              disabled={reordering || units.length === 0}
            >
              <ArrowDownNarrowWide aria-hidden="true" />
              <span>{reordering ? text.reorderBusy : text.reorder}</span>
            </Button>
          </div>
        }
      />
      {reorderStatus !== null && (
        <div
          className={
            reorderStatus.kind === 'ok' ? 'state-panel' : 'org-structure-error'
          }
          role="status"
          aria-live="polite"
        >
          <p>{reorderStatus.message}</p>
        </div>
      )}
      {loading && <SkeletonList label={text.loading} />}
      {!loading && state === 'forbidden' && (
        <div className="state-panel" role="status">
          <p>{text.forbidden}</p>
        </div>
      )}
      {!loading && state === 'error' && (
        <InlineError
          message={text.error}
          retryLabel={text.retry}
          onRetry={() => void load()}
        />
      )}
      {!loading && state === 'ready' && cluster && (
        <div className="org-structure-board-host">
          <OrganizationBoard
            locale={locale}
            units={units}
            facilities={facilities}
            positions={positions}
            selectedUnitId={selectedUnitId}
            onSelectUnit={selectUnit}
            onAddChild={(parentId) => openAddUnitForParent(parentId)}
            onAddPosition={(unitId) => openAddPositionForUnit(unitId)}
            onUpdateUnit={handleUnitUpdated}
            onUpdatePosition={handlePositionUpdated}
          />
          <AddUnitDrawer
            open={unitDrawerOpen}
            onClose={() => {
              setUnitDrawerOpen(false)
              setPreselectedParentId(undefined)
            }}
            locale={locale}
            token={token}
            cluster={cluster}
            facilities={facilities}
            units={units}
            preselectedParentId={preselectedParentId}
            onCreated={handleUnitCreated}
          />
          <AddPositionDrawer
            open={positionDrawerOpen}
            onClose={() => {
              setPositionDrawerOpen(false)
              setPreselectedPositionUnitId(undefined)
            }}
            locale={locale}
            token={token}
            units={units}
            positions={positions}
            jobTitles={jobTitles}
            preselectedUnitId={
              preselectedPositionUnitId ?? selectedUnitId ?? undefined
            }
            onCreated={handlePositionCreated}
          />
        </div>
      )}
    </Page>
  )
}

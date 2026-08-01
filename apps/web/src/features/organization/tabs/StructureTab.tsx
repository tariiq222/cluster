import { useEffect, useState, type FormEvent } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useLocale, useSessionToken } from '../../../app/session-context'
import {
  useCluster,
  useFacilities,
  useJobTitles,
  useOrganizationUnits,
  usePositions,
} from '../../../api/hooks'
import { ApiError, requestInit, stateFromError, unwrap } from '../../../api/http'
import { formatNumber, type Locale } from '../../../i18n'
import {
  Button,
  Drawer,
  EmptyState,
  Field,
  InlineError,
  Panel,
  Select,
  SkeletonList,
  StatusBadge,
} from '../../../ui'
import * as generated from '../../../api/generated/cluster'
import { organizationCopy } from '../organization-copy'
import {
  CODE_PATTERN,
  displayName,
  unitTypes,
  useCapabilities,
} from '../organization-utils'

/* ------------------------------------------------------------------ */
/* Structure tab                                                       */
/* ------------------------------------------------------------------ */

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
  const unitById = new Map(units.map((unit) => [unit.id, unit]))
  const byParent = new Map<string, generated.OrganizationUnit[]>()
  for (const unit of units) {
    const key = `${unit.parent_type}:${unit.parent_id}`
    const siblings = byParent.get(key) ?? []
    siblings.push(unit)
    byParent.set(key, siblings)
  }
  const attach = (parentId: string): UnitNode[] =>
    (byParent.get(`unit:${parentId}`) ?? [])
      .slice()
      .sort((a, b) => a.name_ar.localeCompare(b.name_ar, 'ar'))
      .map((unit) => ({ unit, children: attach(unit.id) }))
  const topLevel = units
    .filter((unit) => {
      if (unit.parent_type === 'cluster') return true
      if (unit.parent_type === 'facility')
        return !facilities.some((facility) => facility.id === unit.parent_id)
      return !unitById.has(unit.parent_id)
    })
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
  const [unitDrawerOpen, setUnitDrawerOpen] = useState(false)
  const [positionDrawerOpen, setPositionDrawerOpen] = useState(false)
  const [preselectedUnitId, setPreselectedUnitId] = useState<
    string | undefined
  >(undefined)

  const canManageUnit = capabilities.includes('organization.unit.manage')
  const canManagePosition = capabilities.includes(
    'organization.position.manage',
  )

  const cluster = (clusterQuery.data as generated.Cluster | undefined) ?? null
  const facilities =
    (facilitiesQuery.data as generated.FacilityCollection | undefined)?.items ??
    []
  const units =
    (unitsQuery.data as generated.OrganizationUnitCollection | undefined)
      ?.items ?? []
  const positions =
    (positionsQuery.data as generated.PositionCollection | undefined)?.items ??
    []
  const jobTitles =
    (jobTitlesQuery.data as generated.JobTitleCollection | undefined)?.items ??
    []
  const loading =
    clusterQuery.isLoading ||
    facilitiesQuery.isLoading ||
    unitsQuery.isLoading ||
    positionsQuery.isLoading ||
    jobTitlesQuery.isLoading
  const loadError =
    clusterQuery.error ??
    facilitiesQuery.error ??
    unitsQuery.error ??
    positionsQuery.error ??
    jobTitlesQuery.error
  const state: 'ready' | 'forbidden' | 'error' = loadError
    ? stateFromError(loadError) === 'forbidden'
      ? 'forbidden'
      : 'error'
    : 'ready'
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
          requestInit(token, {
            command: true,
            idempotency: 'organization-units-reorder',
            lockVersion: cluster.lock_version,
          }),
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
  if (!canRead) return <EmptyState title={text.unavailable} />

  async function handleReorder() {
    if (reordering || cluster === null) return
    const confirmed =
      typeof window !== 'undefined' && typeof window.confirm === 'function'
        ? window.confirm(text.reorderConfirm)
        : true
    if (!confirmed) return
    setNotice(null)
    reorderMutation.mutate()
  }

  const forest = buildUnitForest(units, facilities)

  return (
    <>
      {notice ? (
        <p role="status" className="status-message status-message--success">
          {notice}
        </p>
      ) : null}
      <div
        className="form-actions"
        style={{ justifyContent: 'flex-start', paddingBlockStart: 0 }}
      >
        {canManageUnit ? (
          <Button onClick={() => setUnitDrawerOpen(true)}>
            {text.addUnit}
          </Button>
        ) : null}
        <Button
          variant="secondary"
          onClick={() => void handleReorder()}
          disabled={reordering || units.length === 0 || cluster === null}
        >
          {reordering ? text.reorderBusy : text.reorder}
        </Button>
      </div>
      {loading ? <SkeletonList rows={3} /> : null}
      {!loading && state === 'forbidden' ? (
        <div className="state-panel" role="status">
          <p>{text.unavailable}</p>
        </div>
      ) : null}
      {!loading && state === 'error' ? (
        <InlineError
          message={text.error}
          retryLabel={text.retry}
          onRetry={retry}
        />
      ) : null}
      {!loading && state === 'ready' ? (
        <div className="screen-list">
          {forest.roots.length === 0 && forest.byFacility.size === 0 ? (
            <EmptyState
              title={text.noUnits}
              action={
                canManageUnit ? (
                  <Button onClick={() => setUnitDrawerOpen(true)}>
                    {text.addUnit}
                  </Button>
                ) : undefined
              }
            />
          ) : null}
          {forest.roots.length > 0 ? (
            <Panel
              id="structure-root-panel-heading"
              title={text.unitsAtCluster}
            >
              <UnitTree
                nodes={forest.roots}
                positions={positions}
                jobTitles={jobTitles}
                canManagePosition={canManagePosition}
                onAddPosition={(unitId) => {
                  setPreselectedUnitId(unitId)
                  setPositionDrawerOpen(true)
                }}
              />
            </Panel>
          ) : null}
          {facilities.map((facility) => {
            const nodes = forest.byFacility.get(facility.id)
            if (!nodes || nodes.length === 0) return null
            return (
              <Panel
                key={facility.id}
                id={`structure-facility-${facility.id}`}
                title={displayName(locale, facility)}
              >
                <UnitTree
                  nodes={nodes}
                  positions={positions}
                  jobTitles={jobTitles}
                  canManagePosition={canManagePosition}
                  onAddPosition={(unitId) => {
                    setPreselectedUnitId(unitId)
                    setPositionDrawerOpen(true)
                  }}
                />
              </Panel>
            )
          })}
        </div>
      ) : null}
      {cluster ? (
        <UnitDrawer
          open={unitDrawerOpen}
          onClose={() => setUnitDrawerOpen(false)}
          cluster={cluster}
          facilities={facilities}
          units={units}
          onSaved={() => {
            setUnitDrawerOpen(false)
            setNotice(text.unitSaved)
          }}
        />
      ) : null}
      <PositionDrawer
        open={positionDrawerOpen}
        onClose={() => {
          setPositionDrawerOpen(false)
          setPreselectedUnitId(undefined)
        }}
        units={units}
        jobTitles={jobTitles}
        preselectedUnitId={preselectedUnitId}
        onSaved={() => {
          setPositionDrawerOpen(false)
          setPreselectedUnitId(undefined)
          setNotice(text.positionSaved)
        }}
      />
    </>
  )
}

function UnitTree({
  nodes,
  positions,
  jobTitles,
  canManagePosition,
  onAddPosition,
  depth = 0,
}: {
  nodes: UnitNode[]
  positions: generated.Position[]
  jobTitles: generated.JobTitle[]
  canManagePosition: boolean
  onAddPosition: (unitId: string) => void
  depth?: number
}) {
  const locale = useLocale()
  const text = organizationCopy[locale]
  if (nodes.length === 0) return null
  return (
    <ul className="screen-list" role="list">
      {nodes.map((node) => (
        <li key={node.unit.id} role="listitem" className="screen-list__row">
          <div>
            <div className="screen-list__row-title">
              {displayName(locale, node.unit)}
              <span className="screen-list__row-meta" dir="ltr">
                {' '}
                · {node.unit.code}
              </span>
            </div>
            <div className="screen-list__row-meta">
              {unitTypeLabel(locale, node.unit.type_code)}
              <StatusBadge
                variant={node.unit.status === 'active' ? 'success' : 'neutral'}
              >
                {unitStatusLabel(locale, node.unit.status)}
              </StatusBadge>
            </div>
            {positions.filter(
              (position) => position.organization_unit_id === node.unit.id,
            ).length > 0 ? (
              <div className="screen-list__row-meta">
                {positions
                  .filter(
                    (position) =>
                      position.organization_unit_id === node.unit.id,
                  )
                  .map((position) => (
                    <StatusBadge key={position.id} variant="info">
                      {position.title_ar}
                      {position.job_title_id
                        ? ` · ${jobTitles.find((title) => title.id === position.job_title_id)?.title_ar ?? ''}`
                        : ''}
                    </StatusBadge>
                  ))}
              </div>
            ) : null}
            {canManagePosition ? (
              <div
                className="form-actions"
                style={{
                  justifyContent: 'flex-start',
                  paddingBlockStart: 'var(--space-2)',
                }}
              >
                <Button
                  variant="quiet"
                  onClick={() => onAddPosition(node.unit.id)}
                >
                  {text.addPosition}
                </Button>
              </div>
            ) : null}
          </div>
        </li>
      ))}
      {nodes.some((node) => node.children.length > 0) ? (
        <li
          role="listitem"
          style={{ padding: 0, border: 'none', background: 'transparent' }}
        >
          <ul
            className="screen-list"
            role="list"
            style={{ paddingInlineStart: 'var(--space-5)' }}
          >
            {nodes.map((node) => (
              <UnitTree
                key={node.unit.id}
                nodes={node.children}
                positions={positions}
                jobTitles={jobTitles}
                canManagePosition={canManagePosition}
                onAddPosition={onAddPosition}
                depth={depth + 1}
              />
            ))}
          </ul>
        </li>
      ) : null}
    </ul>
  )
}

function unitTypeLabel(locale: Locale, typeCode: string): string {
  const text = organizationCopy[locale]
  const match = unitTypes.find(([code]) => code === typeCode)
  return match ? text[match[1]] : typeCode
}

function unitStatusLabel(
  locale: Locale,
  status: generated.OrganizationUnitStatus,
): string {
  const text = organizationCopy[locale]
  return status === 'active'
    ? text.active
    : status === 'inactive'
      ? text.inactive
      : text.archived
}

function UnitDrawer({
  open,
  onClose,
  cluster,
  facilities,
  units,
  onSaved,
}: {
  open: boolean
  onClose: () => void
  cluster: generated.Cluster
  facilities: generated.Facility[]
  units: generated.OrganizationUnit[]
  onSaved: (unit: generated.OrganizationUnit) => void
}) {
  const locale = useLocale()
  const token = useSessionToken()
  const text = organizationCopy[locale]
  const queryClient = useQueryClient()
  const [parentId, setParentId] = useState('')
  const [typeCode, setTypeCode] = useState<string>('department')
  const [code, setCode] = useState('')
  const [name, setName] = useState('')
  const [failure, setFailure] = useState<'validation' | 'save' | null>(null)

  useEffect(() => {
    if (!open) return
    setParentId('')
    setTypeCode('department')
    setCode('')
    setName('')
    setFailure(null)
  }, [open])

  const mutation = useMutation({
    mutationFn: async ({
      nextParentId,
      nextTypeCode,
      nextCode,
      nextName,
    }: {
      nextParentId: string
      nextTypeCode: string
      nextCode: string
      nextName: string
    }) =>
      unwrap<generated.OrganizationUnit>(
        await generated.createOrganizationUnit(
          {
            cluster_id: cluster.id,
            parent_id: nextParentId || undefined,
            type_code: nextTypeCode,
            code: nextCode,
            name: nextName,
          },
          requestInit(token, {
            command: true,
            idempotency: 'organization-unit',
          }),
        ),
      ),
    onSuccess: (created) => {
      void queryClient.invalidateQueries({ queryKey: ['organization-units'] })
      onSaved(created)
    },
    onError: () => setFailure('save'),
  })
  const submitting = mutation.isPending

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!CODE_PATTERN.test(code) || !name.trim()) {
      setFailure('validation')
      return
    }
    setFailure(null)
    mutation.mutate({
      nextParentId: parentId,
      nextTypeCode: typeCode,
      nextCode: code,
      nextName: name.trim(),
    })
  }

  const failureMessage =
    failure === 'validation'
      ? text.validation
      : failure === 'save'
        ? text.saveError
        : null

  return (
    <Drawer
      open={open}
      onClose={() => {
        if (!submitting) onClose()
      }}
      title={text.createUnitTitle}
    >
      <form onSubmit={(event) => void submit(event)} noValidate>
        {failureMessage ? (
          <p className="error-summary" role="alert">
            {failureMessage}
          </p>
        ) : null}
        <Field
          id="org-unit-code"
          label={text.code}
          required
          help={text.codeHint}
        >
          <input
            id="org-unit-code"
            dir="ltr"
            value={code}
            required
            aria-required="true"
            aria-invalid={failure === 'validation' || undefined}
            onChange={(event) => setCode(event.target.value.toUpperCase())}
          />
        </Field>
        <Field id="org-unit-name" label={text.nameAr} required>
          <input
            id="org-unit-name"
            value={name}
            required
            aria-required="true"
            aria-invalid={failure === 'validation' || undefined}
            onChange={(event) => setName(event.target.value)}
          />
        </Field>
        <Field id="org-unit-type" label={text.unitType}>
          <Select
            id="org-unit-type"
            value={typeCode}
            onChange={setTypeCode}
            options={unitTypes.map(([value, key]) => ({
              value,
              label: text[key],
            }))}
          />
        </Field>
        <Field id="org-unit-parent" label={text.parent}>
          <Select
            id="org-unit-parent"
            value={parentId}
            onChange={setParentId}
            options={[
              { value: '', label: text.rootLevel },
              ...facilities.map((facility) => ({
                value: facility.id,
                label: displayName(locale, facility),
              })),
              ...units.map((unit) => ({
                value: unit.id,
                label: displayName(locale, unit),
              })),
            ]}
          />
        </Field>
        <div className="form-actions">
          <Button
            type="button"
            variant="quiet"
            onClick={onClose}
            disabled={submitting}
          >
            {text.cancel}
          </Button>
          <Button type="submit" disabled={submitting}>
            {submitting ? text.saving : text.save}
          </Button>
        </div>
      </form>
    </Drawer>
  )
}

function PositionDrawer({
  open,
  onClose,
  units,
  jobTitles,
  preselectedUnitId,
  onSaved,
}: {
  open: boolean
  onClose: () => void
  units: generated.OrganizationUnit[]
  jobTitles: generated.JobTitle[]
  preselectedUnitId?: string
  onSaved: (position: generated.Position) => void
}) {
  const locale = useLocale()
  const token = useSessionToken()
  const text = organizationCopy[locale]
  const queryClient = useQueryClient()
  const [unitId, setUnitId] = useState('')
  const [code, setCode] = useState('')
  const [title, setTitle] = useState('')
  const [jobTitleId, setJobTitleId] = useState('')
  const [failure, setFailure] = useState<'validation' | 'save' | null>(null)

  useEffect(() => {
    if (!open) return
    setUnitId(preselectedUnitId ?? '')
    setCode('')
    setTitle('')
    setJobTitleId('')
    setFailure(null)
  }, [open, preselectedUnitId])

  const mutation = useMutation({
    mutationFn: async ({
      nextUnitId,
      nextCode,
      nextTitle,
      nextJobTitleId,
    }: {
      nextUnitId: string
      nextCode: string
      nextTitle: string
      nextJobTitleId: string
    }) =>
      unwrap<generated.Position>(
        await generated.createPosition(
          {
            organization_unit_id: nextUnitId,
            code: nextCode,
            title: nextTitle.trim() || undefined,
            job_title_id: nextJobTitleId || null,
          },
          requestInit(token, { command: true, idempotency: 'position' }),
        ),
      ),
    onSuccess: (created) => {
      void queryClient.invalidateQueries({ queryKey: ['positions'] })
      onSaved(created)
    },
    onError: () => setFailure('save'),
  })
  const submitting = mutation.isPending

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!CODE_PATTERN.test(code) || !unitId) {
      setFailure('validation')
      return
    }
    setFailure(null)
    mutation.mutate({
      nextUnitId: unitId,
      nextCode: code,
      nextTitle: title,
      nextJobTitleId: jobTitleId,
    })
  }

  const failureMessage =
    failure === 'validation'
      ? text.validation
      : failure === 'save'
        ? text.saveError
        : null

  return (
    <Drawer
      open={open}
      onClose={() => {
        if (!submitting) onClose()
      }}
      title={text.createPositionTitle}
    >
      <form onSubmit={(event) => void submit(event)} noValidate>
        {failureMessage ? (
          <p className="error-summary" role="alert">
            {failureMessage}
          </p>
        ) : null}
        <Field id="org-position-unit" label={text.parent} required>
          <Select
            id="org-position-unit"
            value={unitId}
            onChange={setUnitId}
            options={units.map((unit) => ({
              value: unit.id,
              label: displayName(locale, unit),
            }))}
          />
        </Field>
        <Field
          id="org-position-code"
          label={text.code}
          required
          help={text.codeHint}
        >
          <input
            id="org-position-code"
            dir="ltr"
            value={code}
            required
            aria-required="true"
            aria-invalid={failure === 'validation' || undefined}
            onChange={(event) => setCode(event.target.value.toUpperCase())}
          />
        </Field>
        <Field id="org-position-title" label={text.positionTitle}>
          <input
            id="org-position-title"
            value={title}
            onChange={(event) => setTitle(event.target.value)}
          />
        </Field>
        <Field id="org-position-job-title" label={text.jobTitle}>
          <Select
            id="org-position-job-title"
            value={jobTitleId}
            onChange={setJobTitleId}
            placeholder={text.close}
            options={jobTitles.map((titleItem) => ({
              value: titleItem.id,
              label: titleItem.title_ar,
            }))}
          />
        </Field>
        <div className="form-actions">
          <Button
            type="button"
            variant="quiet"
            onClick={onClose}
            disabled={submitting}
          >
            {text.cancel}
          </Button>
          <Button type="submit" disabled={submitting}>
            {submitting ? text.saving : text.save}
          </Button>
        </div>
      </form>
    </Drawer>
  )
}

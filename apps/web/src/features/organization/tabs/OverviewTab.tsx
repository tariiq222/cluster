import { useEffect, useState, type FormEvent } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useLocale, useSessionToken } from '../../../app/session-context'
import { useCluster, useFacilities } from '../../../api/hooks'
import { ApiError, requestInit, stateFromError, unwrap } from '../../../api/http'
import { formatNumber, type Locale } from '../../../i18n'
import {
  Button,
  Drawer,
  EmptyState,
  Field,
  InlineError,
  Panel,
  PanelGrid,
  Select,
  SkeletonList,
  StatusBadge,
} from '../../../ui'
import * as generated from '../../../api/generated/cluster'
import { organizationCopy } from '../organization-copy'
import {
  CODE_PATTERN,
  displayName,
  facilityTypes,
  useCapabilities,
} from '../organization-utils'

/* ------------------------------------------------------------------ */
/* Overview tab                                                        */
/* ------------------------------------------------------------------ */
export function OverviewTab() {
  const locale = useLocale()
  const text = organizationCopy[locale]
  const capabilities = useCapabilities()
  const clusterQuery = useCluster()
  const facilitiesQuery = useFacilities()
  const [notice, setNotice] = useState<string | null>(null)
  const [drawer, setDrawer] = useState<
    | { kind: 'closed' }
    | { kind: 'create-cluster' }
    | { kind: 'edit-cluster' }
    | { kind: 'create-facility' }
    | { kind: 'edit-facility'; facility: generated.Facility }
  >({ kind: 'closed' })

  const canManageCluster = capabilities.includes('organization.cluster.manage')
  const canManageFacility = capabilities.includes(
    'organization.facility.manage',
  )

  const clusterMissing =
    clusterQuery.error instanceof ApiError && clusterQuery.error.status === 404
  const cluster = clusterMissing
    ? null
    : ((clusterQuery.data as generated.Cluster | undefined) ?? null)
  const facilities =
    (facilitiesQuery.data as generated.FacilityCollection | undefined)?.items ??
    []
  const loading = clusterQuery.isLoading || facilitiesQuery.isLoading
  const loadError =
    clusterQuery.error && !clusterMissing
      ? clusterQuery.error
      : facilitiesQuery.error
  const state: 'ready' | 'forbidden' | 'error' = loadError
    ? stateFromError(loadError) === 'forbidden'
      ? 'forbidden'
      : 'error'
    : 'ready'
  const retry = () => {
    void clusterQuery.refetch()
    void facilitiesQuery.refetch()
  }

  const canRead = capabilities.includes('organization.cluster.read')
  if (!canRead) return <EmptyState title={text.unavailable} />

  const openEditCluster = drawer.kind === 'edit-cluster' ? cluster : null
  const openEditFacility =
    drawer.kind === 'edit-facility' ? drawer.facility : null

  return (
    <>
      {notice ? (
        <p role="status" className="status-message status-message--success">
          {notice}
        </p>
      ) : null}
      {loading ? <SkeletonList rows={2} /> : null}
      {!loading && state === 'forbidden' ? (
        <Panel id="organization-overview-access" title={text.cluster}>
          <p role="status">{text.unavailable}</p>
        </Panel>
      ) : null}
      {!loading && state === 'error' ? (
        <InlineError
          message={text.error}
          retryLabel={text.retry}
          onRetry={retry}
        />
      ) : null}
      {!loading && state === 'ready' ? (
        <PanelGrid>
          <Panel
            id="cluster-panel-heading"
            title={text.cluster}
            actions={
              cluster && canManageCluster ? (
                <Button
                  variant="secondary"
                  onClick={() => setDrawer({ kind: 'edit-cluster' })}
                >
                  {text.editCluster}
                </Button>
              ) : undefined
            }
          >
            {cluster ? (
              <div className="screen-list">
                <div className="screen-list__row">
                  <div>
                    <div className="screen-list__row-title">
                      {displayName(locale, cluster)}
                    </div>
                    <div className="screen-list__row-meta" dir="ltr">
                      {text.identifier}: {cluster.code}
                    </div>
                  </div>
                  <StatusBadge variant="success">{text.active}</StatusBadge>
                </div>
              </div>
            ) : (
              <EmptyState
                title={text.noCluster}
                action={
                  canManageCluster ? (
                    <Button
                      onClick={() => setDrawer({ kind: 'create-cluster' })}
                    >
                      {text.addCluster}
                    </Button>
                  ) : undefined
                }
              />
            )}
          </Panel>
          <Panel
            id="facilities-panel-heading"
            title={text.facilities}
            actions={
              <div className="screen-list__row-actions">
                <span className="screen-list__row-meta">
                  {text.countBadge(formatNumber(facilities.length, locale))}
                </span>
                {cluster && canManageFacility ? (
                  <Button
                    onClick={() => setDrawer({ kind: 'create-facility' })}
                  >
                    {text.addFacility}
                  </Button>
                ) : null}
              </div>
            }
          >
            {facilities.length === 0 ? (
              <EmptyState
                title={text.noFacilities}
                action={
                  cluster && canManageFacility ? (
                    <Button
                      onClick={() => setDrawer({ kind: 'create-facility' })}
                    >
                      {text.addFacility}
                    </Button>
                  ) : undefined
                }
              />
            ) : (
              <div className="screen-list">
                {facilities.map((facility) => (
                  <div className="screen-list__row" key={facility.id}>
                    <div>
                      <div className="screen-list__row-title">
                        {displayName(locale, facility)}
                      </div>
                      <div className="screen-list__row-meta" dir="ltr">
                        {text.identifier}: {facility.code}
                      </div>
                      <div className="screen-list__row-meta">
                        {facilityTypeLabel(locale, facility.type_code)}
                      </div>
                    </div>
                    <div className="screen-list__row-actions">
                      <StatusBadge
                        variant={
                          facility.status === 'active' ? 'success' : 'neutral'
                        }
                      >
                        {facilityStatusLabel(locale, facility.status)}
                      </StatusBadge>
                      {canManageFacility ? (
                        <Button
                          variant="secondary"
                          onClick={() =>
                            setDrawer({ kind: 'edit-facility', facility })
                          }
                        >
                          {text.editFacility}
                        </Button>
                      ) : null}
                    </div>
                  </div>
                ))}
              </div>
            )}
          </Panel>
        </PanelGrid>
      ) : null}
      <ClusterDrawer
        open={
          drawer.kind === 'create-cluster' || drawer.kind === 'edit-cluster'
        }
        cluster={openEditCluster}
        onClose={() => setDrawer({ kind: 'closed' })}
        onSaved={() => {
          setDrawer({ kind: 'closed' })
          setNotice(text.clusterSaved)
        }}
      />
      {cluster ? (
        <FacilityDrawer
          open={
            drawer.kind === 'create-facility' || drawer.kind === 'edit-facility'
          }
          cluster={cluster}
          facility={openEditFacility}
          onClose={() => setDrawer({ kind: 'closed' })}
          onSaved={() => {
            setDrawer({ kind: 'closed' })
            setNotice(text.facilitySaved)
          }}
        />
      ) : null}
    </>
  )
}

function facilityTypeLabel(locale: Locale, typeCode: string): string {
  const text = organizationCopy[locale]
  const match = facilityTypes.find(([code]) => code === typeCode)
  return match ? text[match[1]] : typeCode
}

function facilityStatusLabel(
  locale: Locale,
  status: generated.FacilityStatus,
): string {
  const text = organizationCopy[locale]
  return status === 'active'
    ? text.active
    : status === 'inactive'
      ? text.inactive
      : text.archived
}

function ClusterDrawer({
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
  const [failure, setFailure] = useState<
    'validation' | 'stale' | 'save' | null
  >(null)

  useEffect(() => {
    if (!open) return
    setCode('')
    setName(cluster?.name_ar ?? '')
    setNameEn(cluster?.name_en ?? '')
    setFailure(null)
  }, [open, cluster])

  const mutation = useMutation({
    mutationFn: async ({
      nextCode,
      nextName,
      nextNameEn,
    }: {
      nextCode: string
      nextName: string
      nextNameEn: string
    }) => {
      if (editing && cluster) {
        const fresh = unwrap<generated.Cluster>(
          await generated.getCluster(requestInit(token)),
        )
        return unwrap<generated.Cluster>(
          await generated.updateCluster(
            { name: nextName },
            requestInit(token, {
              command: true,
              idempotency: 'cluster-update',
              lockVersion: fresh.lock_version,
            }),
          ),
        )
      }
      return unwrap<generated.Cluster>(
        await generated.createCluster(
          {
            code: nextCode,
            name: nextName,
            name_en: nextNameEn.trim() || null,
          },
          requestInit(token, { command: true, idempotency: 'cluster' }),
        ),
      )
    },
    onSuccess: (saved) => {
      void queryClient.invalidateQueries({ queryKey: ['cluster'] })
      onSaved(saved)
    },
    onError: (caught) => {
      setFailure(
        caught instanceof ApiError && caught.status === 412 ? 'stale' : 'save',
      )
    },
  })
  const submitting = mutation.isPending

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!name.trim() || (!editing && !CODE_PATTERN.test(code))) {
      setFailure('validation')
      return
    }
    setFailure(null)
    mutation.mutate({
      nextCode: code,
      nextName: name.trim(),
      nextNameEn: nameEn,
    })
  }

  const failureMessage =
    failure === 'validation'
      ? text.validation
      : failure === 'stale'
        ? text.stale
        : failure === 'save'
          ? text.saveError
          : null

  return (
    <Drawer
      open={open}
      onClose={() => {
        if (!submitting) onClose()
      }}
      title={editing ? text.editClusterTitle : text.createClusterTitle}
    >
      <form onSubmit={(event) => void submit(event)} noValidate>
        {failureMessage ? (
          <p className="error-summary" role="alert">
            {failureMessage}
          </p>
        ) : null}
        {!editing ? (
          <Field
            id="org-cluster-code"
            label={text.code}
            required
            help={text.codeHint}
          >
            <input
              id="org-cluster-code"
              dir="ltr"
              value={code}
              required
              aria-required="true"
              aria-invalid={failure === 'validation' || undefined}
              onChange={(event) => setCode(event.target.value.toUpperCase())}
            />
          </Field>
        ) : null}
        <Field id="org-cluster-name" label={text.nameAr} required>
          <input
            id="org-cluster-name"
            value={name}
            required
            aria-required="true"
            aria-invalid={failure === 'validation' || undefined}
            onChange={(event) => setName(event.target.value)}
          />
        </Field>
        {!editing ? (
          <Field id="org-cluster-name-en" label={text.nameEn}>
            <input
              id="org-cluster-name-en"
              value={nameEn}
              onChange={(event) => setNameEn(event.target.value)}
            />
          </Field>
        ) : null}
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

function FacilityDrawer({
  open,
  cluster,
  facility,
  onClose,
  onSaved,
}: {
  open: boolean
  cluster: generated.Cluster
  facility: generated.Facility | null
  onClose: () => void
  onSaved: (facility: generated.Facility) => void
}) {
  const locale = useLocale()
  const token = useSessionToken()
  const text = organizationCopy[locale]
  const queryClient = useQueryClient()
  const editing = facility !== null
  const [typeCode, setTypeCode] = useState<string>('hospital')
  const [code, setCode] = useState('')
  const [name, setName] = useState('')
  const [nameEn, setNameEn] = useState('')
  const [failure, setFailure] = useState<
    'validation' | 'stale' | 'save' | null
  >(null)

  useEffect(() => {
    if (!open) return
    setTypeCode(facility?.type_code ?? 'hospital')
    setCode(facility?.code ?? '')
    setName(facility?.name_ar ?? '')
    setNameEn(facility?.name_en ?? '')
    setFailure(null)
  }, [open, facility])

  const mutation = useMutation({
    mutationFn: async ({
      nextTypeCode,
      nextCode,
      nextName,
      nextNameEn,
    }: {
      nextTypeCode: string
      nextCode: string
      nextName: string
      nextNameEn: string
    }) => {
      if (editing && facility) {
        const fresh = unwrap<generated.Facility>(
          await generated.getFacility(facility.id, requestInit(token)),
        )
        return unwrap<generated.Facility>(
          await generated.updateFacility(
            facility.id,
            { name: nextName },
            requestInit(token, {
              command: true,
              idempotency: 'facility-update',
              lockVersion: fresh.lock_version,
            }),
          ),
        )
      }
      return unwrap<generated.Facility>(
        await generated.createFacility(
          {
            cluster_id: cluster.id,
            type_code: nextTypeCode,
            code: nextCode,
            name: nextName,
            name_en: nextNameEn.trim() || null,
          },
          requestInit(token, { command: true, idempotency: 'facility' }),
        ),
      )
    },
    onSuccess: (saved) => {
      void queryClient.invalidateQueries({ queryKey: ['facilities'] })
      onSaved(saved)
    },
    onError: (caught) => {
      setFailure(
        caught instanceof ApiError && caught.status === 412 ? 'stale' : 'save',
      )
    },
  })
  const submitting = mutation.isPending

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (
      !name.trim() ||
      (!editing && (!CODE_PATTERN.test(code) || typeCode === ''))
    ) {
      setFailure('validation')
      return
    }
    setFailure(null)
    mutation.mutate({
      nextTypeCode: typeCode,
      nextCode: code,
      nextName: name.trim(),
      nextNameEn: nameEn,
    })
  }

  const failureMessage =
    failure === 'validation'
      ? text.validation
      : failure === 'stale'
        ? text.stale
        : failure === 'save'
          ? text.saveError
          : null

  return (
    <Drawer
      open={open}
      onClose={() => {
        if (!submitting) onClose()
      }}
      title={editing ? text.editFacilityTitle : text.createFacilityTitle}
    >
      <form onSubmit={(event) => void submit(event)} noValidate>
        {failureMessage ? (
          <p className="error-summary" role="alert">
            {failureMessage}
          </p>
        ) : null}
        {!editing ? (
          <>
            <Field id="org-facility-type" label={text.type} required>
              <Select
                id="org-facility-type"
                value={typeCode}
                onChange={setTypeCode}
                options={facilityTypes.map(([value, key]) => ({
                  value,
                  label: text[key],
                }))}
              />
            </Field>
            <Field
              id="org-facility-code"
              label={text.code}
              required
              help={text.codeHint}
            >
              <input
                id="org-facility-code"
                dir="ltr"
                value={code}
                required
                aria-required="true"
                aria-invalid={failure === 'validation' || undefined}
                onChange={(event) => setCode(event.target.value.toUpperCase())}
              />
            </Field>
          </>
        ) : null}
        <Field id="org-facility-name" label={text.nameAr} required>
          <input
            id="org-facility-name"
            value={name}
            required
            aria-required="true"
            aria-invalid={failure === 'validation' || undefined}
            onChange={(event) => setName(event.target.value)}
          />
        </Field>
        {!editing ? (
          <Field id="org-facility-name-en" label={text.nameEn}>
            <input
              id="org-facility-name-en"
              value={nameEn}
              onChange={(event) => setNameEn(event.target.value)}
            />
          </Field>
        ) : null}
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

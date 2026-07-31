import { useEffect, useState, type FormEvent } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useLocale, useSessionToken } from '../../app/session-context'
import { usePrincipal } from '../../app/principal-context'
import { ApiError, requestInit, stateFromError, unwrap } from '../../api/http'
import { formatDate, formatNumber, type Locale } from '../../i18n'
import {
  useAssignments,
  useCluster,
  useFacilities,
  useJobTitles,
  useOrganizationUnits,
  usePeople,
  usePositions,
} from '../../api/hooks'
import {
  Button,
  Drawer,
  EmptyState,
  Field,
  InlineError,
  Page,
  PageHeader,
  Panel,
  PanelGrid,
  Select,
  SkeletonList,
  StatusBadge,
  Tabs,
} from '../../ui'
import * as generated from '../../api/generated/cluster'

type TabKey = 'overview' | 'structure' | 'people'

const copy = {
  ar: {
    title: 'المنظمة',
    intro: 'بيانات التجمع والهيكل التنظيمي والموظفين في عرض واحد.',
    tabsLabel: 'أقسام المنظمة',
    overviewTab: 'نظرة عامة',
    structureTab: 'الهيكل التنظيمي',
    peopleTab: 'الموظفون والتكليفات',
    unavailable: 'غير متاح',
    loading: 'جارٍ التحميل…',
    retry: 'إعادة المحاولة',
    error: 'تعذر تحميل البيانات. أعد المحاولة.',
    addCluster: 'إضافة تجمع',
    addFacility: 'إضافة منشأة',
    editCluster: 'تعديل بيانات التجمع',
    editFacility: 'تعديل المنشأة',
    cluster: 'بيانات التجمع',
    facilities: 'المنشآت',
    noCluster: 'لم تُضف بيانات التجمع بعد.',
    noFacilities: 'لا توجد منشآت بعد.',
    identifier: 'الرقم التعريفي',
    type: 'النوع',
    status: 'الحالة',
    actions: 'الإجراءات',
    active: 'نشط',
    inactive: 'غير نشط',
    archived: 'مؤرشف',
    hospital: 'مستشفى',
    center: 'مركز صحي',
    lab: 'مختبر',
    sharedServices: 'خدمات مشتركة',
    clusterSaved: 'تم حفظ بيانات التجمع.',
    facilitySaved: 'تم حفظ بيانات المنشأة.',
    unitSaved: 'تم حفظ الوحدة.',
    positionSaved: 'تم حفظ المنصب.',
    personSaved: 'تم حفظ بيانات الموظف.',
    assignmentSaved: 'تم إنشاء التكليف.',
    assignmentEnded: 'تم إنهاء التكليف.',
    createClusterTitle: 'إضافة تجمع',
    editClusterTitle: 'تعديل بيانات التجمع',
    createFacilityTitle: 'إضافة منشأة',
    editFacilityTitle: 'تعديل المنشأة',
    createUnitTitle: 'إضافة وحدة',
    createPositionTitle: 'إضافة منصب',
    createPersonTitle: 'إضافة موظف',
    editPersonTitle: 'تعديل بيانات الموظف',
    createAssignmentTitle: 'إنشاء تكليف',
    endAssignmentTitle: 'إنهاء التكليف',
    code: 'الرقم التعريفي',
    codeHint:
      'استخدم حروفاً إنجليزية كبيرة وأرقاماً وشرطة أو شرطة سفلية فقط (2–64).',
    nameAr: 'الاسم بالعربية',
    nameEn: 'الاسم بالإنجليزية',
    cancel: 'إلغاء',
    save: 'حفظ',
    saving: 'جارٍ الحفظ…',
    close: 'إغلاق',
    validation: 'أكمل الحقول المطلوبة بالصيغة الصحيحة.',
    stale: 'تغيّرت البيانات في مكان آخر. حدّث الصفحة ثم أعد المحاولة.',
    saveError: 'تعذر حفظ البيانات. أعد المحاولة.',
    rootLevel: 'على مستوى التجمع',
    unitType: 'نوع الوحدة',
    sector: 'قطاع',
    department: 'إدارة',
    section: 'قسم',
    unit: 'وحدة',
    parent: 'الموقع الأعلى',
    jobTitle: 'المسمى الوظيفي',
    positionTitle: 'العنوان',
    addUnit: 'إضافة وحدة',
    addPosition: 'إضافة منصب',
    addPerson: 'إضافة موظف',
    createAssignment: 'إنشاء تكليف',
    endAssignment: 'إنهاء التكليف',
    reorder: 'ترتيب الوحدات',
    reorderConfirm:
      'سيُرتَّب كل مستوى من الوحدات بحسب النوع ثم الاسم. هل تريد المتابعة؟',
    reorderBusy: 'جارٍ ترتيب الوحدات…',
    reorderFailed: 'تعذّر ترتيب الوحدات. أعد المحاولة.',
    reorderStale: 'بيانات قديمة، حدّث الصفحة ثم أعد المحاولة.',
    reorderSuccess: (count: string) => `تم ترتيب ${count} وحدة.`,
    unitsAtCluster: 'وحدات على مستوى التجمع',
    unitPositions: 'المناصب',
    noUnits: 'لا توجد وحدات بعد.',
    noPositions: 'لا توجد مناصب لهذه الوحدة.',
    people: 'الموظفون',
    assignments: 'التكليفات',
    employee: 'الموظف',
    employeeNumber: 'الرقم الوظيفي',
    person: 'الموظف',
    position: 'المنصب',
    startAt: 'بداية التكليف',
    endAt: 'نهاية التكليف',
    endReason: 'سبب الإنهاء',
    endReasonHelp: 'أدخل سبباً واضحاً لإنهاء التكليف.',
    noPeople: 'لا يوجد موظفون بعد.',
    noAssignments: 'لا توجد تكليفات بعد.',
    primary: 'أساسي',
    noActivePeople: 'أضف موظفاً نشطاً واحداً على الأقل قبل إنشاء تكليف.',
    noActivePositions: 'أضف منصباً نشطاً واحداً على الأقل قبل إنشاء تكليف.',
    endAtRequired: 'أدخل وقت نهاية التكليف وسبباً واضحاً بعد بداية التكليف.',
    edit: 'تعديل',
    suspended: 'موقوف',
    left: 'غادر',
    pending: 'قادم',
    ended: 'منتهٍ',
    countBadge: (count: string) => `${count} عنصر`,
  },
  en: {
    title: 'Organization',
    intro:
      'Cluster information, organization structure, and employees in one view.',
    tabsLabel: 'Organization sections',
    overviewTab: 'Overview',
    structureTab: 'Structure',
    peopleTab: 'People & assignments',
    unavailable: 'Unavailable',
    loading: 'Loading…',
    retry: 'Try again',
    error: 'The data could not be loaded. Try again.',
    addCluster: 'Add cluster',
    addFacility: 'Add facility',
    editCluster: 'Edit cluster information',
    editFacility: 'Edit facility',
    cluster: 'Cluster information',
    facilities: 'Facilities',
    noCluster: 'Cluster information has not been added yet.',
    noFacilities: 'No facilities yet.',
    identifier: 'Identifier',
    type: 'Type',
    status: 'Status',
    actions: 'Actions',
    active: 'Active',
    inactive: 'Inactive',
    archived: 'Archived',
    hospital: 'Hospital',
    center: 'Health center',
    lab: 'Laboratory',
    sharedServices: 'Shared services',
    clusterSaved: 'Cluster information saved.',
    facilitySaved: 'Facility information saved.',
    unitSaved: 'Unit saved.',
    positionSaved: 'Position saved.',
    personSaved: 'Employee saved.',
    assignmentSaved: 'Assignment created.',
    assignmentEnded: 'Assignment ended.',
    createClusterTitle: 'Add cluster',
    editClusterTitle: 'Edit cluster information',
    createFacilityTitle: 'Add facility',
    editFacilityTitle: 'Edit facility',
    createUnitTitle: 'Add unit',
    createPositionTitle: 'Add position',
    createPersonTitle: 'Add employee',
    editPersonTitle: 'Edit employee details',
    createAssignmentTitle: 'Create assignment',
    endAssignmentTitle: 'End assignment',
    code: 'Identifier',
    codeHint:
      'Use uppercase letters, digits, hyphens, or underscores only (2–64).',
    nameAr: 'Name in Arabic',
    nameEn: 'Name in English',
    cancel: 'Cancel',
    save: 'Save',
    saving: 'Saving…',
    close: 'Close',
    validation: 'Complete the required fields using the expected format.',
    stale:
      'This information changed elsewhere. Refresh the page and try again.',
    saveError: 'The data could not be saved. Try again.',
    rootLevel: 'At cluster level',
    unitType: 'Unit type',
    sector: 'Sector',
    department: 'Department',
    section: 'Section',
    unit: 'Unit',
    parent: 'Higher level',
    jobTitle: 'Job title',
    positionTitle: 'Title',
    addUnit: 'Add unit',
    addPosition: 'Add position',
    addPerson: 'Add employee',
    createAssignment: 'Create assignment',
    endAssignment: 'End assignment',
    reorder: 'Arrange units',
    reorderConfirm:
      'This will arrange units at each level by type, then name. Continue?',
    reorderBusy: 'Arranging units…',
    reorderFailed: 'The units could not be arranged. Try again.',
    reorderStale: 'The data is outdated. Refresh the page and try again.',
    reorderSuccess: (count: string) => `${count} units arranged.`,
    unitsAtCluster: 'Units at cluster level',
    unitPositions: 'Positions',
    noUnits: 'No units yet.',
    noPositions: 'No positions for this unit.',
    people: 'Employees',
    assignments: 'Assignments',
    employee: 'Employee',
    employeeNumber: 'Employee number',
    person: 'Employee',
    position: 'Position',
    startAt: 'Assignment start',
    endAt: 'Assignment end',
    endReason: 'End reason',
    endReasonHelp: 'Enter a clear reason for ending the assignment.',
    noPeople: 'No employees yet.',
    noAssignments: 'No assignments yet.',
    primary: 'Primary',
    noActivePeople:
      'Add at least one active employee before creating an assignment.',
    noActivePositions:
      'Add at least one active position before creating an assignment.',
    endAtRequired:
      'Enter an end time and a clear reason after the assignment start.',
    edit: 'Edit',
    suspended: 'Suspended',
    left: 'Left',
    pending: 'Pending',
    ended: 'Ended',
    countBadge: (count: string) => `${count} items`,
  },
} as const

const CODE_PATTERN = /^[A-Z0-9_-]{2,64}$/

const unitTypes: Array<
  [
    'sector' | 'department' | 'section' | 'unit',
    'sector' | 'department' | 'section' | 'unit',
  ]
> = [
  ['sector', 'sector'],
  ['department', 'department'],
  ['section', 'section'],
  ['unit', 'unit'],
]

const facilityTypes: Array<
  [
    'hospital' | 'center' | 'lab' | 'shared_services',
    'hospital' | 'center' | 'lab' | 'sharedServices',
  ]
> = [
  ['hospital', 'hospital'],
  ['center', 'center'],
  ['lab', 'lab'],
  ['shared_services', 'sharedServices'],
]

function displayName(
  locale: Locale,
  resource: { name_ar: string; name_en: string | null },
): string {
  return locale === 'en' && resource.name_en
    ? resource.name_en
    : resource.name_ar
}

function toUtcIso(localValue: string): string | undefined {
  if (!localValue) return undefined
  const date = new Date(localValue)
  return Number.isNaN(date.getTime()) ? undefined : date.toISOString()
}

function localDateTimeInput(iso: string): string {
  const date = new Date(iso)
  if (Number.isNaN(date.getTime())) return ''
  const offset = date.getTimezoneOffset()
  return new Date(date.getTime() - offset * 60_000).toISOString().slice(0, 16)
}

export function OrganizationScreen() {
  const locale = useLocale()
  const text = copy[locale]
  const [tab, setTab] = useState<TabKey>('overview')

  return (
    <Page>
      <PageHeader
        id="organization-screen-heading"
        title={text.title}
        description={text.intro}
      />
      <Tabs
        label={text.tabsLabel}
        tabs={[
          {
            key: 'overview',
            label: text.overviewTab,
            active: tab === 'overview',
            onClick: () => setTab('overview'),
          },
          {
            key: 'structure',
            label: text.structureTab,
            active: tab === 'structure',
            onClick: () => setTab('structure'),
          },
          {
            key: 'people',
            label: text.peopleTab,
            active: tab === 'people',
            onClick: () => setTab('people'),
          },
        ]}
      />
      {tab === 'overview' ? <OverviewTab /> : null}
      {tab === 'structure' ? <StructureTab /> : null}
      {tab === 'people' ? <PeopleTab /> : null}
    </Page>
  )
}

function useCapabilities(): string[] {
  const principal = usePrincipal()
  return principal.capabilities ?? []
}

/* ------------------------------------------------------------------ */
/* Overview tab                                                        */
/* ------------------------------------------------------------------ */

function OverviewTab() {
  const locale = useLocale()
  const text = copy[locale]
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
  const text = copy[locale]
  const match = facilityTypes.find(([code]) => code === typeCode)
  return match ? text[match[1]] : typeCode
}

function facilityStatusLabel(
  locale: Locale,
  status: generated.FacilityStatus,
): string {
  const text = copy[locale]
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
  const text = copy[locale]
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
  const text = copy[locale]
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

function StructureTab() {
  const locale = useLocale()
  const token = useSessionToken()
  const text = copy[locale]
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
  const text = copy[locale]
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
  const text = copy[locale]
  const match = unitTypes.find(([code]) => code === typeCode)
  return match ? text[match[1]] : typeCode
}

function unitStatusLabel(
  locale: Locale,
  status: generated.OrganizationUnitStatus,
): string {
  const text = copy[locale]
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
  const text = copy[locale]
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
  const text = copy[locale]
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

/* ------------------------------------------------------------------ */
/* People tab                                                          */
/* ------------------------------------------------------------------ */

function PeopleTab() {
  const locale = useLocale()
  const text = copy[locale]
  const capabilities = useCapabilities()
  const peopleQuery = usePeople()
  const positionsQuery = usePositions()
  const assignmentsQuery = useAssignments()
  const [notice, setNotice] = useState<string | null>(null)
  const [personDrawer, setPersonDrawer] = useState<{
    open: boolean
    person: generated.Person | null
  }>({ open: false, person: null })
  const [assignmentDrawerOpen, setAssignmentDrawerOpen] = useState(false)
  const [endingAssignment, setEndingAssignment] =
    useState<generated.Assignment | null>(null)

  const canManagePerson = capabilities.includes('organization.person.manage')
  const canManageAssignment = capabilities.includes(
    'organization.assignment.manage',
  )

  const people =
    (peopleQuery.data as generated.PersonCollection | undefined)?.items ?? []
  const positions =
    (positionsQuery.data as generated.PositionCollection | undefined)?.items ??
    []
  const assignments =
    (assignmentsQuery.data as generated.AssignmentCollection | undefined)
      ?.items ?? []
  const loading =
    peopleQuery.isLoading ||
    positionsQuery.isLoading ||
    assignmentsQuery.isLoading
  const loadError =
    peopleQuery.error ?? positionsQuery.error ?? assignmentsQuery.error
  const state: 'ready' | 'forbidden' | 'error' = loadError
    ? stateFromError(loadError) === 'forbidden'
      ? 'forbidden'
      : 'error'
    : 'ready'
  const retry = () => {
    void peopleQuery.refetch()
    void positionsQuery.refetch()
    void assignmentsQuery.refetch()
  }

  const canRead = capabilities.includes('organization.person.read')
  if (!canRead) return <EmptyState title={text.unavailable} />

  return (
    <>
      {notice ? (
        <p role="status" className="status-message status-message--success">
          {notice}
        </p>
      ) : null}
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
        <PanelGrid>
          <Panel
            id="people-panel-heading"
            title={text.people}
            actions={
              canManagePerson ? (
                <Button
                  onClick={() => setPersonDrawer({ open: true, person: null })}
                >
                  {text.addPerson}
                </Button>
              ) : undefined
            }
          >
            {people.length === 0 ? (
              <EmptyState
                title={text.noPeople}
                action={
                  canManagePerson ? (
                    <Button
                      onClick={() =>
                        setPersonDrawer({ open: true, person: null })
                      }
                    >
                      {text.addPerson}
                    </Button>
                  ) : undefined
                }
              />
            ) : (
              <div className="screen-list">
                {people.map((person) => (
                  <div className="screen-list__row" key={person.id}>
                    <div>
                      <div className="screen-list__row-title">
                        {locale === 'en' && person.display_name_en
                          ? person.display_name_en
                          : person.display_name_ar}
                      </div>
                      <div className="screen-list__row-meta" dir="ltr">
                        {text.employeeNumber}: {person.employee_number}
                      </div>
                    </div>
                    <div className="screen-list__row-actions">
                      <StatusBadge
                        variant={
                          person.status === 'active' ? 'success' : 'neutral'
                        }
                      >
                        {personStatusLabel(locale, person.status)}
                      </StatusBadge>
                      {canManagePerson ? (
                        <Button
                          variant="secondary"
                          onClick={() =>
                            setPersonDrawer({ open: true, person })
                          }
                        >
                          {text.edit}
                        </Button>
                      ) : null}
                    </div>
                  </div>
                ))}
              </div>
            )}
          </Panel>
          <Panel
            id="assignments-panel-heading"
            title={text.assignments}
            actions={
              canManageAssignment ? (
                <Button onClick={() => setAssignmentDrawerOpen(true)}>
                  {text.createAssignment}
                </Button>
              ) : undefined
            }
          >
            {assignments.length === 0 ? (
              <EmptyState
                title={text.noAssignments}
                action={
                  canManageAssignment ? (
                    <Button onClick={() => setAssignmentDrawerOpen(true)}>
                      {text.createAssignment}
                    </Button>
                  ) : undefined
                }
              />
            ) : (
              <div className="screen-list">
                {assignments.map((assignment) => {
                  const person = people.find(
                    (item) => item.id === assignment.person_id,
                  )
                  const position = positions.find(
                    (item) => item.id === assignment.position_id,
                  )
                  return (
                    <div className="screen-list__row" key={assignment.id}>
                      <div>
                        <div className="screen-list__row-title">
                          {person
                            ? locale === 'en' && person.display_name_en
                              ? person.display_name_en
                              : person.display_name_ar
                            : ''}
                          {assignment.is_primary ? (
                            <StatusBadge variant="info">
                              {text.primary}
                            </StatusBadge>
                          ) : null}
                        </div>
                        <div className="screen-list__row-meta">
                          {text.position}:{' '}
                          {position?.title_ar ?? position?.code ?? ''}
                        </div>
                        <div className="screen-list__row-meta">
                          {text.startAt}:{' '}
                          {formatDate(assignment.start_at, locale)}
                          {assignment.end_at
                            ? ` · ${text.endAt}: ${formatDate(assignment.end_at, locale)}`
                            : ''}
                        </div>
                      </div>
                      <div className="screen-list__row-actions">
                        <StatusBadge
                          variant={
                            assignment.status === 'active'
                              ? 'success'
                              : 'neutral'
                          }
                        >
                          {assignmentStatusLabel(locale, assignment.status)}
                        </StatusBadge>
                        {canManageAssignment &&
                        (assignment.status === 'active' ||
                          assignment.status === 'pending') ? (
                          <Button
                            variant="secondary"
                            onClick={() => setEndingAssignment(assignment)}
                          >
                            {text.endAssignment}
                          </Button>
                        ) : null}
                      </div>
                    </div>
                  )
                })}
              </div>
            )}
          </Panel>
        </PanelGrid>
      ) : null}
      <PersonDrawer
        open={personDrawer.open}
        person={personDrawer.person}
        onClose={() => setPersonDrawer({ open: false, person: null })}
        onSaved={() => {
          setPersonDrawer({ open: false, person: null })
          setNotice(text.personSaved)
        }}
      />
      <AssignmentDrawer
        open={assignmentDrawerOpen}
        onClose={() => setAssignmentDrawerOpen(false)}
        people={people}
        positions={positions}
        onSaved={() => {
          setAssignmentDrawerOpen(false)
          setNotice(text.assignmentSaved)
        }}
      />
      <EndAssignmentDrawer
        open={endingAssignment !== null}
        assignment={endingAssignment}
        onClose={() => setEndingAssignment(null)}
        onEnded={() => {
          setEndingAssignment(null)
          setNotice(text.assignmentEnded)
        }}
      />
    </>
  )
}

function personStatusLabel(
  locale: Locale,
  status: generated.PersonStatus,
): string {
  const text = copy[locale]
  return status === 'active'
    ? text.active
    : status === 'suspended'
      ? text.suspended
      : text.left
}

function assignmentStatusLabel(
  locale: Locale,
  status: generated.AssignmentStatus,
): string {
  const text = copy[locale]
  return status === 'active'
    ? text.active
    : status === 'pending'
      ? text.pending
      : text.ended
}

function PersonDrawer({
  open,
  person,
  onClose,
  onSaved,
}: {
  open: boolean
  person: generated.Person | null
  onClose: () => void
  onSaved: (person: generated.Person) => void
}) {
  const locale = useLocale()
  const token = useSessionToken()
  const text = copy[locale]
  const queryClient = useQueryClient()
  const editing = person !== null
  const [employeeNumber, setEmployeeNumber] = useState('')
  const [nameAr, setNameAr] = useState('')
  const [nameEn, setNameEn] = useState('')
  const [status, setStatus] = useState<string>('active')
  const [failure, setFailure] = useState<
    'validation' | 'stale' | 'save' | null
  >(null)

  useEffect(() => {
    if (!open) return
    setEmployeeNumber(person?.employee_number ?? '')
    setNameAr(person?.display_name_ar ?? '')
    setNameEn(person?.display_name_en ?? '')
    setStatus(person?.status ?? 'active')
    setFailure(null)
  }, [open, person])

  const mutation = useMutation({
    mutationFn: async ({
      nextEmployeeNumber,
      nextNameAr,
      nextNameEn,
      nextStatus,
    }: {
      nextEmployeeNumber: string
      nextNameAr: string
      nextNameEn: string
      nextStatus: string
    }) => {
      if (editing && person) {
        return unwrap<generated.Person>(
          await generated.updatePerson(
            person.id,
            {
              display_name_ar: nextNameAr,
              display_name_en: nextNameEn.trim() || null,
              status: nextStatus as generated.PersonPatchStatus,
            },
            requestInit(token, {
              command: true,
              idempotency: 'person-update',
              lockVersion: person.person_version,
            }),
          ),
        )
      }
      return unwrap<generated.Person>(
        await generated.registerPerson(
          {
            employee_number: nextEmployeeNumber,
            display_name_ar: nextNameAr,
            display_name_en: nextNameEn.trim() || undefined,
            status: nextStatus as generated.PersonCreateStatus,
          },
          requestInit(token, { command: true, idempotency: 'person' }),
        ),
      )
    },
    onSuccess: (saved) => {
      void queryClient.invalidateQueries({ queryKey: ['people'] })
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
    if (!nameAr.trim() || (!editing && !employeeNumber.trim())) {
      setFailure('validation')
      return
    }
    setFailure(null)
    mutation.mutate({
      nextEmployeeNumber: employeeNumber.trim(),
      nextNameAr: nameAr.trim(),
      nextNameEn: nameEn,
      nextStatus: status,
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
      title={editing ? text.editPersonTitle : text.createPersonTitle}
    >
      <form onSubmit={(event) => void submit(event)} noValidate>
        {failureMessage ? (
          <p className="error-summary" role="alert">
            {failureMessage}
          </p>
        ) : null}
        {!editing ? (
          <Field id="org-person-number" label={text.employeeNumber} required>
            <input
              id="org-person-number"
              dir="ltr"
              value={employeeNumber}
              required
              aria-required="true"
              aria-invalid={failure === 'validation' || undefined}
              onChange={(event) => setEmployeeNumber(event.target.value)}
            />
          </Field>
        ) : null}
        <Field id="org-person-name-ar" label={text.nameAr} required>
          <input
            id="org-person-name-ar"
            value={nameAr}
            required
            aria-required="true"
            aria-invalid={failure === 'validation' || undefined}
            onChange={(event) => setNameAr(event.target.value)}
          />
        </Field>
        <Field id="org-person-name-en" label={text.nameEn}>
          <input
            id="org-person-name-en"
            value={nameEn}
            onChange={(event) => setNameEn(event.target.value)}
          />
        </Field>
        <Field id="org-person-status" label={text.status}>
          <Select
            id="org-person-status"
            value={status}
            onChange={setStatus}
            options={[
              { value: 'active', label: text.active },
              { value: 'suspended', label: text.suspended },
              { value: 'left', label: text.left },
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

function AssignmentDrawer({
  open,
  onClose,
  people,
  positions,
  onSaved,
}: {
  open: boolean
  onClose: () => void
  people: generated.Person[]
  positions: generated.Position[]
  onSaved: (assignment: generated.Assignment) => void
}) {
  const locale = useLocale()
  const token = useSessionToken()
  const text = copy[locale]
  const queryClient = useQueryClient()
  const [personId, setPersonId] = useState('')
  const [positionId, setPositionId] = useState('')
  const [startAt, setStartAt] = useState('')
  const [endAt, setEndAt] = useState('')
  const [isPrimary, setIsPrimary] = useState(false)
  const [failure, setFailure] = useState<
    'validation' | 'empty' | 'save' | null
  >(null)

  useEffect(() => {
    if (!open) return
    setPersonId('')
    setPositionId('')
    setStartAt('')
    setEndAt('')
    setIsPrimary(false)
    setFailure(null)
  }, [open])

  const activePeople = people.filter((person) => person.status === 'active')
  const activePositions = positions.filter((position) => position.is_active)

  const mutation = useMutation({
    mutationFn: async ({
      nextPersonId,
      nextPositionId,
      nextStartIso,
      nextEndIso,
      nextIsPrimary,
    }: {
      nextPersonId: string
      nextPositionId: string
      nextStartIso: string
      nextEndIso: string | undefined
      nextIsPrimary: boolean
    }) =>
      unwrap<generated.Assignment>(
        await generated.createAssignment(
          {
            person_id: nextPersonId,
            position_id: nextPositionId,
            start_at: nextStartIso,
            end_at: nextEndIso,
            is_primary: nextIsPrimary || undefined,
          },
          requestInit(token, { command: true, idempotency: 'assignment' }),
        ),
      ),
    onSuccess: (created) => {
      void queryClient.invalidateQueries({ queryKey: ['assignments'] })
      onSaved(created)
    },
    onError: () => setFailure('save'),
  })
  const submitting = mutation.isPending

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const startIso = toUtcIso(startAt)
    if (!personId || !positionId || !startIso) {
      setFailure('validation')
      return
    }
    if (activePeople.length === 0 || activePositions.length === 0) {
      setFailure('empty')
      return
    }
    setFailure(null)
    mutation.mutate({
      nextPersonId: personId,
      nextPositionId: positionId,
      nextStartIso: startIso,
      nextEndIso: toUtcIso(endAt),
      nextIsPrimary: isPrimary,
    })
  }

  const failureMessage =
    failure === 'validation'
      ? text.validation
      : failure === 'empty'
        ? activePeople.length === 0 && activePositions.length === 0
          ? text.noActivePeople
          : text.noActivePositions
        : failure === 'save'
          ? text.saveError
          : null

  return (
    <Drawer
      open={open}
      onClose={() => {
        if (!submitting) onClose()
      }}
      title={text.createAssignmentTitle}
    >
      <form onSubmit={(event) => void submit(event)} noValidate>
        {failureMessage ? (
          <p className="error-summary" role="alert">
            {failureMessage}
          </p>
        ) : null}
        <Field id="org-assignment-person" label={text.person} required>
          <Select
            id="org-assignment-person"
            value={personId}
            onChange={setPersonId}
            options={people.map((person) => ({
              value: person.id,
              label:
                locale === 'en' && person.display_name_en
                  ? person.display_name_en
                  : person.display_name_ar,
            }))}
          />
        </Field>
        <Field id="org-assignment-position" label={text.position} required>
          <Select
            id="org-assignment-position"
            value={positionId}
            onChange={setPositionId}
            options={positions.map((position) => ({
              value: position.id,
              label: position.title_ar,
            }))}
          />
        </Field>
        <Field id="org-assignment-start" label={text.startAt} required>
          <input
            id="org-assignment-start"
            type="datetime-local"
            value={startAt}
            required
            aria-required="true"
            onChange={(event) => setStartAt(event.target.value)}
          />
        </Field>
        <Field id="org-assignment-end" label={text.endAt}>
          <input
            id="org-assignment-end"
            type="datetime-local"
            value={endAt}
            onChange={(event) => setEndAt(event.target.value)}
          />
        </Field>
        <label className="field__label" htmlFor="org-assignment-primary">
          <input
            id="org-assignment-primary"
            type="checkbox"
            checked={isPrimary}
            onChange={(event) => setIsPrimary(event.target.checked)}
          />{' '}
          {text.primary}
        </label>
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

function EndAssignmentDrawer({
  open,
  assignment,
  onClose,
  onEnded,
}: {
  open: boolean
  assignment: generated.Assignment | null
  onClose: () => void
  onEnded: (assignment: generated.Assignment) => void
}) {
  const locale = useLocale()
  const token = useSessionToken()
  const text = copy[locale]
  const queryClient = useQueryClient()
  const [endAt, setEndAt] = useState('')
  const [reason, setReason] = useState('')
  const [failure, setFailure] = useState<
    'validation' | 'stale' | 'save' | null
  >(null)

  useEffect(() => {
    if (!open) return
    setEndAt(localDateTimeInput(new Date().toISOString()))
    setReason('')
    setFailure(null)
  }, [open, assignment])

  const mutation = useMutation({
    mutationFn: async ({
      nextEndIso,
      nextReason,
    }: {
      nextEndIso: string
      nextReason: string
    }) => {
      if (!assignment) throw new Error('Assignment is not available')
      return unwrap<generated.Assignment>(
        await generated.endAssignment(
          assignment.id,
          { end_at: nextEndIso, reason: nextReason },
          requestInit(token, {
            command: true,
            idempotency: 'assignment-end',
            lockVersion: assignment.lock_version,
          }),
        ),
      )
    },
    onSuccess: (ended) => {
      void queryClient.invalidateQueries({ queryKey: ['assignments'] })
      onEnded(ended)
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
    if (!assignment) return
    const endIso = toUtcIso(endAt)
    if (
      !endIso ||
      !reason.trim() ||
      (assignment.start_at &&
        new Date(endIso).getTime() <= new Date(assignment.start_at).getTime())
    ) {
      setFailure('validation')
      return
    }
    setFailure(null)
    mutation.mutate({ nextEndIso: endIso, nextReason: reason.trim() })
  }

  const failureMessage =
    failure === 'validation'
      ? text.endAtRequired
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
      title={text.endAssignmentTitle}
    >
      <form onSubmit={(event) => void submit(event)} noValidate>
        {failureMessage ? (
          <p className="error-summary" role="alert">
            {failureMessage}
          </p>
        ) : null}
        <Field id="org-assignment-end-at" label={text.endAt} required>
          <input
            id="org-assignment-end-at"
            type="datetime-local"
            value={endAt}
            required
            aria-required="true"
            onChange={(event) => setEndAt(event.target.value)}
          />
        </Field>
        <Field
          id="org-assignment-end-reason"
          label={text.endReason}
          required
          help={text.endReasonHelp}
        >
          <input
            id="org-assignment-end-reason"
            value={reason}
            required
            aria-required="true"
            onChange={(event) => setReason(event.target.value)}
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

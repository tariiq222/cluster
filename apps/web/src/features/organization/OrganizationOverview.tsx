import { useCallback, useEffect, useState } from 'react'
import { Building2, Hospital } from 'lucide-react'

import { formattingLocale } from '../../app/copy'
import { useLocale, useToken } from '../../app/session-context'
import { ApiError, getCluster, listFacilities, type Cluster, type Facility } from '../../api'
import { Button, EmptyState, InlineError, Page, PageHeader, Panel, PanelGrid, SkeletonList, StatusBadge } from '../../ui'
import { ClusterDrawer } from './ClusterDrawer'
import { FacilityDrawer } from './FacilityDrawer'
import './organization-overview.css'

type Locale = 'ar' | 'en'
type DrawerState =
  | { readonly kind: 'closed' }
  | { readonly kind: 'create-cluster' }
  | { readonly kind: 'edit-cluster' }
  | { readonly kind: 'create-facility' }
  | { readonly kind: 'edit-facility'; readonly facility: Facility }

const copy = {
  ar: {
    title: 'منشآت التجمع', intro: 'بيانات التجمع والمنشآت التابعة له في عرض واضح ومركز.',
    loading: 'جارٍ تحميل بيانات التجمع…', retry: 'إعادة المحاولة', forbidden: 'لا تملك صلاحية إدارة بيانات التجمع.',
    error: 'تعذر تحميل بيانات التجمع. أعد المحاولة.', cluster: 'بيانات التجمع', facilities: 'المنشآت',
    noCluster: 'لم تُضف بيانات التجمع بعد.', noFacilities: 'لا توجد منشآت بعد.',
    addCluster: 'إضافة تجمع', addFacility: 'إضافة منشأة', editCluster: 'تعديل بيانات التجمع',
    editFacility: 'تعديل المنشأة', identifier: 'الرقم التعريفي', type: 'النوع', status: 'الحالة',
    actions: 'الإجراءات', active: 'نشطة', inactive: 'غير نشطة', archived: 'مؤرشفة',
    hospital: 'مستشفى', center: 'مركز صحي', lab: 'مختبر', sharedServices: 'خدمات مشتركة',
    clusterSaved: 'تم حفظ بيانات التجمع.', facilitySaved: 'تم حفظ بيانات المنشأة.',
  },
  en: {
    title: 'Cluster facilities', intro: 'A focused view of cluster information and its facilities.',
    loading: 'Loading cluster data…', retry: 'Try again', forbidden: 'You do not have permission to manage cluster data.',
    error: 'Cluster data could not be loaded. Try again.', cluster: 'Cluster information', facilities: 'Facilities',
    noCluster: 'Cluster information has not been added yet.', noFacilities: 'No facilities yet.',
    addCluster: 'Add cluster', addFacility: 'Add facility', editCluster: 'Edit cluster information',
    editFacility: 'Edit facility', identifier: 'Identifier', type: 'Type', status: 'Status',
    actions: 'Actions', active: 'Active', inactive: 'Inactive', archived: 'Archived',
    hospital: 'Hospital', center: 'Health center', lab: 'Laboratory', sharedServices: 'Shared services',
    clusterSaved: 'Cluster information saved.', facilitySaved: 'Facility information saved.',
  },
}

function displayName(locale: Locale, resource: { readonly name_ar: string; readonly name_en: string | null }): string {
  return locale === 'en' && resource.name_en ? resource.name_en : resource.name_ar
}

function facilityTypeLabel(locale: Locale, typeCode: string): string {
  const text = copy[locale]
  if (typeCode === 'center') return text.center
  if (typeCode === 'lab') return text.lab
  if (typeCode === 'shared_services') return text.sharedServices
  return text.hospital
}

export function OrganizationOverview() {
  const locale = useLocale()
  const token = useToken()
  const text = copy[locale]
  const [cluster, setCluster] = useState<Cluster | null>(null)
  const [facilities, setFacilities] = useState<Facility[]>([])
  const [loading, setLoading] = useState(true)
  const [state, setState] = useState<'ready' | 'forbidden' | 'error'>('ready')
  const [drawer, setDrawer] = useState<DrawerState>({ kind: 'closed' })
  const [notice, setNotice] = useState<string | null>(null)

  const load = useCallback(async () => {
    setLoading(true)
    setState('ready')
    try {
      try {
        setCluster(await getCluster(token))
      } catch (error) {
        if (error instanceof ApiError && error.status === 404) setCluster(null)
        else throw error
      }
      setFacilities((await listFacilities(token)).items)
    } catch (error) {
      setCluster(null)
      setFacilities([])
      setState(error instanceof ApiError && error.status === 403 ? 'forbidden' : 'error')
    } finally {
      setLoading(false)
    }
  }, [token])

  useEffect(() => { void load() }, [load])

  const primaryAction = cluster === null
    ? <Button onClick={() => setDrawer({ kind: 'create-cluster' })}>{text.addCluster}</Button>
    : <Button onClick={() => setDrawer({ kind: 'create-facility' })}>{text.addFacility}</Button>
  const editingFacility = drawer.kind === 'edit-facility' ? drawer.facility : null
  const clusterDrawerOpen = drawer.kind === 'create-cluster' || drawer.kind === 'edit-cluster'
  const facilityDrawerOpen = drawer.kind === 'create-facility' || drawer.kind === 'edit-facility'

  return (
    <Page>
      <PageHeader id="organization-heading" title={text.title} description={text.intro} actions={primaryAction} />
      {notice ? <p role="status">{notice}</p> : null}
      {loading ? <SkeletonList label={text.loading} /> : null}
      {!loading && state === 'forbidden' ? <Panel id="organization-access" title={text.cluster}><p role="status">{text.forbidden}</p></Panel> : null}
      {!loading && state === 'error' ? <InlineError message={text.error} retryLabel={text.retry} onRetry={() => void load()} /> : null}
      {!loading && state === 'ready' ? (
        <PanelGrid>
          <Panel id="cluster-heading" title={text.cluster} level={2} actions={cluster ? <Button variant="secondary" onClick={() => setDrawer({ kind: 'edit-cluster' })}>{text.editCluster}</Button> : undefined}>
            {cluster ? <ClusterSummary cluster={cluster} locale={locale} text={text} /> : <EmptyState icon={<Building2 />} title={text.noCluster} />}
          </Panel>
          <Panel id="facilities-heading" title={text.facilities} level={2} actions={<span className="count-badge">{new Intl.NumberFormat(formattingLocale(locale)).format(facilities.length)}</span>}>
            {facilities.length === 0 ? <EmptyState icon={<Hospital />} title={text.noFacilities} /> : <FacilityTable facilities={facilities} locale={locale} text={text} onEdit={(facility) => setDrawer({ kind: 'edit-facility', facility })} />}
          </Panel>
        </PanelGrid>
      ) : null}
      <ClusterDrawer
        open={clusterDrawerOpen}
        cluster={drawer.kind === 'edit-cluster' ? cluster : null}
        locale={locale}
        token={token}
        onClose={() => setDrawer({ kind: 'closed' })}
        onSaved={(saved) => { setCluster(saved); setDrawer({ kind: 'closed' }); setNotice(text.clusterSaved) }}
      />
      {cluster ? <FacilityDrawer
        open={facilityDrawerOpen}
        cluster={cluster}
        facility={editingFacility}
        locale={locale}
        token={token}
        onClose={() => setDrawer({ kind: 'closed' })}
        onSaved={(saved) => {
          setFacilities((current) => current.some((facility) => facility.id === saved.id)
            ? current.map((facility) => facility.id === saved.id ? saved : facility)
            : [...current, saved])
          setDrawer({ kind: 'closed' })
          setNotice(text.facilitySaved)
        }}
      /> : null}
    </Page>
  )
}

function ClusterSummary({ cluster, locale, text }: { readonly cluster: Cluster; readonly locale: Locale; readonly text: typeof copy[Locale] }) {
  return <div className="organization-overview-summary"><strong>{displayName(locale, cluster)}</strong><span className="organization-overview-meta" dir="ltr">{text.identifier}: {cluster.code}</span><StatusBadge>{text.active}</StatusBadge></div>
}

function FacilityTable({ facilities, locale, text, onEdit }: { readonly facilities: readonly Facility[]; readonly locale: Locale; readonly text: typeof copy[Locale]; readonly onEdit: (facility: Facility) => void }) {
  return <div className="table-scroll organization-overview-table" tabIndex={0} role="region" aria-label={text.facilities}>
    <table><thead><tr><th scope="col">{text.facilities}</th><th scope="col">{text.type}</th><th scope="col">{text.status}</th><th scope="col">{text.actions}</th></tr></thead><tbody>
      {facilities.map((facility) => <tr key={facility.id}><td><strong>{displayName(locale, facility)}</strong><br /><span className="organization-overview-meta" dir="ltr">{text.identifier}: {facility.code}</span></td><td>{facilityTypeLabel(locale, facility.type_code)}</td><td><StatusBadge>{facility.status === 'active' ? text.active : facility.status === 'inactive' ? text.inactive : text.archived}</StatusBadge></td><td><Button variant="secondary" onClick={() => onEdit(facility)}>{text.editFacility}</Button></td></tr>)}
    </tbody></table>
  </div>
}

import { type FormEvent, useEffect, useRef, useState } from 'react'

import { ApiError, createFacility, updateFacility, type Cluster, type Facility } from '../../api'
import { Button, Drawer, Field, Select } from '../../ui'

type Locale = 'ar' | 'en'
type SaveError = 'validation' | 'stale' | 'save' | null

const copy = {
  ar: {
    createTitle: 'إضافة منشأة', editTitle: 'تعديل المنشأة', close: 'إغلاق', cancel: 'إلغاء',
    intro: 'أدخل معلومات المنشأة. يُعرض الرقم التعريفي للمراجعة فقط ولا يُستخدم كاسم للمنشأة.',
    identifier: 'الرقم التعريفي', identifierHelp: 'يستخدم داخلياً عند الإنشاء فقط.', name: 'اسم المنشأة بالعربية', englishName: 'اسم المنشأة بالإنجليزية', type: 'نوع المنشأة', status: 'الحالة',
    saveCreate: 'حفظ المنشأة', saveEdit: 'حفظ التعديل', saving: 'جارٍ الحفظ…', validation: 'أكمل الحقول المطلوبة بالصيغة الصحيحة.', stale: 'تغيّرت البيانات في مكان آخر. حدّث الصفحة ثم أعد المحاولة.', saveError: 'تعذر حفظ بيانات المنشأة. أعد المحاولة.', codeHint: 'استخدم حروفاً إنجليزية كبيرة وأرقاماً وشرطة أو شرطة سفلية فقط.',
    hospital: 'مستشفى', center: 'مركز صحي', lab: 'مختبر', sharedServices: 'خدمات مشتركة', active: 'نشطة', inactive: 'غير نشطة', archived: 'مؤرشفة',
  },
  en: {
    createTitle: 'Add facility', editTitle: 'Edit facility', close: 'Close', cancel: 'Cancel',
    intro: 'Enter facility information. The identifier is retained for reference and is never the facility name.',
    identifier: 'Identifier', identifierHelp: 'Used internally when creating the facility.', name: 'Facility name in Arabic', englishName: 'Facility name in English', type: 'Facility type', status: 'Status',
    saveCreate: 'Save facility', saveEdit: 'Save changes', saving: 'Saving…', validation: 'Complete the required fields using the expected format.', stale: 'This information changed elsewhere. Refresh the page and try again.', saveError: 'Facility information could not be saved. Try again.', codeHint: 'Use uppercase letters, digits, hyphens, or underscores only.',
    hospital: 'Hospital', center: 'Health center', lab: 'Laboratory', sharedServices: 'Shared services', active: 'Active', inactive: 'Inactive', archived: 'Archived',
  },
}

const CODE_PATTERN = /^[A-Z0-9_-]{2,64}$/

function isFacilityStatus(value: string): value is Facility['status'] { return value === 'active' || value === 'inactive' || value === 'archived' }

export function FacilityDrawer({ open, cluster, facility, locale, token, onClose, onSaved }: { readonly open: boolean; readonly cluster: Cluster; readonly facility: Facility | null; readonly locale: Locale; readonly token: string; readonly onClose: () => void; readonly onSaved: (facility: Facility) => void }) {
  const text = copy[locale]
  const [code, setCode] = useState('')
  const [name, setName] = useState('')
  const [nameEn, setNameEn] = useState('')
  const [typeCode, setTypeCode] = useState('hospital')
  const [status, setStatus] = useState<Facility['status']>('active')
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<SaveError>(null)
  const errorRef = useRef<HTMLParagraphElement>(null)
  const editing = facility !== null

  useEffect(() => {
    if (!open) return
    setCode('')
    setName(facility?.name_ar ?? '')
    setNameEn(facility?.name_en ?? '')
    setTypeCode(facility?.type_code ?? 'hospital')
    setStatus(facility?.status ?? 'active')
    setError(null)
  }, [open, facility])

  function close() { if (!submitting) onClose() }
  function changeStatus(value: string) { if (isFacilityStatus(value)) setStatus(value) }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!name.trim() || (!editing && !CODE_PATTERN.test(code))) {
      setError('validation')
      window.requestAnimationFrame(() => errorRef.current?.focus())
      return
    }
    setSubmitting(true)
    setError(null)
    try {
      const saved = editing && facility
        ? await updateFacility(token, facility.id, facility.lock_version, { name: name.trim(), status })
        : await createFacility(token, { cluster_id: cluster.id, type_code: typeCode, code, name: name.trim(), name_en: nameEn.trim() || null })
      onSaved(saved)
    } catch (caught) {
      setError(caught instanceof ApiError && (caught.status === 409 || caught.status === 412) ? 'stale' : 'save')
      window.requestAnimationFrame(() => errorRef.current?.focus())
    } finally {
      setSubmitting(false)
    }
  }

  const codeInvalid = !editing && (error === 'validation' || code.length > 0) && !CODE_PATTERN.test(code)
  const errorMessage = error === 'validation' ? text.validation : error === 'stale' ? text.stale : error === 'save' ? text.saveError : null
  return <Drawer open={open} onClose={close} title={editing ? text.editTitle : text.createTitle} ariaLabelClose={text.close} dismissable={!submitting}>
    <p className="ui-drawer-intro">{text.intro}</p>
    <form className="organization-overview-drawer-form" onSubmit={(event) => void submit(event)} noValidate>
      {errorMessage ? <p ref={errorRef} className="error-summary" role="alert" tabIndex={-1}>{errorMessage}</p> : null}
      {!editing ? <Field id="facility-code" label={text.identifier} required help={text.identifierHelp} error={codeInvalid ? text.codeHint : undefined}><input id="facility-code" dir="ltr" value={code} required aria-required="true" aria-invalid={codeInvalid || undefined} aria-describedby={codeInvalid ? 'facility-code-error' : 'facility-code-help'} onChange={(event) => setCode(event.target.value.toUpperCase())} /></Field> : null}
      <Field id="facility-name" label={text.name} required error={error === 'validation' && !name.trim() ? text.validation : undefined}><input id="facility-name" value={name} required aria-required="true" aria-invalid={error === 'validation' && !name.trim() ? true : undefined} aria-describedby={error === 'validation' && !name.trim() ? 'facility-name-error' : undefined} onChange={(event) => setName(event.target.value)} /></Field>
      {!editing ? <Field id="facility-name-en" label={text.englishName}><input id="facility-name-en" value={nameEn} onChange={(event) => setNameEn(event.target.value)} /></Field> : null}
      {!editing ? <Field id="facility-type" label={text.type}><Select id="facility-type" value={typeCode} onChange={setTypeCode} options={[{ value: 'hospital', label: text.hospital }, { value: 'center', label: text.center }, { value: 'lab', label: text.lab }, { value: 'shared_services', label: text.sharedServices }]} /></Field> : null}
      {editing ? <Field id="facility-status" label={text.status}><Select id="facility-status" value={status} onChange={changeStatus} options={[{ value: 'active', label: text.active }, { value: 'inactive', label: text.inactive }, { value: 'archived', label: text.archived }]} /></Field> : null}
      <div className="organization-overview-drawer-footer"><Button variant="quiet" onClick={close} disabled={submitting}>{text.cancel}</Button><Button type="submit" disabled={submitting}>{submitting ? text.saving : editing ? text.saveEdit : text.saveCreate}</Button></div>
    </form>
  </Drawer>
}

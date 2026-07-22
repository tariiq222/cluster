import { type FormEvent, useState } from 'react'

import {
  createOrganizationUnit,
  type Cluster,
  type CreateOrganizationUnitInput,
  type Facility,
  type OrganizationUnit,
} from '../../api'
import { Button, Drawer, Field, Select, cx } from '../../ui'

type Locale = 'ar' | 'en'

const copy = {
  ar: {
    title: 'إضافة وحدة تنظيمية',
    close: 'إغلاق',
    description: 'املأ البيانات لإنشاء وحدة جديدة وربطها بمكانها في الهيكل.',
    code: 'الرمز',
    name: 'الاسم بالعربية',
    type: 'النوع',
    parent: 'الوحدة الأم',
    root: 'جذر التجمع',
    cancel: 'إلغاء',
    save: 'حفظ الوحدة',
    saving: 'جارٍ الحفظ…',
    validation: 'أكمل الحقول المطلوبة بالصيغة الصحيحة.',
    saveError: 'لم يُحفظ التغيير. راجع البيانات ثم أعد المحاولة.',
    codePatternHint: 'حروف إنجليزية كبيرة وأرقام وشرطات فقط (2–64).',
    types: { sector: 'قطاع', department: 'إدارة', section: 'قسم', unit: 'وحدة' },
    searchUnitsOrFacilities: 'ابحث عن وحدة أو منشأة…',
    noMatchingResults: 'لا توجد نتائج مطابقة',
  },
  en: {
    title: 'Add organization unit',
    close: 'Close',
    description: 'Fill in the form to create a new unit and place it in the tree.',
    code: 'Code',
    name: 'Name in Arabic',
    type: 'Type',
    parent: 'Parent',
    root: 'Cluster root',
    cancel: 'Cancel',
    save: 'Save unit',
    saving: 'Saving…',
    validation: 'Complete the required fields using the expected format.',
    saveError: 'The change was not saved. Review the data and try again.',
    codePatternHint: 'Uppercase letters, digits, hyphen or underscore (2–64).',
    types: { sector: 'Sector', department: 'Department', section: 'Section', unit: 'Unit' },
    searchUnitsOrFacilities: 'Search units or facilities…',
    noMatchingResults: 'No matching results',
  },
} as const

const unitTypes = [
  ['sector', 'sector'],
  ['department', 'department'],
  ['section', 'section'],
  ['unit', 'unit'],
] as const

const CODE_PATTERN = /^[A-Z0-9_-]{2,64}$/

/** Drawer that drives the "Add organization unit" form. Submission is
 * delegated to the caller through `onCreated` so the page can update its
 * state without the drawer knowing about siblings or rollbacks. */
export function AddUnitDrawer({
  open,
  onClose,
  locale,
  token,
  cluster,
  facilities,
  units,
  preselectedParentId,
  onCreated,
}: {
  open: boolean
  onClose: () => void
  locale: Locale
  token: string
  cluster: Cluster
  facilities: Facility[]
  units: OrganizationUnit[]
  preselectedParentId?: string
  onCreated: (unit: OrganizationUnit) => void
}) {
  const text = copy[locale]
  const [parentId, setParentId] = useState<string>(preselectedParentId ?? '')
  const [typeCode, setTypeCode] = useState<string>('department')
  const [code, setCode] = useState('')
  const [name, setName] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<'validation' | 'save' | null>(null)

  function reset() {
    setParentId(preselectedParentId ?? '')
    setTypeCode('department')
    setCode('')
    setName('')
    setError(null)
  }

  function close() {
    if (submitting) return
    reset()
    onClose()
  }

  function buildPayload(): CreateOrganizationUnitInput | null {
    if (!CODE_PATTERN.test(code) || !name.trim()) return null
    return {
      cluster_id: cluster.id,
      parent_id: parentId || undefined,
      type_code: typeCode,
      code,
      name: name.trim(),
    }
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const payload = buildPayload()
    if (payload === null) {
      setError('validation')
      return
    }
    setSubmitting(true)
    setError(null)
    try {
      const created = await createOrganizationUnit(token, payload)
      onCreated(created)
      reset()
    } catch {
      setError('save')
    } finally {
      setSubmitting(false)
    }
  }

  const codeInvalid = code.length > 0 && !CODE_PATTERN.test(code)
  const nameInvalid = name.length > 0 && !name.trim()
  const errorMessage = error === null
    ? undefined
    : error === 'validation'
      ? text.validation
      : text.saveError

  return (
    <Drawer open={open} onClose={close} title={text.title} ariaLabelClose={text.close} dismissable={!submitting}>
      <p className="ui-drawer-intro">{text.description}</p>
      <form className="org-drawer-form" onSubmit={(event) => void submit(event)} noValidate>
        {errorMessage ? <div className="error-summary" role="alert">{errorMessage}</div> : null}
        <Field id="unit-code" label={text.code} required error={codeInvalid ? text.codePatternHint : undefined}>
          <input
            id="unit-code"
            dir="ltr"
            value={code}
            required
            aria-required="true"
            aria-invalid={codeInvalid || undefined}
            onChange={(event) => setCode(event.target.value.toUpperCase())}
          />
        </Field>
        <Field id="unit-name" label={text.name} required error={nameInvalid ? text.validation : undefined}>
          <input
            id="unit-name"
            value={name}
            required
            aria-required="true"
            aria-invalid={nameInvalid || undefined}
            onChange={(event) => setName(event.target.value)}
          />
        </Field>
        <Field id="unit-type" label={text.type}>
          <Select
            id="unit-type"
            value={typeCode}
            onChange={setTypeCode}
            options={unitTypes.map(([value, key]) => ({ value, label: text.types[key] }))}
          />
        </Field>
        <Field id="unit-parent" label={text.parent}>
          <Select
            id="unit-parent"
            value={parentId}
            onChange={setParentId}
            options={[
              { value: '', label: text.root },
              ...facilities.map((facility) => ({ value: facility.id, label: facility.name_ar })),
              ...units.map((unit) => ({
                value: unit.id,
                label: `${'— '.repeat(Math.min(unit.depth, 4))}${unit.name_ar}`,
              })),
            ]}
            searchPlaceholder={copy[locale].searchUnitsOrFacilities}
            emptyLabel={copy[locale].noMatchingResults}
          />
        </Field>
        <div className={cx('org-drawer-form-footer')}>
          <Button type="button" variant="quiet" onClick={close} disabled={submitting}>{text.cancel}</Button>
          <Button type="submit" disabled={submitting}>{submitting ? text.saving : text.save}</Button>
        </div>
      </form>
    </Drawer>
  )
}

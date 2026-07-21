import { type FormEvent, useState } from 'react'

import { ApiError, createPosition, type Position } from '../../api'
import { Button, Drawer, Field, Select } from '../../ui'

type Locale = 'ar' | 'en'

const copy = {
  ar: {
    title: 'إضافة منصب',
    close: 'إغلاق',
    description: 'املأ البيانات لتعريف منصب جديد وربطه بالوحدة المسؤولة.',
    code: 'الرمز',
    titleAr: 'المسمى الوظيفي',
    titleArHint: 'اختر مسمى من قائمة المسميات المعتمدة.',
    titleArPlaceholder: '— اختر مسمىً —',
    unit: 'الوحدة التنظيمية',
    manager: 'المنصب المدير',
    noManager: 'بلا منصب مدير',
    noUnit: 'لا توجد وحدة متاحة. أنشئ وحدة أولاً.',
    cancel: 'إلغاء',
    save: 'حفظ المنصب',
    saving: 'جارٍ الحفظ…',
    validation: 'أكمل الحقول المطلوبة بالصيغة الصحيحة.',
    saveError: 'لم يُحفظ التغيير. راجع البيانات ثم أعد المحاولة.',
    codePatternHint: 'حروف إنجليزية كبيرة وأرقام وشرطات فقط (2–64).',
  },
  en: {
    title: 'Add position',
    close: 'Close',
    description:
      'Fill in the form to define a new position and assign it to the owning unit.',
    code: 'Code',
    titleAr: 'Job title',
    titleArHint: 'Pick a title from the governed reference list.',
    titleArPlaceholder: '— Pick a job title —',
    unit: 'Organization unit',
    manager: 'Manager position',
    noManager: 'No manager position',
    noUnit: 'No units available. Create one first.',
    cancel: 'Cancel',
    save: 'Save position',
    saving: 'Saving…',
    validation: 'Complete the required fields using the expected format.',
    saveError: 'The change was not saved. Review the data and try again.',
    codePatternHint: 'Uppercase letters, digits, hyphen or underscore (2–64).',
  },
} as const

const CODE_PATTERN = /^[A-Z0-9_-]{2,64}$/

/** Drawer driving the "Add position" form. Same shape as the unit drawer
 * with the addition of a manager selector and a preselected unit when the
 * caller is binding the position to a tree row. */
export function AddPositionDrawer({
  open,
  onClose,
  locale,
  token,
  units,
  positions,
  jobTitles,
  preselectedUnitId,
  onCreated,
  onSessionExpired,
}: {
  open: boolean
  onClose: () => void
  locale: Locale
  token: string
  units: { id: string; name_ar: string; depth: number }[]
  positions: Position[]
  jobTitles: { id: string; title_ar: string; code: string }[]
  preselectedUnitId?: string
  onCreated: (position: Position) => void
  onSessionExpired: () => void
}) {
  const text = copy[locale]
  const [unitId, setUnitId] = useState<string>(
    preselectedUnitId ?? units[0]?.id ?? '',
  )
  const [managerId, setManagerId] = useState('')
  const [code, setCode] = useState('')
  const [jobTitleId, setJobTitleId] = useState<string>(jobTitles[0]?.id ?? '')
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<'validation' | 'save' | null>(null)

  function reset() {
    setUnitId(preselectedUnitId ?? units[0]?.id ?? '')
    setManagerId('')
    setCode('')
    setJobTitleId(jobTitles[0]?.id ?? '')
    setError(null)
  }

  function close() {
    if (submitting) return
    reset()
    onClose()
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!unitId || !CODE_PATTERN.test(code) || !jobTitleId) {
      setError('validation')
      return
    }
    const selectedTitle = jobTitles.find((jt) => jt.id === jobTitleId)
    if (!selectedTitle) {
      setError('validation')
      return
    }
    setSubmitting(true)
    setError(null)
    try {
      const created = await createPosition(token, {
        organization_unit_id: unitId,
        code,
        job_title_id: jobTitleId,
        manager_position_id: managerId || null,
      })
      onCreated(created)
      reset()
    } catch (failure) {
      if (failure instanceof ApiError && failure.status === 401)
        onSessionExpired()
      else setError('save')
    } finally {
      setSubmitting(false)
    }
  }

  const codeInvalid = code.length > 0 && !CODE_PATTERN.test(code)
  const titleMissing = !jobTitleId
  const errorMessage =
    error === null
      ? undefined
      : error === 'validation'
        ? text.validation
        : text.saveError

  if (units.length === 0) {
    return (
      <Drawer
        open={open}
        onClose={close}
        title={text.title}
        ariaLabelClose={text.close}
        dismissable
      >
        <p className="ui-drawer-intro">{text.noUnit}</p>
      </Drawer>
    )
  }

  return (
    <Drawer
      open={open}
      onClose={close}
      title={text.title}
      ariaLabelClose={text.close}
      dismissable={!submitting}
    >
      <p className="ui-drawer-intro">{text.description}</p>
      <form
        className="org-drawer-form"
        onSubmit={(event) => void submit(event)}
        noValidate
      >
        {errorMessage ? (
          <div className="error-summary" role="alert">
            {errorMessage}
          </div>
        ) : null}
        <Field
          id="position-code"
          label={text.code}
          required
          error={codeInvalid ? text.codePatternHint : undefined}
        >
          <input
            id="position-code"
            dir="ltr"
            value={code}
            required
            aria-required="true"
            aria-invalid={codeInvalid || undefined}
            onChange={(event) => setCode(event.target.value.toUpperCase())}
          />
        </Field>
        <Field
          id="position-title"
          label={text.titleAr}
          required
          error={titleMissing ? text.titleArHint : undefined}
        >
          <Select
            id="position-title"
            value={jobTitleId}
            onChange={setJobTitleId}
            options={[
              { value: '', label: text.titleArPlaceholder },
              ...jobTitles.map((jt) => ({
                value: jt.id,
                label: `${jt.title_ar} (${jt.code})`,
              })),
            ]}
            searchPlaceholder={
              locale === 'ar' ? 'ابحث عن مسمى…' : 'Search job titles…'
            }
            emptyLabel={
              locale === 'ar' ? 'لا توجد نتائج مطابقة' : 'No matching results'
            }
          />
        </Field>
        <Field id="position-unit" label={text.unit}>
          <Select
            id="position-unit"
            value={unitId}
            onChange={setUnitId}
            options={units.map((unit) => ({
              value: unit.id,
              label: `${'— '.repeat(Math.min(unit.depth, 4))}${unit.name_ar}`,
            }))}
            searchPlaceholder={
              locale === 'ar' ? 'ابحث عن وحدة…' : 'Search units…'
            }
            emptyLabel={
              locale === 'ar' ? 'لا توجد نتائج مطابقة' : 'No matching results'
            }
          />
        </Field>
        <Field id="position-manager" label={text.manager}>
          <Select
            id="position-manager"
            value={managerId}
            onChange={setManagerId}
            options={[
              { value: '', label: text.noManager },
              ...positions.map((position) => ({
                value: position.id,
                label: position.title_ar,
              })),
            ]}
            searchPlaceholder={
              locale === 'ar' ? 'ابحث عن منصب…' : 'Search positions…'
            }
            emptyLabel={
              locale === 'ar' ? 'لا توجد نتائج مطابقة' : 'No matching results'
            }
          />
        </Field>
        <div className="org-drawer-form-footer">
          <Button
            type="button"
            variant="quiet"
            onClick={close}
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

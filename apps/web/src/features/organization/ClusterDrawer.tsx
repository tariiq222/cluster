import { type FormEvent, useEffect, useRef, useState } from 'react'

import { ApiError, createCluster, updateCluster, type Cluster } from '../../api'
import { Button, Drawer, Field } from '../../ui'

type Locale = 'ar' | 'en'
type SaveError = 'validation' | 'stale' | 'save' | null

const copy = {
  ar: {
    createTitle: 'إضافة تجمع', editTitle: 'تعديل بيانات التجمع', close: 'إغلاق', cancel: 'إلغاء',
    intro: 'أدخل البيانات الأساسية للتجمع. الحقول الظاهرة هنا فقط هي التي يمكن حفظها.',
    identifier: 'الرقم التعريفي', identifierHelp: 'يستخدم داخلياً عند الإنشاء فقط، ولا يظهر كعنوان للتجمع.',
    name: 'اسم التجمع بالعربية', englishName: 'اسم التجمع بالإنجليزية', saveCreate: 'حفظ التجمع', saveEdit: 'حفظ التعديل', saving: 'جارٍ الحفظ…',
    validation: 'أكمل الحقول المطلوبة بالصيغة الصحيحة.', stale: 'تغيّرت البيانات في مكان آخر. حدّث الصفحة ثم أعد المحاولة.', saveError: 'تعذر حفظ بيانات التجمع. أعد المحاولة.', codeHint: 'استخدم حروفاً إنجليزية كبيرة وأرقاماً وشرطة أو شرطة سفلية فقط.',
  },
  en: {
    createTitle: 'Add cluster', editTitle: 'Edit cluster information', close: 'Close', cancel: 'Cancel',
    intro: 'Enter the cluster basics. Only the supported fields shown here can be saved.',
    identifier: 'Identifier', identifierHelp: 'Used internally when creating the cluster and never used as its main label.',
    name: 'Cluster name in Arabic', englishName: 'Cluster name in English', saveCreate: 'Save cluster', saveEdit: 'Save changes', saving: 'Saving…',
    validation: 'Complete the required fields using the expected format.', stale: 'This information changed elsewhere. Refresh the page and try again.', saveError: 'Cluster information could not be saved. Try again.', codeHint: 'Use uppercase letters, digits, hyphens, or underscores only.',
  },
}

const CODE_PATTERN = /^[A-Z0-9_-]{2,64}$/

export function ClusterDrawer({ open, cluster, locale, token, onClose, onSaved }: { readonly open: boolean; readonly cluster: Cluster | null; readonly locale: Locale; readonly token: string; readonly onClose: () => void; readonly onSaved: (cluster: Cluster) => void }) {
  const text = copy[locale]
  const [code, setCode] = useState('')
  const [name, setName] = useState('')
  const [nameEn, setNameEn] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<SaveError>(null)
  const errorRef = useRef<HTMLParagraphElement>(null)
  const editing = cluster !== null

  useEffect(() => {
    if (!open) return
    setCode('')
    setName(cluster?.name_ar ?? '')
    setNameEn(cluster?.name_en ?? '')
    setError(null)
  }, [open, cluster])

  function close() { if (!submitting) onClose() }

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
      const saved = editing && cluster
        ? await updateCluster(token, cluster.lock_version, { name: name.trim() })
        : await createCluster(token, { code, name: name.trim(), name_en: nameEn.trim() || null })
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
      {!editing ? <Field id="cluster-code" label={text.identifier} required help={text.identifierHelp} error={codeInvalid ? text.codeHint : undefined}><input id="cluster-code" dir="ltr" value={code} required aria-required="true" aria-invalid={codeInvalid || undefined} aria-describedby={codeInvalid ? 'cluster-code-error' : 'cluster-code-help'} onChange={(event) => setCode(event.target.value.toUpperCase())} /></Field> : null}
      <Field id="cluster-name" label={text.name} required error={error === 'validation' && !name.trim() ? text.validation : undefined}><input id="cluster-name" value={name} required aria-required="true" aria-invalid={error === 'validation' && !name.trim() ? true : undefined} aria-describedby={error === 'validation' && !name.trim() ? 'cluster-name-error' : undefined} onChange={(event) => setName(event.target.value)} /></Field>
      {!editing ? <Field id="cluster-name-en" label={text.englishName}><input id="cluster-name-en" value={nameEn} onChange={(event) => setNameEn(event.target.value)} /></Field> : null}
      <div className="organization-overview-drawer-footer"><Button variant="quiet" onClick={close} disabled={submitting}>{text.cancel}</Button><Button type="submit" disabled={submitting}>{submitting ? text.saving : editing ? text.saveEdit : text.saveCreate}</Button></div>
    </form>
  </Drawer>
}

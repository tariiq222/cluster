import { type FormEvent, useRef, useState } from 'react'
import { useLocale, useToken } from '../../app/session-context'

import { changeIdentityPassword } from '../../api'
import { Button, Field as UiField, Page, PageHeader, Panel } from '../../ui'

type Locale = 'ar' | 'en'

/** The server rejects anything shorter; mirrored here so the user hears it before submitting. */
const MINIMUM_PASSWORD_LENGTH = 14

const copy = {
  ar: {
    title: 'الأمان وكلمة المرور',
    intro: 'غيّر كلمة مرورك الخاصة. هذه الصفحة تخص حسابك أنت فقط.',
    panel: 'تغيير كلمة المرور',
    currentPassword: 'كلمة المرور الحالية',
    newPassword: 'كلمة المرور الجديدة',
    confirmPassword: 'تأكيد كلمة المرور الجديدة',
    lengthHint: '١٤ حرفاً على الأقل. اختر عبارة تسهل عليك تذكرها ويصعب تخمينها.',
    confirmHint: 'أعد كتابة كلمة المرور الجديدة نفسها.',
    submit: 'حفظ كلمة المرور',
    saving: 'جارٍ الحفظ…',
    tooShort: 'كلمة المرور الجديدة قصيرة. اكتب ١٤ حرفاً على الأقل.',
    mismatch: 'الكلمتان غير متطابقتين. تأكد من التأكيد.',
    failed: 'تعذر تغيير كلمة المرور. تأكد من كلمة المرور الحالية ثم أعد المحاولة.',
    success: 'تم تغيير كلمة المرور. سيُطلب منك تسجيل الدخول من جديد.',
  },
  en: {
    title: 'Security and password',
    intro: 'Change your own password. This page is about your account only.',
    panel: 'Change password',
    currentPassword: 'Current password',
    newPassword: 'New password',
    confirmPassword: 'Confirm new password',
    lengthHint: `At least ${MINIMUM_PASSWORD_LENGTH} characters. Pick a phrase that is easy for you to recall and hard to guess.`,
    confirmHint: 'Type the same new password again.',
    submit: 'Save password',
    saving: 'Saving…',
    tooShort: `The new password is too short — use at least ${MINIMUM_PASSWORD_LENGTH} characters.`,
    mismatch: 'The two entries do not match. Check the confirmation.',
    failed: 'The password could not be changed. Check your current password and try again.',
    success: 'Password changed. You will be asked to sign in again.',
  },
} as const

type Problem = 'tooShort' | 'mismatch' | 'failed'

export function PersonalSecurity() {
  const locale = useLocale() as Locale
  const token = useToken()
  const text = copy[locale]
  const [currentPassword, setCurrentPassword] = useState('')
  const [newPassword, setNewPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [problem, setProblem] = useState<Problem | null>(null)
  const [succeeded, setSucceeded] = useState(false)
  const errorRef = useRef<HTMLParagraphElement>(null)

  function fail(kind: Problem) {
    setProblem(kind); setSubmitting(false)
    window.requestAnimationFrame(() => errorRef.current?.focus())
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setSubmitting(true); setProblem(null); setSucceeded(false)
    if (newPassword.length < MINIMUM_PASSWORD_LENGTH) { fail('tooShort'); return }
    if (newPassword !== confirmation) { fail('mismatch'); return }
    try {
      await changeIdentityPassword(token, {
        current_password: currentPassword,
        new_password: newPassword,
        new_password_confirmation: confirmation,
      })
      setSucceeded(true); setCurrentPassword(''); setNewPassword(''); setConfirmation('')
    } catch { fail('failed'); return }
    finally { setSubmitting(false) }
  }

  return <Page>
    <PageHeader id="personal-security-heading" title={text.title} description={text.intro} />
    <Panel id="password-heading" title={text.panel} level={2}>
      <form className="resource-form" onSubmit={(event) => void submit(event)} noValidate>
        {problem && <p className="error-summary" role="alert" tabIndex={-1} ref={errorRef}>{text[problem]}</p>}
        {succeeded && <p className="status-message" role="status">{text.success}</p>}
        <PasswordField id="current-password" label={text.currentPassword} value={currentPassword} onChange={setCurrentPassword} autoComplete="current-password" />
        <PasswordField id="new-password" label={text.newPassword} value={newPassword} onChange={setNewPassword} autoComplete="new-password" hint={text.lengthHint} invalid={problem === 'tooShort'} />
        <PasswordField id="confirm-password" label={text.confirmPassword} value={confirmation} onChange={setConfirmation} autoComplete="new-password" hint={text.confirmHint} invalid={problem === 'mismatch'} />
        <Button type="submit" disabled={submitting}>{submitting ? text.saving : text.submit}</Button>
      </form>
    </Panel>
  </Page>
}

function PasswordField({ id, label, value, onChange, autoComplete, hint, invalid = false }: {
  id: string
  label: string
  value: string
  onChange: (value: string) => void
  autoComplete: string
  hint?: string
  invalid?: boolean
}) {
  return <UiField id={id} label={label} required help={hint}>
    <input
      id={id}
      type="password"
      value={value}
      required
      aria-required="true"
      aria-invalid={invalid}
      autoComplete={autoComplete}
      onChange={(event) => onChange(event.target.value)}
    />
  </UiField>
}

import { useRef, useState, type FormEvent } from 'react'
import * as generated from '../../../src/api/generated/cluster'
import { requestInit, unwrapEmpty } from '../../api/http'
import { useLocale, useSessionToken } from '../../app/session-context'
import { Button, Field, Page, PageHeader, Panel } from '../../ui'

const MINIMUM_PASSWORD_LENGTH = 14

const copy = {
  ar: {
    title: 'الأمان الشخصي',
    intro: 'غيّر كلمة مرور حسابك. هذه الصفحة تخصك أنت فقط.',
    panel: 'تغيير كلمة المرور',
    currentPassword: 'كلمة المرور الحالية',
    newPassword: 'كلمة المرور الجديدة',
    confirmPassword: 'تأكيد كلمة المرور الجديدة',
    lengthHint: '١٤ حرفاً على الأقل. اختر عبارة يسهل تذكّرها ويصعب تخمينها.',
    confirmHint: 'أعد كتابة كلمة المرور الجديدة نفسها.',
    submit: 'حفظ كلمة المرور',
    saving: 'جارٍ الحفظ…',
    tooShort: 'كلمة المرور الجديدة قصيرة جداً. اكتب ١٤ حرفاً على الأقل.',
    mismatch: 'الكلمتان غير متطابقتين. تحقّق من التأكيد.',
    failed: 'تعذّر تغيير كلمة المرور. تأكّد من كلمة المرور الحالية ثم أعد المحاولة.',
    success: 'تم تغيير كلمة المرور. سيُطلب منك تسجيل الدخول من جديد.',
    changeAgain: 'تغيير كلمة مرور أخرى',
  },
  en: {
    title: 'Personal security',
    intro: 'Change your account password. This page is about you only.',
    panel: 'Change password',
    currentPassword: 'Current password',
    newPassword: 'New password',
    confirmPassword: 'Confirm new password',
    lengthHint: `At least ${MINIMUM_PASSWORD_LENGTH} characters. Pick a phrase that is easy to recall and hard to guess.`,
    confirmHint: 'Type the same new password again.',
    submit: 'Save password',
    saving: 'Saving…',
    tooShort: `The new password is too short — use at least ${MINIMUM_PASSWORD_LENGTH} characters.`,
    mismatch: 'The two entries do not match. Check the confirmation.',
    failed: 'The password could not be changed. Check your current password and try again.',
    success: 'Password changed. You will be asked to sign in again.',
    changeAgain: 'Change another password',
  },
} as const

type Problem = 'tooShort' | 'mismatch' | 'failed'
type Phase = 'ready' | 'form' | 'success' | 'error'

export function PersonalSecurityScreen() {
  const locale = useLocale()
  const csrfToken = useSessionToken()
  const text = copy[locale]
  const [phase, setPhase] = useState<Phase>('ready')
  const [problem, setProblem] = useState<Problem | null>(null)
  const [currentPassword, setCurrentPassword] = useState('')
  const [newPassword, setNewPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const errorRef = useRef<HTMLParagraphElement>(null)

  function fail(kind: Problem) {
    setProblem(kind)
    setPhase('error')
    window.requestAnimationFrame(() => errorRef.current?.focus())
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (newPassword.length < MINIMUM_PASSWORD_LENGTH) { fail('tooShort'); return }
    if (newPassword !== confirmation) { fail('mismatch'); return }
    setProblem(null)
    setPhase('form')
    try {
      await unwrapEmpty(
        await generated.changeIdentityPassword(
          {
            current_password: currentPassword,
            new_password: newPassword,
            new_password_confirmation: confirmation,
          },
          requestInit(csrfToken, { mutation: true }),
        ),
      )
      setCurrentPassword('')
      setNewPassword('')
      setConfirmation('')
      setPhase('success')
    } catch {
      fail('failed')
    }
  }

  function reset() {
    setProblem(null)
    setPhase('ready')
  }

  return (
    <Page>
      <PageHeader id="personal-security-heading" title={text.title} description={text.intro} />
      <Panel id="password-panel-heading" title={text.panel} level={2}>
        {phase === 'success' ? (
          <div className="state-panel" role="status">
            <p>{text.success}</p>
            <Button variant="secondary" type="button" onClick={reset}>
              {text.changeAgain}
            </Button>
          </div>
        ) : (
          <form className="resource-form" onSubmit={(event) => void submit(event)} noValidate>
            {phase === 'error' && problem && (
              <p className="error-summary" role="alert" tabIndex={-1} ref={errorRef}>
                {text[problem]}
              </p>
            )}
            <Field id="current-password" label={text.currentPassword} required>
              <input
                id="current-password"
                type="password"
                value={currentPassword}
                required
                aria-required="true"
                autoComplete="current-password"
                onChange={(event) => setCurrentPassword(event.target.value)}
              />
            </Field>
            <Field id="new-password" label={text.newPassword} required help={text.lengthHint} error={problem === 'tooShort' ? text.tooShort : null}>
              <input
                id="new-password"
                type="password"
                value={newPassword}
                required
                aria-required="true"
                aria-invalid={problem === 'tooShort'}
                autoComplete="new-password"
                onChange={(event) => setNewPassword(event.target.value)}
              />
            </Field>
            <Field id="confirm-password" label={text.confirmPassword} required help={text.confirmHint} error={problem === 'mismatch' ? text.mismatch : null}>
              <input
                id="confirm-password"
                type="password"
                value={confirmation}
                required
                aria-required="true"
                aria-invalid={problem === 'mismatch'}
                autoComplete="new-password"
                onChange={(event) => setConfirmation(event.target.value)}
              />
            </Field>
            <div className="form-actions">
              <Button type="submit" disabled={phase === 'form'}>
                {phase === 'form' ? text.saving : text.submit}
              </Button>
            </div>
          </form>
        )}
      </Panel>
    </Page>
  )
}

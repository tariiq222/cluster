import { type FormEvent, useEffect, useRef, useState } from 'react'
import { formattingLocale } from '../../app/copy'
import { useLocale, useToken } from '../../app/session-context'

import { Users } from 'lucide-react'

import {
  ApiError,
  createUserAccount,
  issueIdentityActivation,
  changeIdentityPassword,
  listPeople,
  listUserAccounts,
  transitionUserAccount,
  type Person,
  type UserAccount,
  type UserAccountAction,
  stateFromError,
} from '../../api'
import {
  Button,
  EmptyState,
  Field as UiField,
  InlineError,
  Page,
  PageHeader,
  Panel,
  PanelGrid,
  Select as UiSelect,
  SkeletonList,
} from '../../ui'

type Locale = 'ar' | 'en'

const copy = {
  ar: {
    title: 'حسابات الهوية', intro: 'إدارة الحسابات المرتبطة بسجل Person من دون عرض بيانات اعتماد أو أسرار.',
    loading: 'جارٍ تحميل الحسابات…', forbidden: 'لا تملك صلاحية إدارة حسابات الهوية.', error: 'تعذر تحميل الحسابات.', retry: 'إعادة المحاولة',
    accounts: 'الحسابات', noAccounts: 'لا توجد حسابات بعد.', username: 'اسم المستخدم', person: 'الشخص', status: 'الحالة', password: 'تغيير كلمة المرور', required: 'مطلوب', notRequired: 'غير مطلوب',
    addAccount: 'إنشاء حساب pending', action: 'إجراء على الحساب', account: 'الحساب', reason: 'سبب الإجراء', execute: 'تنفيذ الإجراء', saving: 'جارٍ الحفظ…',
    validation: 'اختر Person واكتب اسم مستخدم صحيحاً.', saveError: 'لم يُحفظ التغيير. أعد تحميل البيانات أو راجع حالة الحساب.', stale: 'تغيرت نسخة الحساب. أعد المحاولة بعد التحديث.',
    pending: 'بانتظار التفعيل', active: 'نشط', locked: 'مقفل', disabled: 'معطل', archived: 'مؤرشف',
    activate: 'تفعيل', unlock: 'فك القفل', disable: 'تعطيل', archive: 'أرشفة', revokeSessions: 'إنهاء الجلسات', forcePassword: 'فرض تغيير كلمة المرور',
    noEligiblePeople: 'لا يوجد Person نشط بلا حساب حالياً.', activation: 'إصدار تفعيل', activationIssued: 'تم إصدار التفعيل', activationExpiry: 'ينتهي في', activationDelivery: 'التسليم', activationError: 'تعذر إصدار التفعيل.', currentPassword: 'كلمة المرور الحالية', newPassword: 'كلمة المرور الجديدة', confirmPassword: 'تأكيد كلمة المرور', changeOwnPassword: 'تغيير كلمة مرور المستخدم الحالي', passwordChanged: 'تم تغيير كلمة المرور. ستنتهي الجلسة الحالية.', passwordError: 'تعذر تغيير كلمة المرور.',
  },
  en: {
    title: 'Identity accounts', intro: 'Manage Person-linked accounts without exposing credentials or secrets.',
    loading: 'Loading accounts…', forbidden: 'You do not have permission to manage Identity accounts.', error: 'Accounts could not be loaded.', retry: 'Try again',
    accounts: 'Accounts', noAccounts: 'No accounts yet.', username: 'Username', person: 'Person', status: 'Status', password: 'Password change', required: 'Required', notRequired: 'Not required',
    addAccount: 'Create pending account', action: 'Account action', account: 'Account', reason: 'Action reason', execute: 'Execute action', saving: 'Saving…',
    validation: 'Select a Person and enter a valid username.', saveError: 'The change was not saved. Reload or review the account state.', stale: 'The account version changed. Reload and try again.',
    pending: 'Pending', active: 'Active', locked: 'Locked', disabled: 'Disabled', archived: 'Archived',
    activate: 'Activate', unlock: 'Unlock', disable: 'Disable', archive: 'Archive', revokeSessions: 'Revoke sessions', forcePassword: 'Force password change',
    noEligiblePeople: 'There is no active Person without an account.', activation: 'Issue activation', activationIssued: 'Activation issued', activationExpiry: 'Expires', activationDelivery: 'Delivery', activationError: 'Activation could not be issued.', currentPassword: 'Current password', newPassword: 'New password', confirmPassword: 'Confirm password', changeOwnPassword: 'Change current user password', passwordChanged: 'Password changed. This session will be revoked.', passwordError: 'Password could not be changed.',
  },
} as const

const actions: Array<{ value: UserAccountAction; label: keyof typeof copy.ar }> = [
  { value: 'activate', label: 'activate' }, { value: 'unlock', label: 'unlock' },
  { value: 'disable', label: 'disable' }, { value: 'archive', label: 'archive' },
  { value: 'revoke-sessions', label: 'revokeSessions' }, { value: 'force-password-change', label: 'forcePassword' },
]

export function IdentityAccounts() {
  const locale = useLocale()
  const token = useToken()
  const text = copy[locale]
  const [accounts, setAccounts] = useState<UserAccount[]>([])
  const [people, setPeople] = useState<Person[]>([])
  const [loading, setLoading] = useState(true)
  const [state, setState] = useState<'ready' | 'forbidden' | 'error'>('ready')
  async function load() {
    setLoading(true); setState('ready')
    try {
      const [accountPage, peoplePage] = await Promise.all([listUserAccounts(token), listPeople(token)])
      setAccounts(accountPage.items); setPeople(peoplePage.items)
    } catch (error) {
      setAccounts([]); setPeople([])
      setState(stateFromError(error) === 'forbidden' ? 'forbidden' : 'error')
    } finally { setLoading(false) }
  }
  useEffect(() => {
    void load()
    // This route reloads only when the authenticated session changes.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [token])
  const claimedPeople = new Set(accounts.filter((account) => account.status !== 'archived').map((account) => account.person_id))
  const eligiblePeople = people.filter((person) => person.status === 'active' && !claimedPeople.has(person.id))

  return <Page>
    <PageHeader id="identity-heading" title={text.title} description={text.intro} />
    {loading && <SkeletonList label={text.loading} />}
    {!loading && state === 'forbidden' && <div className="state-panel" role="status"><p>{text.forbidden}</p></div>}
    {!loading && state === 'error' && <InlineError message={text.error} retryLabel={text.retry} onRetry={() => void load()} />}
    {!loading && state === 'ready' && <PanelGrid>
      <Panel id="accounts-heading" title={text.accounts} level={2} actions={<span className="count-badge">{new Intl.NumberFormat(formattingLocale(locale)).format(accounts.length)}</span>}>
        {accounts.length === 0 ? <EmptyState icon={<Users />} title={text.noAccounts} /> : <AccountTable accounts={accounts} locale={locale} />}
        {eligiblePeople.length > 0 ? <AccountForm locale={locale} token={token} people={eligiblePeople} onCreated={(account) => setAccounts((current) => [...current, account])} /> : <p className="status-message" role="status">{text.noEligiblePeople}</p>}
        <AccountActivationForm locale={locale} token={token} accounts={accounts} />
        <PasswordChangeForm locale={locale} token={token} />
      </Panel>
      {accounts.length > 0 && <Panel id="account-action-heading" title={text.action} level={2}><AccountActionForm locale={locale} token={token} accounts={accounts} onChanged={(account) => setAccounts((current) => current.map((item) => item.id === account.id ? account : item))} /></Panel>}
    </PanelGrid>}
  </Page>
}

function AccountTable({ accounts, locale }: { accounts: UserAccount[]; locale: Locale }) {
  const text = copy[locale]
  return <div className="table-scroll" tabIndex={0} role="region" aria-label={text.accounts}><table><thead><tr><th scope="col">{text.username}</th><th scope="col">{text.person}</th><th scope="col">{text.status}</th><th scope="col">{text.password}</th></tr></thead><tbody>{accounts.map((account) => <tr key={account.id}><td dir="ltr">{account.username}</td><td>{locale === 'en' && account.display_name_en ? account.display_name_en : account.display_name_ar}</td><td><span className="status-badge">{text[account.status]}</span></td><td>{account.must_change_password ? text.required : text.notRequired}</td></tr>)}</tbody></table></div>
}

function AccountForm({ locale, token, people, onCreated }: { locale: Locale; token: string; people: Person[]; onCreated: (account: UserAccount) => void; }) {
  const text = copy[locale]
  const [personId, setPersonId] = useState(people[0]?.id ?? '')
  const [username, setUsername] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState(false)
  const errorRef = useRef<HTMLParagraphElement>(null)
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const person = people.find((item) => item.id === personId)
    if (!person || !/^[a-zA-Z0-9._-]{3,128}$/.test(username)) { setError(true); window.requestAnimationFrame(() => errorRef.current?.focus()); return }
    setSubmitting(true); setError(false)
    try { onCreated(await createUserAccount(token, { person_id: person.id, person_version: person.person_version, username })) }
    catch { setError(true); window.requestAnimationFrame(() => errorRef.current?.focus()) }
    finally { setSubmitting(false) }
  }
  return <form className="resource-form" onSubmit={(event) => void submit(event)} noValidate>
    {error && <p className="error-summary" role="alert" tabIndex={-1} ref={errorRef}>{text.validation}</p>}
    <div className="field-row"><Select id="account-person" label={text.person} value={personId} onChange={setPersonId} options={people.map((person) => ({ value: person.id, label: person.display_name_ar }))} /><Field id="account-username" label={text.username} value={username} onChange={setUsername} required invalid={error && !/^[a-zA-Z0-9._-]{3,128}$/.test(username)} /></div>
    <Button type="submit" disabled={submitting}>{submitting ? text.saving : text.addAccount}</Button>
  </form>
}

function AccountActionForm({ locale, token, accounts, onChanged }: { locale: Locale; token: string; accounts: UserAccount[]; onChanged: (account: UserAccount) => void; }) {
  const text = copy[locale]
  const available = accounts.filter((account) => account.status !== 'archived')
  const [accountId, setAccountId] = useState(available[0]?.id ?? '')
  const [action, setAction] = useState<UserAccountAction>('activate')
  const [reason, setReason] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<'save' | 'stale' | null>(null)
  const errorRef = useRef<HTMLParagraphElement>(null)
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); setSubmitting(true); setError(null)
    try { onChanged(await transitionUserAccount(token, accountId, action, reason.trim() || undefined)) }
    catch (failure) {
      { setError(failure instanceof ApiError && failure.status === 412 ? 'stale' : 'save'); window.requestAnimationFrame(() => errorRef.current?.focus()) }
    } finally { setSubmitting(false) }
  }
  if (available.length === 0) return null
  return <form className="resource-form" onSubmit={(event) => void submit(event)}>
    {error && <p className="error-summary" role="alert" tabIndex={-1} ref={errorRef}>{error === 'stale' ? text.stale : text.saveError}</p>}
    <div className="field-row"><Select id="action-account" label={text.account} value={accountId} onChange={setAccountId} options={available.map((account) => ({ value: account.id, label: account.username }))} /><Select id="account-action" label={text.action} value={action} onChange={(value) => setAction(value as UserAccountAction)} options={actions.map((item) => ({ value: item.value, label: text[item.label] }))} /><Field id="action-reason" label={text.reason} value={reason} onChange={setReason} /></div>
    <Button type="submit" disabled={submitting}>{submitting ? text.saving : text.execute}</Button>
  </form>
}

function AccountActivationForm({ locale, token, accounts }: { locale: Locale; token: string; accounts: UserAccount[]; }) {
  const text = copy[locale]
  const pending = accounts.filter((account) => account.status === 'pending')
  const [accountId, setAccountId] = useState(pending[0]?.id ?? '')
  const [submitting, setSubmitting] = useState(false)
  const [message, setMessage] = useState<IdentityActivationResult | null>(null)
  const [error, setError] = useState(false)
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); setSubmitting(true); setError(false); setMessage(null)
    try { setMessage(await issueIdentityActivation(token, accountId)) }
    catch { setError(true) }
    finally { setSubmitting(false) }
  }
  if (pending.length === 0) return null
  return <form className="resource-form" onSubmit={(event) => void submit(event)}>
    <div className="field-row"><Select id="activation-account" label={text.account} value={accountId} onChange={setAccountId} options={pending.map((account) => ({ value: account.id, label: account.username }))} /></div>
    {error && <p className="error-summary" role="alert">{text.activationError}</p>}
    {message && <p className="status-message" role="status">{text.activationIssued} — {text.activationExpiry}: <span dir="ltr">{message.expires_at}</span>; {text.activationDelivery}: {message.delivery}</p>}
    <Button type="submit" disabled={submitting || !accountId}>{submitting ? text.saving : text.activation}</Button>
  </form>
}

type IdentityActivationResult = Awaited<ReturnType<typeof issueIdentityActivation>>

function PasswordChangeForm({ locale, token }: { locale: Locale; token: string; }) {
  const text = copy[locale]
  const [currentPassword, setCurrentPassword] = useState('')
  const [newPassword, setNewPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [result, setResult] = useState<'success' | 'error' | null>(null)
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); setSubmitting(true); setResult(null)
    if (newPassword.length < 14 || newPassword !== confirmation) { setResult('error'); setSubmitting(false); return }
    try { await changeIdentityPassword(token, { current_password: currentPassword, new_password: newPassword, new_password_confirmation: confirmation }); setResult('success'); setCurrentPassword(''); setNewPassword(''); setConfirmation('') }
    catch { setResult('error') }
    finally { setSubmitting(false) }
  }
  return <form className="resource-form" onSubmit={(event) => void submit(event)} aria-label={text.changeOwnPassword}>
    <h3>{text.changeOwnPassword}</h3>
    {result === 'error' && <p className="error-summary" role="alert">{text.passwordError}</p>}
    {result === 'success' && <p className="status-message" role="status">{text.passwordChanged}</p>}
    <div className="field-row"><Field id="current-password" type="password" label={text.currentPassword} value={currentPassword} onChange={setCurrentPassword} required /><Field id="new-password" type="password" label={text.newPassword} value={newPassword} onChange={setNewPassword} required /><Field id="confirm-password" type="password" label={text.confirmPassword} value={confirmation} onChange={setConfirmation} required /></div>
    <Button type="submit" disabled={submitting}>{submitting ? text.saving : text.changeOwnPassword}</Button>
  </form>
}

function Field({ id, label, value, onChange, type = 'text', required = false, invalid = false }: { id: string; label: string; value: string; onChange: (value: string) => void; type?: 'text' | 'password'; required?: boolean; invalid?: boolean }) { return <UiField id={id} label={label} required={required}><input id={id} type={type} value={value} required={required} aria-required={required || undefined} aria-invalid={invalid} onChange={(event) => onChange(event.target.value)} /></UiField> }
function Select({ id, label, value, onChange, options }: { id: string; label: string; value: string; onChange: (value: string) => void; options: Array<{ value: string; label: string }> }) { return <UiField id={id} label={label}><UiSelect id={id} value={value} onChange={onChange} options={options} /></UiField> }

import { type FormEvent, useEffect, useRef, useState } from 'react'

import {
  ApiError,
  createUserAccount,
  listPeople,
  listUserAccounts,
  transitionUserAccount,
  type Person,
  type UserAccount,
  type UserAccountAction,
} from '../../api'

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
    noEligiblePeople: 'لا يوجد Person نشط بلا حساب حالياً.',
  },
  en: {
    title: 'Identity accounts', intro: 'Manage Person-linked accounts without exposing credentials or secrets.',
    loading: 'Loading accounts…', forbidden: 'You do not have permission to manage Identity accounts.', error: 'Accounts could not be loaded.', retry: 'Try again',
    accounts: 'Accounts', noAccounts: 'No accounts yet.', username: 'Username', person: 'Person', status: 'Status', password: 'Password change', required: 'Required', notRequired: 'Not required',
    addAccount: 'Create pending account', action: 'Account action', account: 'Account', reason: 'Action reason', execute: 'Execute action', saving: 'Saving…',
    validation: 'Select a Person and enter a valid username.', saveError: 'The change was not saved. Reload or review the account state.', stale: 'The account version changed. Reload and try again.',
    pending: 'Pending', active: 'Active', locked: 'Locked', disabled: 'Disabled', archived: 'Archived',
    activate: 'Activate', unlock: 'Unlock', disable: 'Disable', archive: 'Archive', revokeSessions: 'Revoke sessions', forcePassword: 'Force password change',
    noEligiblePeople: 'There is no active Person without an account.',
  },
} as const

const actions: Array<{ value: UserAccountAction; label: keyof typeof copy.ar }> = [
  { value: 'activate', label: 'activate' }, { value: 'unlock', label: 'unlock' },
  { value: 'disable', label: 'disable' }, { value: 'archive', label: 'archive' },
  { value: 'revoke-sessions', label: 'revokeSessions' }, { value: 'force-password-change', label: 'forcePassword' },
]

export function IdentityAccounts({ locale, token, onSessionExpired }: { locale: Locale; token: string; onSessionExpired: () => void }) {
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
      if (error instanceof ApiError && error.status === 401) onSessionExpired()
      else if (error instanceof ApiError && error.status === 403) setState('forbidden')
      else setState('error')
    } finally { setLoading(false) }
  }
  useEffect(() => {
    void load()
    // This route reloads only when the authenticated session changes.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [token])
  const claimedPeople = new Set(accounts.filter((account) => account.status !== 'archived').map((account) => account.person_id))
  const eligiblePeople = people.filter((person) => person.status === 'active' && !claimedPeople.has(person.id))

  return <section className="organization-page" aria-labelledby="identity-heading">
    <div className="page-heading page-heading-copy"><div><h1 id="identity-heading">{text.title}</h1><p>{text.intro}</p></div></div>
    {loading && <div className="skeleton-list" aria-label={text.loading}>{[0, 1, 2].map((item) => <div className="skeleton-row" aria-hidden="true" key={item} />)}</div>}
    {!loading && state !== 'ready' && <div className="state-panel" role={state === 'error' ? 'alert' : 'status'}><p>{state === 'forbidden' ? text.forbidden : text.error}</p>{state === 'error' && <button type="button" className="secondary-button" onClick={() => void load()}>{text.retry}</button>}</div>}
    {!loading && state === 'ready' && <div className="organization-layout">
      <section className="organization-section" aria-labelledby="accounts-heading">
        <div className="section-heading"><h2 id="accounts-heading">{text.accounts}</h2><span className="count-badge">{new Intl.NumberFormat(locale === 'ar' ? 'ar-SA' : 'en-GB').format(accounts.length)}</span></div>
        {accounts.length === 0 ? <p>{text.noAccounts}</p> : <AccountTable accounts={accounts} locale={locale} />}
        {eligiblePeople.length > 0 ? <AccountForm locale={locale} token={token} people={eligiblePeople} onCreated={(account) => setAccounts((current) => [...current, account])} onSessionExpired={onSessionExpired} /> : <p className="status-message" role="status">{text.noEligiblePeople}</p>}
      </section>
      {accounts.length > 0 && <section className="organization-section" aria-labelledby="account-action-heading"><h2 id="account-action-heading">{text.action}</h2><AccountActionForm locale={locale} token={token} accounts={accounts} onChanged={(account) => setAccounts((current) => current.map((item) => item.id === account.id ? account : item))} onSessionExpired={onSessionExpired} /></section>}
    </div>}
  </section>
}

function AccountTable({ accounts, locale }: { accounts: UserAccount[]; locale: Locale }) {
  const text = copy[locale]
  return <div className="table-scroll" tabIndex={0} role="region" aria-label={text.accounts}><table><thead><tr><th scope="col">{text.username}</th><th scope="col">{text.person}</th><th scope="col">{text.status}</th><th scope="col">{text.password}</th></tr></thead><tbody>{accounts.map((account) => <tr key={account.id}><td dir="ltr">{account.username}</td><td>{locale === 'en' && account.display_name_en ? account.display_name_en : account.display_name_ar}</td><td><span className="status-badge">{text[account.status]}</span></td><td>{account.must_change_password ? text.required : text.notRequired}</td></tr>)}</tbody></table></div>
}

function AccountForm({ locale, token, people, onCreated, onSessionExpired }: { locale: Locale; token: string; people: Person[]; onCreated: (account: UserAccount) => void; onSessionExpired: () => void }) {
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
    catch (failure) { if (failure instanceof ApiError && failure.status === 401) onSessionExpired(); else { setError(true); window.requestAnimationFrame(() => errorRef.current?.focus()) } }
    finally { setSubmitting(false) }
  }
  return <form className="resource-form" onSubmit={(event) => void submit(event)} noValidate>
    {error && <p className="error-summary" role="alert" tabIndex={-1} ref={errorRef}>{text.validation}</p>}
    <div className="field-row"><Select id="account-person" label={text.person} value={personId} onChange={setPersonId} options={people.map((person) => ({ value: person.id, label: person.display_name_ar }))} /><Field id="account-username" label={text.username} value={username} onChange={setUsername} required invalid={error && !/^[a-zA-Z0-9._-]{3,128}$/.test(username)} /></div>
    <button type="submit" className="primary-button" disabled={submitting}>{submitting ? text.saving : text.addAccount}</button>
  </form>
}

function AccountActionForm({ locale, token, accounts, onChanged, onSessionExpired }: { locale: Locale; token: string; accounts: UserAccount[]; onChanged: (account: UserAccount) => void; onSessionExpired: () => void }) {
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
      if (failure instanceof ApiError && failure.status === 401) onSessionExpired()
      else { setError(failure instanceof ApiError && failure.status === 412 ? 'stale' : 'save'); window.requestAnimationFrame(() => errorRef.current?.focus()) }
    } finally { setSubmitting(false) }
  }
  if (available.length === 0) return null
  return <form className="resource-form" onSubmit={(event) => void submit(event)}>
    {error && <p className="error-summary" role="alert" tabIndex={-1} ref={errorRef}>{error === 'stale' ? text.stale : text.saveError}</p>}
    <div className="field-row"><Select id="action-account" label={text.account} value={accountId} onChange={setAccountId} options={available.map((account) => ({ value: account.id, label: account.username }))} /><Select id="account-action" label={text.action} value={action} onChange={(value) => setAction(value as UserAccountAction)} options={actions.map((item) => ({ value: item.value, label: text[item.label] }))} /><Field id="action-reason" label={text.reason} value={reason} onChange={setReason} /></div>
    <button type="submit" className="primary-button" disabled={submitting}>{submitting ? text.saving : text.execute}</button>
  </form>
}

function Field({ id, label, value, onChange, required = false, invalid = false }: { id: string; label: string; value: string; onChange: (value: string) => void; required?: boolean; invalid?: boolean }) { return <div className="field"><label htmlFor={id}>{label}{required && <span aria-hidden="true"> *</span>}</label><input id={id} value={value} required={required} aria-required={required || undefined} aria-invalid={invalid} onChange={(event) => onChange(event.target.value)} /></div> }
function Select({ id, label, value, onChange, options }: { id: string; label: string; value: string; onChange: (value: string) => void; options: Array<{ value: string; label: string }> }) { return <div className="field"><label htmlFor={id}>{label}</label><select id={id} value={value} onChange={(event) => onChange(event.target.value)}>{options.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}</select></div> }

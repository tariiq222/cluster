import { type FormEvent, useEffect, useRef, useState } from 'react'
import { formattingLocale } from '../../app/copy'
import { useLocale, useToken } from '../../app/session-context'

import { UserPlus, Users } from 'lucide-react'

import {
  ApiError,
  createUserAccount,
  issueIdentityActivation,
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
  Drawer,
  EmptyState,
  Field as UiField,
  InlineError,
  Page,
  PageHeader,
  Panel,
  Select as UiSelect,
  SkeletonList,
  StatusBadge,
} from '../../ui'

type Locale = 'ar' | 'en'

const copy = {
  ar: {
    title: 'حسابات الدخول',
    intro: 'أنشئ حساب دخول لكل موظف وتحكم في وصوله. لا تُعرض كلمات المرور هنا أبداً.',
    loading: 'جارٍ تحميل الحسابات…',
    forbidden: 'لا تملك صلاحية إدارة حسابات الدخول.',
    error: 'تعذر تحميل الحسابات.',
    retry: 'إعادة المحاولة',
    accounts: 'الحسابات',
    noAccounts: 'لا توجد حسابات دخول بعد.',
    noAccountsBody: 'ابدأ بإنشاء حساب لموظف حتى يتمكن من تسجيل الدخول.',
    employee: 'الموظف',
    username: 'اسم الدخول',
    status: 'الحالة',
    manage: 'إدارة',
    manageAccount: 'إدارة الحساب',
    close: 'إغلاق',
    addAccount: 'إضافة حساب',
    addAccountTitle: 'إضافة حساب دخول',
    addAccountIntro: 'اختر الموظف واكتب اسم الدخول. يُنشأ الحساب بانتظار التفعيل، ثم أرسل له رابط التفعيل.',
    create: 'إنشاء الحساب',
    saving: 'جارٍ الحفظ…',
    usernameHint: '٣ أحرف فأكثر: حروف إنجليزية وأرقام و . _ - فقط.',
    validation: 'اختر موظفاً واكتب اسم دخول صحيحاً.',
    saveError: 'لم يُحفظ التغيير. حدّث الصفحة أو راجع حالة الحساب.',
    stale: 'تغيّرت بيانات الحساب من مكان آخر. حدّث الصفحة وأعد المحاولة.',
    pending: 'بانتظار التفعيل',
    active: 'نشط',
    locked: 'مقفل',
    disabled: 'معطل',
    archived: 'مؤرشف',
    pendingHint: 'أُنشئ الحساب ولم يُستخدم بعد. أرسل رابط التفعيل ليضبط الموظف كلمة مروره.',
    activeHint: 'الموظف يستطيع تسجيل الدخول الآن.',
    lockedHint: 'أُقفل الحساب بعد محاولات دخول فاشلة. فك القفل ليعود للعمل.',
    disabledHint: 'أوقفنا الدخول لهذا الحساب. يمكن تفعيله متى لزم.',
    archivedHint: 'حساب مؤرشف، ولا يمكن إجراء أي تغيير عليه.',
    mustChangePassword: 'مطلوب من الموظف تغيير كلمة المرور عند الدخول القادم.',
    noEligiblePeople: 'كل الموظفين النشطين لديهم حساب دخول بالفعل.',
    sendActivation: 'إرسال رابط التفعيل',
    activationIssued: 'أُرسل رابط التفعيل.',
    activationExpiry: 'ينتهي في',
    activationDelivery: 'طريقة الإرسال',
    activationError: 'تعذر إرسال رابط التفعيل.',
    activationDeliveryControlled: 'تسليم منضبط',
    reason: 'سبب الإجراء (اختياري)',
    reasonHint: 'يُحفظ في سجل التدقيق ليُعرف لاحقاً سبب التغيير.',
    activate: 'تفعيل الحساب',
    unlock: 'فك القفل',
    disable: 'تعطيل الحساب',
    archive: 'أرشفة الحساب',
    revokeSessions: 'إنهاء الجلسات المفتوحة',
    forcePassword: 'إلزامه بتغيير كلمة المرور',
    activateHint: 'يعيد للموظف القدرة على تسجيل الدخول.',
    unlockHint: 'يلغي القفل الناتج عن محاولات الدخول الفاشلة.',
    disableHint: 'يمنع الدخول مؤقتاً مع الاحتفاظ بالحساب.',
    archiveHint: 'إغلاق نهائي للحساب. لا يمكن التراجع.',
    revokeSessionsHint: 'يخرج الموظف من كل الأجهزة فوراً.',
    forcePasswordHint: 'يطلب منه كلمة مرور جديدة عند الدخول القادم.',
    noActions: 'لا توجد إجراءات متاحة على هذا الحساب.',
    done: 'تم تنفيذ الإجراء.',
    cancel: 'إلغاء',
  },
  en: {
    title: 'Sign-in accounts',
    intro: 'Create a sign-in account for each employee and control their access. Passwords are never shown here.',
    loading: 'Loading accounts…',
    forbidden: 'You do not have permission to manage sign-in accounts.',
    error: 'Accounts could not be loaded.',
    retry: 'Try again',
    accounts: 'Accounts',
    noAccounts: 'No sign-in accounts yet.',
    noAccountsBody: 'Start by creating an account so an employee can sign in.',
    employee: 'Employee',
    username: 'Username',
    status: 'Status',
    manage: 'Manage',
    manageAccount: 'Manage account',
    close: 'Close',
    addAccount: 'Add account',
    addAccountTitle: 'Add a sign-in account',
    addAccountIntro: 'Pick the employee and choose a username. The account starts as awaiting activation — then send the activation link.',
    create: 'Create account',
    saving: 'Saving…',
    usernameHint: 'At least 3 characters: letters, digits, and . _ - only.',
    validation: 'Select an employee and enter a valid username.',
    saveError: 'The change was not saved. Refresh the page or review the account state.',
    stale: 'This account changed elsewhere. Refresh and try again.',
    pending: 'Awaiting activation',
    active: 'Active',
    locked: 'Locked',
    disabled: 'Disabled',
    archived: 'Archived',
    pendingHint: 'Created but never used. Send the activation link so the employee can set a password.',
    activeHint: 'The employee can sign in right now.',
    lockedHint: 'Locked after failed sign-in attempts. Unlock to restore access.',
    disabledHint: 'Sign-in is switched off for this account. You can activate it again anytime.',
    archivedHint: 'Archived account — no further changes are possible.',
    mustChangePassword: 'The employee must set a new password at next sign-in.',
    noEligiblePeople: 'Every active employee already has a sign-in account.',
    sendActivation: 'Send activation link',
    activationIssued: 'Activation link sent.',
    activationExpiry: 'Expires',
    activationDelivery: 'Delivery',
    activationError: 'The activation link could not be sent.',
    activationDeliveryControlled: 'Controlled delivery',
    reason: 'Reason (optional)',
    reasonHint: 'Stored in the audit trail so the change can be explained later.',
    activate: 'Activate account',
    unlock: 'Unlock',
    disable: 'Disable account',
    archive: 'Archive account',
    revokeSessions: 'End open sessions',
    forcePassword: 'Require a password change',
    activateHint: 'Restores the employee’s ability to sign in.',
    unlockHint: 'Clears the lock caused by failed sign-in attempts.',
    disableHint: 'Blocks sign-in temporarily while keeping the account.',
    archiveHint: 'Closes the account for good. This cannot be undone.',
    revokeSessionsHint: 'Signs the employee out of every device immediately.',
    forcePasswordHint: 'Asks for a new password at their next sign-in.',
    noActions: 'No actions are available on this account.',
    done: 'Action completed.',
    cancel: 'Cancel',
  },
} as const

type CopyKey = keyof typeof copy.ar

/**
 * Which transitions the API accepts per state, in the order an administrator is
 * most likely to want them. Keeping this table here — rather than a flat action
 * dropdown — is what lets a row offer only the handful of moves that make sense
 * for the account in front of the user.
 */
const ACTIONS_BY_STATUS: Record<string, Array<{ action: UserAccountAction; label: CopyKey; hint: CopyKey; danger?: boolean }>> = {
  pending: [
    { action: 'archive', label: 'archive', hint: 'archiveHint', danger: true },
  ],
  active: [
    { action: 'revoke-sessions', label: 'revokeSessions', hint: 'revokeSessionsHint' },
    { action: 'force-password-change', label: 'forcePassword', hint: 'forcePasswordHint' },
    { action: 'disable', label: 'disable', hint: 'disableHint' },
    { action: 'archive', label: 'archive', hint: 'archiveHint', danger: true },
  ],
  locked: [
    { action: 'unlock', label: 'unlock', hint: 'unlockHint' },
    { action: 'disable', label: 'disable', hint: 'disableHint' },
    { action: 'archive', label: 'archive', hint: 'archiveHint', danger: true },
  ],
  disabled: [
    { action: 'activate', label: 'activate', hint: 'activateHint' },
    { action: 'archive', label: 'archive', hint: 'archiveHint', danger: true },
  ],
  archived: [],
}

const STATUS_HINT: Record<string, CopyKey> = {
  pending: 'pendingHint',
  active: 'activeHint',
  locked: 'lockedHint',
  disabled: 'disabledHint',
  archived: 'archivedHint',
}

const USERNAME_PATTERN = /^[a-zA-Z0-9._-]{3,128}$/

function personName(account: UserAccount, locale: Locale): string {
  return locale === 'en' && account.display_name_en ? account.display_name_en : account.display_name_ar
}

export function IdentityAccounts() {
  const locale = useLocale()
  const token = useToken()
  const text = copy[locale]
  const [accounts, setAccounts] = useState<UserAccount[]>([])
  const [people, setPeople] = useState<Person[]>([])
  const [loading, setLoading] = useState(true)
  const [state, setState] = useState<'ready' | 'forbidden' | 'error'>('ready')
  const [addOpen, setAddOpen] = useState(false)
  const [managedId, setManagedId] = useState<string | null>(null)

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

  /**
   * On an ETag conflict (HTTP 412) the server's view of the account is newer than
   * what the drawer holds. Reload the account list and reconcile the drawer to the
   * latest account while keeping the existing localized stale/conflict feedback.
   * Request ordering is preserved: the reload resolves before the drawer reads
   * the reconciled account, so the drawer never renders a stale snapshot.
   */
  async function reloadAfterConflict() {
    try {
      const accountPage = await listUserAccounts(token)
      setAccounts(accountPage.items)
    } catch {
      // Keep the existing localized stale feedback; the list simply stays as-is.
    }
  }

  useEffect(() => {
    void load()
    // This route reloads only when the authenticated session changes.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [token])

  const claimedPeople = new Set(accounts.filter((account) => account.status !== 'archived').map((account) => account.person_id))
  const eligiblePeople = people.filter((person) => person.status === 'active' && !claimedPeople.has(person.id))
  const managed = accounts.find((account) => account.id === managedId) ?? null

  return <Page>
    <PageHeader id="identity-heading" title={text.title} description={text.intro} />
    {loading && <SkeletonList label={text.loading} />}
    {!loading && state === 'forbidden' && <div className="state-panel" role="status"><p>{text.forbidden}</p></div>}
    {!loading && state === 'error' && <InlineError message={text.error} retryLabel={text.retry} onRetry={() => void load()} />}
    {!loading && state === 'ready' && <>
      <Panel
        id="accounts-heading"
        title={text.accounts}
        level={2}
        actions={<>
          <span className="count-badge">{new Intl.NumberFormat(formattingLocale(locale)).format(accounts.length)}</span>
          {eligiblePeople.length > 0 && <Button onClick={() => setAddOpen(true)}>{text.addAccount}</Button>}
        </>}
      >
        {accounts.length === 0
          ? <EmptyState
              icon={<Users />}
              title={text.noAccounts}
              body={eligiblePeople.length > 0 ? text.noAccountsBody : text.noEligiblePeople}
              action={eligiblePeople.length > 0 ? <Button onClick={() => setAddOpen(true)}><UserPlus aria-hidden="true" /> {text.addAccount}</Button> : undefined}
            />
          : <AccountTable accounts={accounts} locale={locale} onManage={setManagedId} />}
        {accounts.length > 0 && eligiblePeople.length === 0 && <p className="status-message" role="status">{text.noEligiblePeople}</p>}
      </Panel>

      <AddAccountDrawer
        open={addOpen}
        locale={locale}
        token={token}
        people={eligiblePeople}
        onClose={() => setAddOpen(false)}
        onCreated={(account) => { setAccounts((current) => [...current, account]); setAddOpen(false) }}
      />

      <ManageAccountDrawer
        account={managed}
        locale={locale}
        token={token}
        onClose={() => setManagedId(null)}
        onChanged={(account) => setAccounts((current) => current.map((item) => item.id === account.id ? account : item))}
        onConflict={reloadAfterConflict}
      />
    </>}
  </Page>
}

function AccountTable({ accounts, locale, onManage }: { accounts: UserAccount[]; locale: Locale; onManage: (id: string) => void }) {
  const text = copy[locale]
  return <div className="table-scroll" tabIndex={0} role="region" aria-label={text.accounts}>
    <table className="data-table">
      <thead><tr>
        <th scope="col">{text.employee}</th>
        <th scope="col">{text.username}</th>
        <th scope="col">{text.status}</th>
        <th scope="col"><span className="visually-hidden">{text.manage}</span></th>
      </tr></thead>
      <tbody>{accounts.map((account) => <tr key={account.id}>
        <td>{personName(account, locale)}</td>
        <td dir="ltr">{account.username}</td>
        <td><StatusBadge className={`status-${account.status}`}>{text[account.status as CopyKey]}</StatusBadge></td>
        <td>
          <div className="table-actions">
            <Button variant="quiet" onClick={() => onManage(account.id)}>
              {text.manage}<span className="visually-hidden"> — {personName(account, locale)}</span>
            </Button>
          </div>
        </td>
      </tr>)}</tbody>
    </table>
  </div>
}

function AddAccountDrawer({ open, locale, token, people, onClose, onCreated }: {
  open: boolean
  locale: Locale
  token: string
  people: Person[]
  onClose: () => void
  onCreated: (account: UserAccount) => void
}) {
  const text = copy[locale]
  const [personId, setPersonId] = useState('')
  const [username, setUsername] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState(false)
  const errorRef = useRef<HTMLParagraphElement>(null)

  useEffect(() => {
    if (!open) return
    setPersonId(people[0]?.id ?? ''); setUsername(''); setError(false)
  }, [open, people])

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const person = people.find((item) => item.id === personId)
    if (!person || !USERNAME_PATTERN.test(username)) { setError(true); window.requestAnimationFrame(() => errorRef.current?.focus()); return }
    setSubmitting(true); setError(false)
    try { onCreated(await createUserAccount(token, { person_id: person.id, person_version: person.person_version, username })) }
    catch { setError(true); window.requestAnimationFrame(() => errorRef.current?.focus()) }
    finally { setSubmitting(false) }
  }

  return <Drawer open={open} onClose={onClose} title={text.addAccountTitle} ariaLabelClose={text.close} dismissable={!submitting}>
    <p className="ui-drawer-intro">{text.addAccountIntro}</p>
    <form className="resource-form" onSubmit={(event) => void submit(event)} noValidate>
      {error && <p className="error-summary" role="alert" tabIndex={-1} ref={errorRef}>{text.validation}</p>}
      <UiField id="account-person" label={text.employee}><UiSelect id="account-person" value={personId} onChange={setPersonId} options={people.map((person) => ({ value: person.id, label: locale === 'en' && person.display_name_en ? person.display_name_en : person.display_name_ar }))} /></UiField>
      <UiField id="account-username" label={text.username} required help={text.usernameHint} error={error && !USERNAME_PATTERN.test(username) ? text.validation : undefined}><input id="account-username" value={username} required aria-required="true" aria-invalid={error && !USERNAME_PATTERN.test(username)} onChange={(event) => setUsername(event.target.value)} /></UiField>
      <div className="table-actions">
        <Button type="submit" disabled={submitting}>{submitting ? text.saving : text.create}</Button>
        <Button variant="secondary" type="button" onClick={onClose} disabled={submitting}>{text.close}</Button>
      </div>
    </form>
  </Drawer>
}

/**
 * One place to see what an account is and everything that can be done to it.
 * The available moves come from the account's own state, so the administrator
 * never has to re-pick the account or guess which transition is legal.
 */
function ManageAccountDrawer({ account, locale, token, onClose, onChanged, onConflict }: {
  account: UserAccount | null
  locale: Locale
  token: string
  onClose: () => void
  onChanged: (account: UserAccount) => void
  onConflict: () => Promise<void>
}) {
  const text = copy[locale]
  const [reason, setReason] = useState('')
  const [pending, setPending] = useState<UserAccountAction | 'activation' | null>(null)
  const [error, setError] = useState<'save' | 'stale' | 'activation' | null>(null)
  const [activation, setActivation] = useState<IdentityActivationResult | null>(null)
  const [done, setDone] = useState(false)
  const errorRef = useRef<HTMLParagraphElement>(null)
  const accountId = account?.id ?? null

  useEffect(() => {
    setReason(''); setPending(null); setError(null); setActivation(null); setDone(false)
  }, [accountId])

  if (!account) return null

  const id = account.id
  const status = account.status
  const available = ACTIONS_BY_STATUS[status] ?? []
  const busy = pending !== null

  function fail(kind: 'save' | 'stale' | 'activation') {
    setError(kind); window.requestAnimationFrame(() => errorRef.current?.focus())
  }

  async function run(action: UserAccountAction) {
    setPending(action); setError(null); setDone(false)
    try {
      onChanged(await transitionUserAccount(token, id, action, reason.trim() || undefined))
      setReason(''); setDone(true)
    } catch (failure) {
      const stale = failure instanceof ApiError && failure.status === 412
        || (typeof failure === 'object' && failure !== null && 'status' in failure && failure.status === 412)
      if (stale) {
        await onConflict()
        fail('stale')
      } else {
        fail('save')
      }
    } finally {
      setPending(null)
    }
  }

  async function sendActivation() {
    setPending('activation'); setError(null); setActivation(null); setDone(false)
    try { setActivation(await issueIdentityActivation(token, id)) }
    catch { fail('activation') }
    finally { setPending(null) }
  }

  return <Drawer open onClose={onClose} title={text.manageAccount} ariaLabelClose={text.close} dismissable={!busy}>
    <dl className="detail-list">
      <div><dt>{text.employee}</dt><dd>{personName(account, locale)}</dd></div>
      <div><dt>{text.username}</dt><dd dir="ltr">{account.username}</dd></div>
      <div><dt>{text.status}</dt><dd><StatusBadge className={`status-${status}`}>{text[status as CopyKey]}</StatusBadge></dd></div>
    </dl>
    <p className="ui-drawer-intro">{text[STATUS_HINT[status] ?? 'activeHint']}</p>
    {account.must_change_password && <p className="status-message" role="status">{text.mustChangePassword}</p>}

    {error && <p className="error-summary" role="alert" tabIndex={-1} ref={errorRef}>
      {error === 'stale' ? text.stale : error === 'activation' ? text.activationError : text.saveError}
    </p>}
    {done && <p className="status-message" role="status">{text.done}</p>}
    {activation && <p className="status-message" role="status">
      {text.activationIssued} {text.activationExpiry}: <span dir="ltr">{activation.expires_at}</span> — {text.activationDelivery}: {text.activationDeliveryControlled}
    </p>}

    {status === 'pending' && <div className="account-action">
      <Button onClick={() => void sendActivation()} disabled={busy}>{pending === 'activation' ? text.saving : text.sendActivation}</Button>
    </div>}

    {available.length > 0 && <>
      <UiField id="account-reason" label={text.reason} help={text.reasonHint}><input id="account-reason" value={reason} onChange={(event) => setReason(event.target.value)} /></UiField>
      {available.map((item) => <div className="account-action" key={item.action}>
        <Button variant={item.danger ? 'secondary' : 'primary'} onClick={() => void run(item.action)} disabled={busy}>
          {pending === item.action ? text.saving : text[item.label]}
        </Button>
        <p className="field-help">{text[item.hint]}</p>
      </div>)}
    </>}

    {available.length === 0 && status !== 'pending' && <p className="status-message" role="status">{text.noActions}</p>}
  </Drawer>
}

type IdentityActivationResult = Awaited<ReturnType<typeof issueIdentityActivation>>

import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react'
import * as generated from '../../../src/api/generated/cluster'
import { ApiError, requestInit, stateFromError, unwrap } from '../../api/http'
import { useLocale, useSessionToken } from '../../app/session-context'
import { usePrincipal } from '../../app/principal-context'
import { formatDate, statusLabel } from '../../i18n'
import {
  Button,
  Drawer,
  EmptyState,
  Field,
  InlineError,
  Page,
  PageHeader,
  Panel,
  Select,
  SkeletonList,
  StatusBadge,
  Tabs,
} from '../../ui'

const copy = {
  ar: {
    title: 'الحسابات والصلاحيات',
    intro: 'أدر حسابات الدخول والأدوار وافحص قرارات الوصول.',
    tabsLabel: 'مساحات الحسابات والصلاحيات',
    tabAccounts: 'الحسابات',
    tabRoles: 'الأدوار',
    tabInspector: 'مفتش القرارات',
    unavailable: 'غير متاح',
    unavailableBody: 'لا تملك الصلاحية المطلوبة لعرض هذا القسم.',
    retry: 'إعادة المحاولة',
    loading: 'جارٍ التحميل…',
    close: 'إغلاق',
    save: 'حفظ',
    cancel: 'إلغاء',
    done: 'تم تنفيذ الإجراء.',
    saveError: 'لم يُحفظ التغيير. أعد المحاولة.',
    stale: 'تغيّرت البيانات من مكان آخر. حدّث القائمة وأعد المحاولة.',
  },
  en: {
    title: 'Accounts & Permissions',
    intro: 'Manage sign-in accounts, roles, and inspect access decisions.',
    tabsLabel: 'Accounts & permissions workspaces',
    tabAccounts: 'Accounts',
    tabRoles: 'Roles',
    tabInspector: 'Decision inspector',
    unavailable: 'Unavailable',
    unavailableBody: 'You do not have the required permission to view this section.',
    retry: 'Retry',
    loading: 'Loading…',
    close: 'Close',
    save: 'Save',
    cancel: 'Cancel',
    done: 'Action completed.',
    saveError: 'The change was not saved. Try again.',
    stale: 'This data changed elsewhere. Refresh the list and try again.',
  },
} as const

const accountCopy = {
  ar: {
    accounts: 'حسابات الدخول',
    noAccounts: 'لا توجد حسابات دخول بعد.',
    noAccountsBody: 'ابدأ بإنشاء حساب لموظف حتى يتمكن من تسجيل الدخول.',
    employee: 'الموظف',
    username: 'اسم الدخول',
    status: 'الحالة',
    manage: 'إدارة',
    manageAccount: 'إدارة الحساب',
    addAccount: 'إضافة حساب',
    addAccountTitle: 'إضافة حساب دخول',
    addAccountIntro: 'اختر الموظف واكتب اسم الدخول. يُنشأ الحساب بانتظار التفعيل.',
    create: 'إنشاء الحساب',
    saving: 'جارٍ الحفظ…',
    usernameHint: '٣ أحرف فأكثر: حروف إنجليزية وأرقام و . _ - فقط.',
    validation: 'اختر موظفاً واكتب اسم دخول صحيحاً.',
    peopleError: 'تعذّر تحميل قائمة الموظفين.',
    reason: 'سبب الإجراء (اختياري)',
    reasonHint: 'يُحفظ في سجل التدقيق ليُعرف لاحقاً سبب التغيير.',
    pending: 'بانتظار التفعيل',
    active: 'نشط',
    locked: 'مقفل',
    disabled: 'معطَّل',
    archived: 'مؤرشف',
    mustChangePassword: 'مطلوب من الموظف تغيير كلمة المرور عند الدخول القادم.',
    activate: 'تفعيل الحساب',
    unlock: 'فك القفل',
    disable: 'تعطيل الحساب',
    archive: 'أرشفة الحساب',
    revokeSessions: 'إنهاء الجلسات المفتوحة',
    forcePasswordChange: 'إلزامه بتغيير كلمة المرور',
    noActions: 'لا توجد إجراءات متاحة على هذا الحساب.',
    activateHint: 'يعيد للموظف القدرة على تسجيل الدخول.',
    unlockHint: 'يلغي القفل الناتج عن محاولات الدخول الفاشلة.',
    disableHint: 'يمنع الدخول مؤقتاً مع الاحتفاظ بالحساب.',
    archiveHint: 'إغلاق نهائي للحساب. لا يمكن التراجع.',
    revokeSessionsHint: 'يخرج الموظف من كل الأجهزة فوراً.',
    forcePasswordChangeHint: 'يطلب منه كلمة مرور جديدة عند الدخول القادم.',
    accountsError: 'تعذّر تحميل الحسابات.',
    saveError: 'لم يُحفظ التغيير. أعد المحاولة.',
    peopleLoading: 'جارٍ تحميل الموظفين…',
  },
  en: {
    accounts: 'Sign-in accounts',
    noAccounts: 'No sign-in accounts yet.',
    noAccountsBody: 'Start by creating an account so an employee can sign in.',
    employee: 'Employee',
    username: 'Username',
    status: 'Status',
    manage: 'Manage',
    manageAccount: 'Manage account',
    addAccount: 'Add account',
    addAccountTitle: 'Add a sign-in account',
    addAccountIntro: 'Pick the employee and choose a username. The account starts as awaiting activation.',
    create: 'Create account',
    saving: 'Saving…',
    usernameHint: 'At least 3 characters: letters, digits, and . _ - only.',
    validation: 'Select an employee and enter a valid username.',
    peopleError: 'Could not load the employee list.',
    reason: 'Reason (optional)',
    reasonHint: 'Stored in the audit trail so the change can be explained later.',
    pending: 'Awaiting activation',
    active: 'Active',
    locked: 'Locked',
    disabled: 'Disabled',
    archived: 'Archived',
    mustChangePassword: 'The employee must set a new password at next sign-in.',
    activate: 'Activate account',
    unlock: 'Unlock',
    disable: 'Disable account',
    archive: 'Archive account',
    revokeSessions: 'End open sessions',
    forcePasswordChange: 'Require a password change',
    noActions: 'No actions are available on this account.',
    activateHint: 'Restores the employee’s ability to sign in.',
    unlockHint: 'Clears the lock caused by failed sign-in attempts.',
    disableHint: 'Blocks sign-in temporarily while keeping the account.',
    archiveHint: 'Closes the account for good. This cannot be undone.',
    revokeSessionsHint: 'Signs the employee out of every device immediately.',
    forcePasswordChangeHint: 'Asks for a new password at their next sign-in.',
    accountsError: 'Accounts could not be loaded.',
    saveError: 'The change was not saved. Try again.',
    peopleLoading: 'Loading employees…',
  },
} as const

const roleCopy = {
  ar: {
    roles: 'الأدوار والصلاحيات',
    code: 'رمز الدور',
    name: 'اسم الدور',
    capabilities: 'الصلاحيات',
    create: 'إنشاء دور',
    save: 'حفظ التغييرات',
    edit: 'تعديل',
    archive: 'أرشفة',
    clone: 'نسخ كدور مخصص',
    revoke: 'إزالة',
    cancel: 'إلغاء',
    empty: 'لا توجد أدوار لعرضها.',
    noCatalog: 'لا تتوفر قائمة الصلاحيات (تحتاج authorization.capability.read).',
    roleError: 'تعذّر حفظ الدور.',
    rolesError: 'تعذّر تحميل الأدوار.',
    countCapabilities: 'صلاحية',
    systemRole: 'نظامي',
    customRole: 'مخصص',
  },
  en: {
    roles: 'Roles & Permissions',
    code: 'Role code',
    name: 'Role name',
    capabilities: 'Capabilities',
    create: 'Create role',
    save: 'Save changes',
    edit: 'Edit',
    archive: 'Archive',
    clone: 'Clone as custom role',
    revoke: 'Remove',
    cancel: 'Cancel',
    empty: 'No roles to display.',
    noCatalog: 'Capability catalog unavailable (needs authorization.capability.read).',
    roleError: 'Could not save the role.',
    rolesError: 'Roles could not be loaded.',
    countCapabilities: 'capabilities',
    systemRole: 'System',
    customRole: 'Custom',
  },
} as const

const inspectorCopy = {
  ar: {
    inspector: 'مفتش قرارات الوصول',
    inspectorIntro: 'أدخل معرّف القرار لعرض شرحه الكامل.',
    decisionId: 'معرّف القرار',
    inspect: 'فحص',
    inspecting: 'جارٍ الفحص…',
    idle: 'أدخل معرّف قرار لعرض شرحه.',
    allow: 'مسموح',
    deny: 'مرفوض',
    action: 'الإجراء',
    resourceType: 'نوع المورد',
    resourceId: 'معرّف المورد',
    policyVersion: 'نسخة السياسة',
    factsVersion: 'نسخة الحقائق',
    evaluatedAt: 'وقت التقييم',
    decisionIdLabel: 'معرّف القرار',
    plainLanguage: 'الشرح المبسّط',
    reasonCodes: 'أكواد السبب',
    obligations: 'الالتزامات',
    assignments: 'التعيينات',
    policies: 'مراجع السياسات',
    notFound: 'لم يُعثر على القرار.',
    error: 'تعذّر فحص القرار.',
  },
  en: {
    inspector: 'Access decision inspector',
    inspectorIntro: 'Enter a decision ID to see its full explanation.',
    decisionId: 'Decision ID',
    inspect: 'Inspect',
    inspecting: 'Inspecting…',
    idle: 'Enter a decision ID to see its explanation.',
    allow: 'Allow',
    deny: 'Deny',
    action: 'Action',
    resourceType: 'Resource type',
    resourceId: 'Resource ID',
    policyVersion: 'Policy version',
    factsVersion: 'Facts version',
    evaluatedAt: 'Evaluated at',
    decisionIdLabel: 'Decision ID',
    plainLanguage: 'Plain-language explanation',
    reasonCodes: 'Reason codes',
    obligations: 'Obligations',
    assignments: 'Assignments',
    policies: 'Policy references',
    notFound: 'Decision not found.',
    error: 'Could not inspect the decision.',
  },
} as const

type TabKey = 'accounts' | 'roles' | 'inspector'

export function AccountsPermissionsScreen() {
  const locale = useLocale()
  const principal = usePrincipal()
  const text = copy[locale]
  const [tab, setTab] = useState<TabKey>('accounts')
  const capabilities = principal.capabilities ?? []
  const canAccounts = capabilities.includes('identity.account.read')
  const canRoles = capabilities.includes('authorization.role.read')
  const canInspector = capabilities.includes('authorization.decision.read')

  const tabs = [
    { key: 'accounts', label: text.tabAccounts, active: tab === 'accounts', onClick: () => setTab('accounts') },
    { key: 'roles', label: text.tabRoles, active: tab === 'roles', onClick: () => setTab('roles') },
    { key: 'inspector', label: text.tabInspector, active: tab === 'inspector', onClick: () => setTab('inspector') },
  ]

  return (
    <Page>
      <PageHeader id="accounts-permissions-heading" title={text.title} description={text.intro} />
      <Tabs tabs={tabs} label={text.tabsLabel} />
      {tab === 'accounts' &&
        (canAccounts ? (
          <AccountsTab />
        ) : (
          <UnavailableTab title={text.unavailable} body={text.unavailableBody} />
        ))}
      {tab === 'roles' &&
        (canRoles ? (
          <RolesTab />
        ) : (
          <UnavailableTab title={text.unavailable} body={text.unavailableBody} />
        ))}
      {tab === 'inspector' &&
        (canInspector ? (
          <InspectorTab />
        ) : (
          <UnavailableTab title={text.unavailable} body={text.unavailableBody} />
        ))}
    </Page>
  )
}

function UnavailableTab({ title, body }: { title: string; body: string }) {
  return <EmptyState title={title} body={body} />
}

/* ------------------------------------------------------------------ */
/* Accounts tab                                                        */
/* ------------------------------------------------------------------ */

const USERNAME_PATTERN = /^[a-zA-Z0-9._-]{3,128}$/

type AccountAction =
  | 'activate'
  | 'unlock'
  | 'disable'
  | 'archive'
  | 'revoke-sessions'
  | 'force-password-change'

const ACTIONS_BY_STATUS: Record<string, Array<{ action: AccountAction; label: keyof typeof accountCopy.ar; hint: keyof typeof accountCopy.ar; danger?: boolean }>> = {
  pending: [{ action: 'archive', label: 'archive', hint: 'archiveHint', danger: true }],
  active: [
    { action: 'revoke-sessions', label: 'revokeSessions', hint: 'revokeSessionsHint' },
    { action: 'force-password-change', label: 'forcePasswordChange', hint: 'forcePasswordChangeHint' },
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

function accountStatusLabel(status: string, locale: 'ar' | 'en'): string {
  const key = status as keyof typeof accountCopy.ar
  return accountCopy[locale][key] ?? status
}

function personName(account: generated.UserAccount, locale: 'ar' | 'en'): string {
  return locale === 'en' && account.display_name_en ? account.display_name_en : account.display_name_ar
}

function AccountsTab() {
  const locale = useLocale()
  const csrfToken = useSessionToken()
  const text = accountCopy[locale]
  const [accounts, setAccounts] = useState<generated.UserAccount[]>([])
  const [loadState, setLoadState] = useState<'loading' | 'ready' | 'forbidden' | 'error'>('loading')
  const [addOpen, setAddOpen] = useState(false)
  const [managedId, setManagedId] = useState<string | null>(null)
  const activeRef = useRef(true)
  const loadRequestRef = useRef(0)

  useEffect(() => {
    activeRef.current = true
    return () => {
      activeRef.current = false
      loadRequestRef.current += 1
    }
  }, [])

  const load = useCallback(async () => {
    const epoch = ++loadRequestRef.current
    setLoadState('loading')
    try {
      const collection = unwrap<generated.UserAccountCollection>(
        await generated.listUserAccounts({ limit: 100 }, requestInit(csrfToken)),
      )
      if (!activeRef.current || epoch !== loadRequestRef.current) return
      setAccounts(collection.items)
      setLoadState('ready')
    } catch (error) {
      if (!activeRef.current || epoch !== loadRequestRef.current) return
      setAccounts([])
      setLoadState(stateFromError(error) === 'forbidden' ? 'forbidden' : 'error')
    }
  }, [csrfToken])

  useEffect(() => {
    void load()
  }, [load])

  const managed = accounts.find((account) => account.id === managedId) ?? null

  if (loadState === 'loading') return <SkeletonList rows={4} />
  if (loadState === 'forbidden') return <EmptyState title={copy[locale].unavailable} body={copy[locale].unavailableBody} />
  if (loadState === 'error')
    return <InlineError message={text.accountsError} retryLabel={copy[locale].retry} onRetry={() => void load()} />

  return (
    <Panel
      id="accounts-tab-panel"
      title={text.accounts}
      level={2}
      actions={
        <Button type="button" onClick={() => setAddOpen(true)}>
          {text.addAccount}
        </Button>
      }
    >
      {accounts.length === 0 ? (
        <EmptyState title={text.noAccounts} body={text.noAccountsBody} />
      ) : (
        <ul className="screen-list">
          {accounts.map((account) => (
            <li key={account.id} className="screen-list__row">
              <span className="screen-list__row-title">{personName(account, locale)}</span>
              <span className="screen-list__row-meta" dir="ltr">
                {account.username}
              </span>
              <span className="screen-list__row-meta">
                <StatusBadge variant={account.status === 'active' ? 'success' : account.status === 'archived' ? 'neutral' : 'warning'}>
                  {accountStatusLabel(account.status, locale)}
                </StatusBadge>
              </span>
              <span className="screen-list__row-actions">
                <Button variant="quiet" type="button" onClick={() => setManagedId(account.id)}>
                  {text.manage}
                </Button>
              </span>
            </li>
          ))}
        </ul>
      )}

      <CreateAccountDrawer
        open={addOpen}
        onClose={() => setAddOpen(false)}
        onCreated={(account) => {
          setAccounts((current) => [...current, account])
          setAddOpen(false)
        }}
      />

      <ManageAccountDrawer
        account={managed}
        onClose={() => setManagedId(null)}
        onChanged={(account) => setAccounts((current) => current.map((item) => (item.id === account.id ? account : item)))}
        onConflict={load}
      />
    </Panel>
  )
}

function CreateAccountDrawer({
  open,
  onClose,
  onCreated,
}: {
  open: boolean
  onClose: () => void
  onCreated: (account: generated.UserAccount) => void
}) {
  const locale = useLocale()
  const csrfToken = useSessionToken()
  const text = accountCopy[locale]
  const [people, setPeople] = useState<generated.Person[] | null>(null)
  const [peopleError, setPeopleError] = useState(false)
  const [personId, setPersonId] = useState('')
  const [username, setUsername] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState(false)
  const errorRef = useRef<HTMLParagraphElement>(null)

  useEffect(() => {
    if (!open) return
    setUsername('')
    setError(false)
    setPeopleError(false)
    setPeople(null)
    let cancelled = false
    ;(async () => {
      try {
        const collection = unwrap<{ items: generated.Person[] }>(
          await generated.listPeople({ limit: 100 }, requestInit(csrfToken)),
        )
        if (cancelled) return
        setPeople(collection.items)
        setPersonId(collection.items[0]?.id ?? '')
      } catch {
        if (cancelled) return
        setPeopleError(true)
      }
    })()
    return () => {
      cancelled = true
    }
  }, [open, csrfToken])

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const person = people?.find((item) => item.id === personId)
    if (!person || !USERNAME_PATTERN.test(username)) {
      setError(true)
      window.requestAnimationFrame(() => errorRef.current?.focus())
      return
    }
    setSubmitting(true)
    setError(false)
    try {
      const account = unwrap<generated.UserAccount>(
        await generated.createUserAccount(
          { person_id: person.id, person_version: person.person_version, username },
          requestInit(csrfToken, { command: true }),
        ),
      )
      onCreated(account)
    } catch {
      setError(true)
      window.requestAnimationFrame(() => errorRef.current?.focus())
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Drawer open={open} onClose={onClose} title={text.addAccountTitle}>
      <p className="field__help">{text.addAccountIntro}</p>
      {error && (
        <p className="error-summary" role="alert" tabIndex={-1} ref={errorRef}>
          {text.validation}
        </p>
      )}
      <form className="resource-form" onSubmit={(event) => void submit(event)} noValidate>
        {peopleError ? (
          <p className="error-summary" role="alert">
            {text.peopleError}
          </p>
        ) : people === null ? (
          <p className="field__help" role="status">
            {text.peopleLoading}
          </p>
        ) : (
          <>
            <Field id="account-person" label={text.employee} required>
              <Select
                id="account-person"
                value={personId}
                onChange={setPersonId}
                options={people.map((person) => ({
                  value: person.id,
                  label: locale === 'en' && person.display_name_en ? person.display_name_en : person.display_name_ar,
                }))}
              />
            </Field>
            <Field
              id="account-username"
              label={text.username}
              required
              help={text.usernameHint}
              error={error && !USERNAME_PATTERN.test(username) ? text.validation : null}
            >
              <input
                id="account-username"
                value={username}
                required
                aria-required="true"
                aria-invalid={error && !USERNAME_PATTERN.test(username)}
                onChange={(event) => setUsername(event.target.value)}
              />
            </Field>
            <div className="form-actions">
              <Button type="submit" disabled={submitting}>
                {submitting ? text.saving : text.create}
              </Button>
              <Button variant="secondary" type="button" onClick={onClose} disabled={submitting}>
                {copy[locale].cancel}
              </Button>
            </div>
          </>
        )}
      </form>
    </Drawer>
  )
}

function ManageAccountDrawer({
  account,
  onClose,
  onChanged,
  onConflict,
}: {
  account: generated.UserAccount | null
  onClose: () => void
  onChanged: (account: generated.UserAccount) => void
  onConflict: () => Promise<void>
}) {
  const locale = useLocale()
  const csrfToken = useSessionToken()
  const text = accountCopy[locale]
  const [reason, setReason] = useState('')
  const [pending, setPending] = useState<AccountAction | null>(null)
  const [error, setError] = useState<'save' | 'stale' | null>(null)
  const [done, setDone] = useState(false)
  const errorRef = useRef<HTMLParagraphElement>(null)
  const accountId = account?.id ?? null

  useEffect(() => {
    setReason('')
    setPending(null)
    setError(null)
    setDone(false)
  }, [accountId])

  if (!account) return null

  const currentAccount = account
  const available = ACTIONS_BY_STATUS[currentAccount.status] ?? []
  const busy = pending !== null

  async function run(action: AccountAction) {
    setPending(action)
    setError(null)
    setDone(false)
    try {
      const fresh = unwrap<generated.UserAccount & { lock_version?: number }>(
        await generated.getUserAccount(currentAccount.id, requestInit(csrfToken)),
      )
      const updated = unwrap<generated.UserAccount>(
        await generated.transitionUserAccount(
          currentAccount.id,
          action,
          reason.trim() ? { reason: reason.trim() } : undefined,
          requestInit(csrfToken, { command: true, lockVersion: fresh.lock_version }),
        ),
      )
      onChanged(updated)
      setReason('')
      setDone(true)
    } catch (failure) {
      if (failure instanceof ApiError && failure.status === 412) {
        setPending(null)
        await onConflict()
        setError('stale')
      } else {
        setError('save')
      }
      window.requestAnimationFrame(() => errorRef.current?.focus())
    } finally {
      setPending(null)
    }
  }

  return (
    <Drawer open onClose={onClose} title={text.manageAccount}>
      <dl className="detail-list">
        <div>
          <dt>{text.employee}</dt>
          <dd>{personName(account, locale)}</dd>
        </div>
        <div>
          <dt>{text.username}</dt>
          <dd dir="ltr">{account.username}</dd>
        </div>
        <div>
          <dt>{text.status}</dt>
          <dd>
            <StatusBadge variant={account.status === 'active' ? 'success' : account.status === 'archived' ? 'neutral' : 'warning'}>
              {accountStatusLabel(account.status, locale)}
            </StatusBadge>
          </dd>
        </div>
      </dl>
      {account.must_change_password && (
        <p className="status-message" role="status">
          {text.mustChangePassword}
        </p>
      )}
      {error && (
        <p className="error-summary" role="alert" tabIndex={-1} ref={errorRef}>
          {error === 'stale' ? copy[locale].stale : text.saveError}
        </p>
      )}
      {done && (
        <p className="status-message" role="status">
          {copy[locale].done}
        </p>
      )}
      {available.length === 0 ? (
        <p className="status-message" role="status">
          {text.noActions}
        </p>
      ) : (
        <>
          <Field id="account-reason" label={text.reason} help={text.reasonHint}>
            <input id="account-reason" value={reason} onChange={(event) => setReason(event.target.value)} />
          </Field>
          {available.map((item) => (
            <div className="form-actions" key={item.action}>
              <Button variant={item.danger ? 'secondary' : 'primary'} type="button" onClick={() => void run(item.action)} disabled={busy}>
                {pending === item.action ? text.saving : text[item.label]}
              </Button>
              <p className="field__help">{text[item.hint]}</p>
            </div>
          ))}
        </>
      )}
    </Drawer>
  )
}

/* ------------------------------------------------------------------ */
/* Roles tab                                                           */
/* ------------------------------------------------------------------ */

type CapabilityCatalogItem = generated.AuthorizationCapability & { id: string }

interface RoleDraft {
  code: string
  name: string
  capabilityCodes: string[]
}

const EMPTY_DRAFT: RoleDraft = { code: '', name: '', capabilityCodes: [] }

function roleDisplayName(role: generated.AuthorizationRole, locale: 'ar' | 'en'): string {
  return locale === 'ar' ? (role.name_ar ?? role.name_en ?? role.code) : (role.name_en ?? role.name_ar ?? role.code)
}

function RolesTab() {
  const locale = useLocale()
  const csrfToken = useSessionToken()
  const principal = usePrincipal()
  const text = roleCopy[locale]
  const [roles, setRoles] = useState<generated.AuthorizationRole[]>([])
  const [catalog, setCatalog] = useState<CapabilityCatalogItem[]>([])
  const [loadState, setLoadState] = useState<'loading' | 'ready' | 'error'>('loading')
  const [draft, setDraft] = useState<RoleDraft>(EMPTY_DRAFT)
  const [editing, setEditing] = useState<generated.AuthorizationRole | null>(null)
  const [pending, setPending] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const activeRef = useRef(true)
  const loadRequestRef = useRef(0)

  useEffect(() => {
    activeRef.current = true
    return () => {
      activeRef.current = false
      loadRequestRef.current += 1
    }
  }, [])

  const canReadCapabilities = (principal.capabilities ?? []).includes('authorization.capability.read')

  const load = useCallback(async () => {
    const epoch = ++loadRequestRef.current
    setLoadState('loading')
    try {
      const [rolePage, capabilityPage] = await Promise.all([
        unwrap<{ items: generated.AuthorizationRole[] }>(
          await generated.listAuthorizationAdminResources('roles', { limit: 100 }, requestInit(csrfToken)),
        ),
        canReadCapabilities
          ? unwrap<{ items: CapabilityCatalogItem[] }>(
              await generated.listAuthorizationAdminResources('capabilities', { limit: 100 }, requestInit(csrfToken)),
            )
          : Promise.resolve(null),
      ])
      if (!activeRef.current || epoch !== loadRequestRef.current) return
      setRoles(rolePage.items)
      setCatalog(capabilityPage?.items ?? [])
      setLoadState('ready')
    } catch (caught) {
      if (!activeRef.current || epoch !== loadRequestRef.current) return
      setRoles([])
      setCatalog([])
      setLoadState('error')
      setError(caught instanceof ApiError ? caught.message : text.rolesError)
    }
  }, [canReadCapabilities, csrfToken, text.rolesError])

  useEffect(() => {
    void load()
  }, [load])

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!draft.code.trim() || !draft.name.trim()) return
    setPending(true)
    setError(null)
    try {
      if (editing) {
        const updated = unwrap<generated.AuthorizationRole>(
          await generated.updateAuthorizationAdminResource(
            'roles',
            editing.id,
            { name: draft.name.trim(), capability_codes: draft.capabilityCodes },
            requestInit(csrfToken, { mutation: true, lockVersion: editing.lock_version }),
          ),
        )
        setRoles((current) => current.map((role) => (role.id === updated.id ? updated : role)))
      } else {
        const created = unwrap<generated.AuthorizationRole>(
          await generated.createAuthorizationAdminResource(
            'roles',
            {
              resource_type: 'role',
              code: draft.code.trim(),
              name: draft.name.trim(),
              capability_codes: draft.capabilityCodes,
            },
            requestInit(csrfToken, { command: true, idempotency: 'authorization-role' }),
          ),
        )
        setRoles((current) => [...current, created])
      }
      setDraft(EMPTY_DRAFT)
      setEditing(null)
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : text.roleError)
    } finally {
      setPending(false)
    }
  }

  async function archive(role: generated.AuthorizationRole) {
    setPending(true)
    setError(null)
    try {
      const updated = unwrap<generated.AuthorizationRole>(
        await generated.updateAuthorizationAdminResource(
          'roles',
          role.id,
          { status: 'archived' },
          requestInit(csrfToken, { mutation: true, lockVersion: role.lock_version }),
        ),
      )
      setRoles((current) => current.map((item) => (item.id === updated.id ? updated : item)))
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : text.roleError)
    } finally {
      setPending(false)
    }
  }

  async function clone(role: generated.AuthorizationRole) {
    setPending(true)
    setError(null)
    try {
      const created = unwrap<generated.AuthorizationRole>(
        await generated.transitionAuthorizationAdminResource(
          'roles',
          role.id,
          'clone',
          undefined,
          requestInit(csrfToken, { command: true, idempotency: 'authorization-role-clone', lockVersion: role.lock_version }),
        ),
      )
      setRoles((current) => [...current, created])
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : text.roleError)
    } finally {
      setPending(false)
    }
  }

  async function revokeCapability(role: generated.AuthorizationRole, capability: CapabilityCatalogItem) {
    setPending(true)
    setError(null)
    try {
      const updated = unwrap<generated.AuthorizationRole>(
        await generated.transitionAuthorizationAdminResource(
          'role-capabilities',
          `${role.id}:${capability.id}`,
          'revoke',
          undefined,
          requestInit(csrfToken, { command: true, idempotency: 'authorization-role-capability-revoke', lockVersion: role.lock_version }),
        ),
      )
      setRoles((current) => current.map((item) => (item.id === updated.id ? updated : item)))
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : text.roleError)
    } finally {
      setPending(false)
    }
  }

  function toggleCapability(code: string) {
    setDraft((current) => ({
      ...current,
      capabilityCodes: current.capabilityCodes.includes(code)
        ? current.capabilityCodes.filter((item) => item !== code)
        : [...current.capabilityCodes, code],
    }))
  }

  function beginEdit(role: generated.AuthorizationRole) {
    setEditing(role)
    setDraft({
      code: role.code,
      name: roleDisplayName(role, locale),
      capabilityCodes: role.capability_codes ?? [],
    })
    setError(null)
  }

  if (loadState === 'loading') return <SkeletonList rows={4} />
  if (loadState === 'error')
    return <InlineError message={error ?? text.rolesError} retryLabel={copy[locale].retry} onRetry={() => void load()} />

  return (
    <Panel id="roles-tab-panel" title={text.roles} level={2}>
      {canReadCapabilities && (
        <form className="resource-form" onSubmit={(event) => void submit(event)} noValidate>
          <Field id="role-code" label={text.code} required>
            <input
              id="role-code"
              value={draft.code}
              required
              aria-required="true"
              disabled={Boolean(editing) || pending}
              onChange={(event) => setDraft((current) => ({ ...current, code: event.target.value }))}
            />
          </Field>
          <Field id="role-name" label={text.name} required>
            <input
              id="role-name"
              value={draft.name}
              required
              aria-required="true"
              disabled={pending}
              onChange={(event) => setDraft((current) => ({ ...current, name: event.target.value }))}
            />
          </Field>
          {catalog.length > 0 ? (
            <fieldset>
              <legend className="field__label">{text.capabilities}</legend>
              <div className="badge-row">
                {catalog.map((capability) => (
                  <label key={capability.id} className="capability-toggle">
                    <input
                      type="checkbox"
                      checked={draft.capabilityCodes.includes(capability.code)}
                      disabled={pending}
                      onChange={() => toggleCapability(capability.code)}
                    />
                    <span dir="ltr">{capability.code}</span>
                  </label>
                ))}
              </div>
            </fieldset>
          ) : (
            <p className="field__help">{text.noCatalog}</p>
          )}
          <div className="form-actions">
            <Button type="submit" disabled={pending}>
              {pending ? copy[locale].loading : editing ? text.save : text.create}
            </Button>
            {editing && (
              <Button
                variant="secondary"
                type="button"
                disabled={pending}
                onClick={() => {
                  setEditing(null)
                  setDraft(EMPTY_DRAFT)
                  setError(null)
                }}
              >
                {text.cancel}
              </Button>
            )}
          </div>
        </form>
      )}
      {error && (
        <p className="error-summary" role="alert">
          {error}
        </p>
      )}
      {roles.length === 0 ? (
        <EmptyState title={text.empty} />
      ) : (
        <ul className="screen-list">
          {roles.map((role) => {
            const capabilityChips =
              catalog.length > 0 && !role.is_system_role
                ? (role.capability_codes ?? [])
                    .map((code) => catalog.find((item) => item.code === code))
                    .filter((item): item is CapabilityCatalogItem => item !== undefined)
                : []
            return (
              <li key={role.id} className="screen-list__row">
                <span className="screen-list__row-title">{roleDisplayName(role, locale)}</span>
                <span className="screen-list__row-meta" dir="ltr">
                  {role.code}
                </span>
                <span className="screen-list__row-meta">
                  <StatusBadge variant={role.is_system_role ? 'info' : 'neutral'}>
                    {role.is_system_role ? text.systemRole : text.customRole}
                  </StatusBadge>
                  <StatusBadge variant={role.status === 'active' ? 'success' : role.status === 'archived' ? 'neutral' : 'warning'}>
                    {statusLabel(role.status, locale)}
                  </StatusBadge>
                  <span>
                    {(role.capability_codes ?? []).length} {text.countCapabilities}
                  </span>
                </span>
                {capabilityChips.length > 0 && (
                  <span className="screen-list__row-meta">
                    <span className="badge-row">
                      {capabilityChips.map((capability) => (
                        <span key={capability.id} className="status-badge status-badge--info">
                          <span dir="ltr">{capability.code}</span>
                          <button
                            type="button"
                            className="button button--quiet capability-revoke"
                            aria-label={`${text.revoke} ${capability.code}`}
                            disabled={pending}
                            onClick={() => void revokeCapability(role, capability)}
                          >
                            ✕
                          </button>
                        </span>
                      ))}
                    </span>
                  </span>
                )}
                <span className="screen-list__row-actions">
                  {!role.is_system_role && (
                    <Button variant="quiet" type="button" disabled={pending} onClick={() => beginEdit(role)}>
                      {text.edit}
                    </Button>
                  )}
                  {!role.is_system_role && (
                    <Button variant="quiet" type="button" disabled={pending} onClick={() => void archive(role)}>
                      {text.archive}
                    </Button>
                  )}
                  {role.is_system_role && (
                    <Button variant="secondary" type="button" disabled={pending} onClick={() => void clone(role)}>
                      {text.clone}
                    </Button>
                  )}
                </span>
              </li>
            )
          })}
        </ul>
      )}
    </Panel>
  )
}

/* ------------------------------------------------------------------ */
/* Decision inspector tab                                              */
/* ------------------------------------------------------------------ */

function InspectorTab() {
  const locale = useLocale()
  const csrfToken = useSessionToken()
  const text = inspectorCopy[locale]
  const [decisionId, setDecisionId] = useState('')
  const [phase, setPhase] = useState<'idle' | 'loading' | 'ready' | 'not-found' | 'error'>('idle')
  const [decision, setDecision] = useState<generated.AccessDecisionSchema | null>(null)

  async function inspect(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!decisionId.trim()) return
    setPhase('loading')
    try {
      const result = unwrap<generated.AccessDecisionSchema>(
        await generated.explainAccessDecision(decisionId.trim(), requestInit(csrfToken)),
      )
      setDecision(result)
      setPhase('ready')
    } catch (error) {
      setDecision(null)
      setPhase(stateFromError(error) === 'not-found' ? 'not-found' : 'error')
    }
  }

  return (
    <Panel id="inspector-tab-panel" title={text.inspector} level={2}>
      <p className="field__help">{text.inspectorIntro}</p>
      <form className="inline-form" onSubmit={(event) => void inspect(event)}>
        <Field id="decision-id" label={text.decisionId}>
          <input
            id="decision-id"
            value={decisionId}
            required
            aria-required="true"
            dir="ltr"
            disabled={phase === 'loading'}
            onChange={(event) => setDecisionId(event.target.value)}
          />
        </Field>
        <Button type="submit" disabled={phase === 'loading'}>
          {phase === 'loading' ? text.inspecting : text.inspect}
        </Button>
      </form>
      {phase === 'idle' && <p className="status-message">{text.idle}</p>}
      {phase === 'not-found' && (
        <p className="status-message status-message--error" role="alert">
          {text.notFound}
        </p>
      )}
      {phase === 'error' && (
        <p className="status-message status-message--error" role="alert">
          {text.error}
        </p>
      )}
      {phase === 'ready' && decision && (
        <div className="detail-grid">
          <StatusBadge variant={decision.decision === 'allow' ? 'success' : 'danger'}>
            {decision.decision === 'allow' ? text.allow : text.deny}
          </StatusBadge>
          {decision.applies_in_plain_language && <p className="field__help">{decision.applies_in_plain_language}</p>}
          <dl className="detail-list">
            <div>
              <dt>{text.action}</dt>
              <dd>{decision.action}</dd>
            </div>
            <div>
              <dt>{text.resourceType}</dt>
              <dd>{decision.resource_type}</dd>
            </div>
            {decision.resource_id && (
              <div>
                <dt>{text.resourceId}</dt>
                <dd dir="ltr">{decision.resource_id}</dd>
              </div>
            )}
            <div>
              <dt>{text.decisionIdLabel}</dt>
              <dd dir="ltr">{decision.decision_id}</dd>
            </div>
            <div>
              <dt>{text.policyVersion}</dt>
              <dd>{decision.policy_version}</dd>
            </div>
            <div>
              <dt>{text.factsVersion}</dt>
              <dd>{decision.facts_version}</dd>
            </div>
            <div>
              <dt>{text.evaluatedAt}</dt>
              <dd>{formatDate(decision.evaluated_at, locale)}</dd>
            </div>
          </dl>
          {decision.reason_codes.length > 0 && (
            <>
              <h3 className="panel__heading">{text.reasonCodes}</h3>
              <div className="badge-row">
                {decision.reason_codes.map((code) => (
                  <StatusBadge key={code} variant="neutral">
                    <span dir="ltr">{code}</span>
                  </StatusBadge>
                ))}
              </div>
            </>
          )}
          {decision.obligations && decision.obligations.length > 0 && (
            <>
              <h3 className="panel__heading">{text.obligations}</h3>
              <div className="badge-row">
                {decision.obligations.map((obligation) => (
                  <StatusBadge key={obligation} variant="warning">
                    {statusLabel(obligation, locale)}
                  </StatusBadge>
                ))}
              </div>
            </>
          )}
          {decision.assignment_summaries && decision.assignment_summaries.length > 0 && (
            <>
              <h3 className="panel__heading">{text.assignments}</h3>
              <ul className="screen-list">
                {decision.assignment_summaries.map((summary, index) => (
                  <li key={`${summary.role_code}-${index}`} className="screen-list__row">
                    <span className="screen-list__row-title" dir="ltr">
                      {summary.role_code}
                    </span>
                    <span className="screen-list__row-meta">
                      <StatusBadge variant={summary.effective_status === 'active' ? 'success' : 'neutral'}>
                        {statusLabel(summary.effective_status, locale)}
                      </StatusBadge>
                      {summary.scope_type && <span>{summary.scope_type}</span>}
                    </span>
                  </li>
                ))}
              </ul>
            </>
          )}
          {decision.policy_references && decision.policy_references.length > 0 && (
            <>
              <h3 className="panel__heading">{text.policies}</h3>
              <ul className="screen-list">
                {decision.policy_references.map((reference, index) => (
                  <li key={`${reference.policy_code}-${index}`} className="screen-list__row">
                    <span className="screen-list__row-title" dir="ltr">
                      {reference.policy_code}
                    </span>
                    <span className="screen-list__row-meta" dir="ltr">
                      {reference.policy_version}
                    </span>
                    {reference.excerpt && <span className="screen-list__row-meta">{reference.excerpt}</span>}
                  </li>
                ))}
              </ul>
            </>
          )}
        </div>
      )}
    </Panel>
  )
}

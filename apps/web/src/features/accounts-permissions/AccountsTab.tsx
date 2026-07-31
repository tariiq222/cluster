import type { Locale } from '../../app/copy'
import { IdentityAccounts } from '../identity/IdentityAccounts'
import { canMutateAdminResource } from './canMutateAdminResource'

export type AccountsTabProps = { locale: Locale; capabilities: readonly string[] }
const COPY = {
  ar: 'استخدم تبويب إسنادات الأدوار لإسناد أدوار إضافية أو إلغائها.',
  en: 'Use the role assignments tab to assign or revoke additional roles.',
} as const satisfies Record<Locale, string>

export function AccountsTab({ locale, capabilities }: AccountsTabProps) {
  const canAssign = canMutateAdminResource('accounts', 'assign', capabilities)
  return <div className="accounts-tab-panel"><IdentityAccounts />{canAssign ? <p className="accounts-tab-hint" role="note">{COPY[locale]}</p> : null}</div>
}

import { ShieldCheck } from 'lucide-react'
import type { Locale } from '../../app/copy'
import { directionForLocale } from '../../app/copy'
import { EmptyState } from '../../ui'

const copy = {
  ar: 'تُدار الأدوار والصلاحيات الآن في مساحة الحسابات والصلاحيات.',
  en: 'Roles and permissions are now managed in the Accounts & Permissions workspace.',
} as const satisfies Record<Locale, string>

/**
 * Compatibility entry point for saved roles/capabilities links. Active routing
 * mounts the actionable roles-and-permissions tab in Accounts & Permissions.
 */
export function RolesCapabilitiesWorkspace({ locale }: { locale: Locale; capabilities: readonly string[] | null }) {
  return (
    <div dir={directionForLocale(locale)}>
      <EmptyState icon={<ShieldCheck aria-hidden="true" />} title={copy[locale]} />
    </div>
  )
}

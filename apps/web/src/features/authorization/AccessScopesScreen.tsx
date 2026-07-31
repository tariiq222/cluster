import { Network } from 'lucide-react'
import type { Locale } from '../../app/copy'
import { directionForLocale } from '../../app/copy'
import { EmptyState } from '../../ui'

const copy = {
  ar: 'تُدار نطاقات الصلاحيات الآن في تبويب السياسات والنطاقات.',
  en: 'Access scopes are now managed in the Policies & Scopes tab.',
} as const satisfies Record<Locale, string>

/**
 * Compatibility entry point for the legacy access-scopes route. Active routing
 * mounts the policies-and-scopes tab in Accounts & Permissions.
 */
export function AccessScopesScreen({ locale }: { locale: Locale }) {
  return (
    <div dir={directionForLocale(locale)}>
      <EmptyState icon={<Network aria-hidden="true" />} title={copy[locale]} />
    </div>
  )
}

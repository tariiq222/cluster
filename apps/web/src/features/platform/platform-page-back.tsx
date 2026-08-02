import { ArrowLeft, ArrowRight } from 'lucide-react'
import type { Locale } from '../../i18n'
import { Button } from '@/components/ui/button'

/*
 * Locale-aware back link for platform full pages (DESIGN-RULES §2.6).
 * The arrow mirrors the reading direction: ArrowRight in Arabic, ArrowLeft
 * in English. The label is caller-supplied localized copy.
 */
export function PlatformBackButton({
  label,
  onBack,
  locale,
}: {
  label: string
  onBack: () => void
  locale: Locale
}) {
  return (
    <Button variant="ghost" size="sm" onClick={onBack} className="-ms-2">
      {locale === 'ar' ? (
        <ArrowRight aria-hidden="true" />
      ) : (
        <ArrowLeft aria-hidden="true" />
      )}
      {label}
    </Button>
  )
}

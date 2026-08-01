import type { Locale } from '../../i18n'
import { usePrincipal } from '../../app/principal-context'

export const CODE_PATTERN = /^[A-Z0-9_-]{2,64}$/

export const unitTypes: Array<
  [
    'sector' | 'department' | 'section' | 'unit',
    'sector' | 'department' | 'section' | 'unit',
  ]
> = [
  ['sector', 'sector'],
  ['department', 'department'],
  ['section', 'section'],
  ['unit', 'unit'],
]

export const facilityTypes: Array<
  [
    'hospital' | 'center' | 'lab' | 'shared_services',
    'hospital' | 'center' | 'lab' | 'sharedServices',
  ]
> = [
  ['hospital', 'hospital'],
  ['center', 'center'],
  ['lab', 'lab'],
  ['shared_services', 'sharedServices'],
]

export function displayName(
  locale: Locale,
  resource: { name_ar: string; name_en: string | null },
): string {
  return locale === 'en' && resource.name_en
    ? resource.name_en
    : resource.name_ar
}

export function toUtcIso(localValue: string): string | undefined {
  if (!localValue) return undefined
  const date = new Date(localValue)
  return Number.isNaN(date.getTime()) ? undefined : date.toISOString()
}

export function localDateTimeInput(iso: string): string {
  const date = new Date(iso)
  if (Number.isNaN(date.getTime())) return ''
  const offset = date.getTimezoneOffset()
  return new Date(date.getTime() - offset * 60_000).toISOString().slice(0, 16)
}

export function useCapabilities(): string[] {
  const principal = usePrincipal()
  return principal.capabilities ?? []
}

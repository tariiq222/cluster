import type { ReactNode } from 'react'
import { CircleAlert, LockKeyhole } from 'lucide-react'

import type { CollectionResponse, EntityResponse } from '../../api/generated/cluster'
import { EmptyState, InlineError, SkeletonList } from '../../ui'
import type { Locale } from '../../app/copy'

/** Server responses stay generated; this is a screen-state, not an API model. */
export type PlatformScreenResource = EntityResponse | CollectionResponse
export type PlatformScreenState = 'loading' | 'denied' | 'error' | 'empty' | 'stale' | 'success'

export type PlatformScreenProps = {
  locale: Locale
  state?: PlatformScreenState
  allowedActions?: readonly string[]
  resource?: PlatformScreenResource
}

export type PlatformLogsScreenProps = PlatformScreenProps & {
  logs?: CollectionResponse
  onCursorChange?: (cursor: string | null) => void
}

export function isAllowed(actions: readonly string[] | undefined, action: string): boolean {
  return actions?.includes(action) === true
}

export function stateGate(locale: Locale, state: PlatformScreenState, emptyTitle: string): ReactNode | null {
  const ar = locale === 'ar'
  if (state === 'loading') return <SkeletonList label={ar ? 'جارٍ تحميل بيانات المنصة' : 'Loading platform data'} rows={4} />
  if (state === 'denied') {
    return <EmptyState icon={<LockKeyhole />} title={ar ? 'لا تملك صلاحية هذا القسم' : 'You do not have access to this section'} body={ar ? 'تُخفى البيانات والإجراءات غير المصرح بها.' : 'Unauthorized data and actions are hidden.'} />
  }
  if (state === 'error') return <InlineError message={ar ? 'تعذر تحميل البيانات. أعد المحاولة.' : 'The data could not be loaded. Try again.'} />
  if (state === 'empty') return <EmptyState icon={<CircleAlert />} title={emptyTitle} body={ar ? 'لا توجد بيانات متاحة ضمن النطاق الحالي.' : 'There is no data in the current scope.'} />
  return null
}

export const screenText = (locale: Locale, ar: string, en: string) => locale === 'ar' ? ar : en

import { directionForLocale, type Locale } from '../../app/copy'
import { EmptyState, Page, PageHeader } from '../../ui'

export type PoliciesScopesTabProps = {
  locale: Locale
  capabilities: readonly string[]
}

const COPY = {
  ar: {
    title: 'السياسات والنطاقات',
    intro: 'سياسات التصنيف وقوالب وصول الحقول ونطاقات الوصول.',
    unavailableTitle: 'لا تتوفر إدارة السياسات من هذه المساحة',
    unavailableBody: 'لا تعرض هذه المساحة عناصر تحكم وهمية. استخدم مساحة إعدادات السياسات المعتمدة عندما تكون متاحة لحسابك.',
  },
  en: {
    title: 'Policies & scopes',
    intro: 'Classification policies, field-access templates, and access scopes.',
    unavailableTitle: 'Policy management is unavailable in this workspace',
    unavailableBody: 'This workspace does not show non-functional editing controls. Use the approved policy settings workspace when it is available to your account.',
  },
} as const satisfies Record<Locale, Record<string, string>>

/**
 * Policy and scope editing has no complete Task 7 API surface. Show one
 * localized, honest unavailable state rather than controls which cannot save.
 */
export function PoliciesScopesTab({ locale }: PoliciesScopesTabProps) {
  const labels = COPY[locale]

  return (
    <div dir={directionForLocale(locale)}>
      <Page>
        <PageHeader id="policies-scopes-heading" title={labels.title} description={labels.intro} />
        <EmptyState
          icon={<span aria-hidden="true">🔒</span>}
          title={labels.unavailableTitle}
          body={labels.unavailableBody}
        />
      </Page>
    </div>
  )
}

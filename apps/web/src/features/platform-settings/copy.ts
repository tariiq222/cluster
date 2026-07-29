import type { PlatformSettingsSection } from '../../shell/routes'

export type PlatformSettingsCopy = {
  title: string
  description: string
  navigationLabel: string
  loadingCapabilities: string
  unavailableTitle: string
  unavailableBody: string
  returnToOverview: string
  sectionPlaceholder: string
  sections: Record<PlatformSettingsSection | 'api-reference', string>
}

export const platformSettingsCopy: Record<'ar' | 'en', PlatformSettingsCopy> = {
  ar: {
    title: 'إعدادات المنصة',
    description: 'مركز موحّد للإعدادات التشغيلية والصحة والسجلات ضمن صلاحياتك.',
    navigationLabel: 'أقسام إعدادات المنصة',
    loadingCapabilities: 'جارٍ التحقق من صلاحيات إعدادات المنصة…',
    unavailableTitle: 'لا توجد أقسام متاحة',
    unavailableBody: 'تظهر أقسام إعدادات المنصة بعد تحميل الصلاحيات المتاحة في نطاقك.',
    returnToOverview: 'العودة إلى مركز التحكم',
    sectionPlaceholder: 'ستظهر تفاصيل هذا القسم هنا.',
    sections: {
      overview: 'مركز التحكم',
      security: 'الأمان والإعدادات',
      calendars: 'تقويم العمل',
      backups: 'النسخ الاحتياطي والاستعادة',
      logs: 'السجلات التقنية',
      health: 'صحة المنصة والتنبيهات',
      maintenance: 'وضع الصيانة',
      apiReference: 'مرجع API',
    },
  },
  en: {
    title: 'Platform settings',
    description: 'A governed center for operational settings, health, and logs within your access.',
    navigationLabel: 'Platform settings sections',
    loadingCapabilities: 'Checking platform settings permissions…',
    unavailableTitle: 'No sections available',
    unavailableBody: 'Platform settings sections appear after your available permissions load.',
    returnToOverview: 'Return to control center',
    sectionPlaceholder: 'Details for this section will appear here.',
    sections: {
      overview: 'Control center',
      security: 'Security and settings',
      calendars: 'Business calendar',
      backups: 'Backups and recovery',
      logs: 'Technical logs',
      health: 'Platform health and alerts',
      maintenance: 'Maintenance mode',
      apiReference: 'API reference',
    },
  },
}

export type Locale = 'ar' | 'en'

export const LOCALE_KEY = 'cluster.presentation-locale'

export const DEFAULT_LOCALE: Locale = 'ar'

export function initialLocale(): Locale {
  const stored = localStorage.getItem(LOCALE_KEY)
  return stored === 'en' ? 'en' : DEFAULT_LOCALE
}

export function directionForLocale(locale: Locale): 'rtl' | 'ltr' {
  return locale === 'ar' ? 'rtl' : 'ltr'
}

export function formattingLocale(locale: Locale): string {
  return locale === 'ar' ? 'ar-SA' : 'en-GB'
}

export function numberFormattingLocale(locale: Locale): string {
  return locale === 'ar' ? 'ar-SA-u-nu-arab' : 'en-US'
}

export function formatDate(value: string | null | undefined, locale: Locale): string {
  if (!value) return ''
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return value
  return new Intl.DateTimeFormat(formattingLocale(locale), { dateStyle: 'medium', timeStyle: 'short' }).format(date)
}

export function formatNumber(value: number, locale: Locale): string {
  return new Intl.NumberFormat(numberFormattingLocale(locale)).format(value)
}

type Copy = Record<Locale, string>

export const common: Copy = {
  ar: 'عام',
  en: 'Common',
}

export const shellCopy = {
  ar: {
    brand: 'منصة التجمع الصحي',
    home: 'الرئيسية',
    tasks: 'المهام',
    documents: 'المستندات',
    organization: 'المنظمة',
    accountsPermissions: 'الحسابات والصلاحيات',
    reportsMonitoring: 'التقارير والمراقبة',
    platformManagement: 'إدارة المنصة',
    search: 'بحث',
    searchPlaceholder: 'ابحث في المنصة…',
    notifications: 'الإشعارات',
    noNotifications: 'لا توجد إشعارات',
    markRead: 'تحديد كمقروء',
    loadMore: 'عرض المزيد',
    logout: 'تسجيل الخروج',
    language: 'اللغة',
    menu: 'القائمة',
    closeMenu: 'إغلاق القائمة',
    personalSecurity: 'الأمان الشخصي',
    accessContext: 'سياق الوصول',
    scope: 'النطاق',
    facility: 'المنشأة',
    footer: 'جميع الحقوق محفوظة © 2026',
    signIn: 'تسجيل الدخول',
    username: 'اسم المستخدم',
    password: 'كلمة المرور',
    showPassword: 'إظهار كلمة المرور',
    hidePassword: 'إخفاء كلمة المرور',
    theme: 'المظهر',
    light: 'فاتح',
    dark: 'داكن',
    system: 'تلقائي',
    workRecords: 'سجلات العمل',
    inbox: 'صندوق الموافقات',
    account: 'الحساب',
    sessionExpired: 'انتهت الجلسة، يرجى تسجيل الدخول مرة أخرى.',
    signingIn: 'جارٍ التحقق…',
    denied: 'غير مصرح لك بالوصول إلى هذه الصفحة.',
    notFound: 'الصفحة غير موجودة.',
    retry: 'إعادة المحاولة',
    error: 'حدث خطأ غير متوقع.',
    forbidden: 'غير مصرح',
    loading: 'جارٍ التحميل…',
    empty: 'لا توجد بيانات.',
    cancel: 'إلغاء',
    save: 'حفظ',
    close: 'إغلاق',
    confirm: 'تأكيد',
    submit: 'إرسال',
    searchNoResults: 'لا توجد نتائج',
  },
  en: {
    brand: 'Health Cluster Platform',
    home: 'Home',
    tasks: 'Tasks',
    documents: 'Documents',
    organization: 'Organization',
    accountsPermissions: 'Accounts & Permissions',
    reportsMonitoring: 'Reports & Monitoring',
    platformManagement: 'Platform Management',
    search: 'Search',
    searchPlaceholder: 'Search the platform…',
    notifications: 'Notifications',
    noNotifications: 'No notifications',
    markRead: 'Mark as read',
    loadMore: 'Load more',
    logout: 'Sign out',
    language: 'Language',
    menu: 'Menu',
    closeMenu: 'Close menu',
    personalSecurity: 'Personal security',
    accessContext: 'Access context',
    scope: 'Scope',
    facility: 'Facility',
    footer: 'All rights reserved © 2026',
    signIn: 'Sign in',
    username: 'Username',
    password: 'Password',
    showPassword: 'Show password',
    hidePassword: 'Hide password',
    theme: 'Theme',
    light: 'Light',
    dark: 'Dark',
    system: 'System',
    workRecords: 'Work Records',
    inbox: 'Approvals Inbox',
    account: 'Account',
    sessionExpired: 'Session expired. Please sign in again.',
    signingIn: 'Verifying…',
    denied: 'You are not authorized to view this page.',
    notFound: 'Page not found.',
    retry: 'Retry',
    error: 'Something went wrong.',
    forbidden: 'Forbidden',
    loading: 'Loading…',
    empty: 'No data.',
    cancel: 'Cancel',
    save: 'Save',
    close: 'Close',
    confirm: 'Confirm',
    submit: 'Submit',
    searchNoResults: 'No results',
  },
} as const

export function statusLabel(status: string, locale: Locale): string {
  const labels: Record<string, Copy> = {
    open: { ar: 'مفتوحة', en: 'Open' },
    in_progress: { ar: 'قيد التنفيذ', en: 'In progress' },
    blocked: { ar: 'محظورة', en: 'Blocked' },
    completed: { ar: 'مكتملة', en: 'Completed' },
    cancelled: { ar: 'ملغاة', en: 'Cancelled' },
    submitted: { ar: 'مقدَّم', en: 'Submitted' },
    returned: { ar: 'مُعاد', en: 'Returned' },
    draft: { ar: 'مسودة', en: 'Draft' },
    pending: { ar: 'قيد الانتظار', en: 'Pending' },
    active: { ar: 'نشط', en: 'Active' },
    disabled: { ar: 'معطَّل', en: 'Disabled' },
    archived: { ar: 'مؤرشف', en: 'Archived' },
    rejected: { ar: 'مرفوض', en: 'Rejected' },
    approved: { ar: 'معتمد', en: 'Approved' },
    published: { ar: 'منشور', en: 'Published' },
    unread: { ar: 'غير مقروء', en: 'Unread' },
    read: { ar: 'مقروء', en: 'Read' },
  }
  return labels[status]?.[locale] ?? status
}

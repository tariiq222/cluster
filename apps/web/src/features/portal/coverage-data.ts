// Static inventory backing the product-review coverage screen.
// Not live data: these figures are maintained by hand alongside the coverage audit.

export type Bilingual = { ar: string; en: string }

export const CONTRACT_VERSION = '1.1.0'
export const GENERATED_AT = '2026-07-23T03:35:00+03:00'

export interface CoverageStat {
  value: string
  label: Bilingual
}

export interface CoverageModule {
  name: Bilingual
  count: string
  label: Bilingual
  type: 'ui' | 'internal'
}

export interface GapItem {
  rank: 'P0' | 'P1'
  title: Bilingual
  desc: Bilingual
}

export const COVERAGE_STATS: CoverageStat[] = [
  { value: '13', label: { ar: 'نطاقًا تقنيًا في الجرد', en: 'Technical surfaces in the inventory' } },
  { value: '9', label: { ar: 'وجهات استخدام رئيسية', en: 'Primary user surfaces' } },
  { value: '2', label: { ar: 'عمليتان داخليتان بلا زر', en: 'Internal operations without a button' } },
  { value: '4', label: { ar: 'إضافات مؤكدة الأولوية', en: 'Confirmed-priority additions' } },
]

export const COVERAGE_MODULES: CoverageModule[] = [
  { name: { ar: 'المنظمة', en: 'Organization' }, count: '36', label: { ar: 'المنظمة والقوى العاملة', en: 'Organization and workforce' }, type: 'ui' },
  { name: { ar: 'الهوية', en: 'Identity' }, count: '15', label: { ar: 'الدخول، الحساب، النطاق', en: 'Sign-in, account, scope' }, type: 'ui' },
  { name: { ar: 'سير العمل', en: 'Workflow' }, count: '11', label: { ar: 'الإجراءات وسير العمل', en: 'Procedures and workflow' }, type: 'ui' },
  { name: { ar: 'المستندات', en: 'Documents' }, count: '10', label: { ar: 'مركز المستندات', en: 'Document hub' }, type: 'ui' },
  { name: { ar: 'التفويض', en: 'Authorization' }, count: '9', label: { ar: 'الأدوار وقرار الوصول', en: 'Roles and access decisions' }, type: 'ui' },
  { name: { ar: 'المهام', en: 'Tasks' }, count: '8', label: { ar: 'المهام والتعليقات', en: 'Tasks and engagement' }, type: 'ui' },
  { name: { ar: 'التقارير', en: 'Reporting' }, count: '6', label: { ar: 'التقارير واللوحات', en: 'Reports and dashboards' }, type: 'ui' },
  { name: { ar: 'تعريفات العمل', en: 'Work Definitions' }, count: '5', label: { ar: 'تعريفات العمل', en: 'Work definitions' }, type: 'ui' },
  { name: { ar: 'سجلات العمل', en: 'Work Records' }, count: '5', label: { ar: 'سجلات العمل', en: 'Work records' }, type: 'ui' },
  { name: { ar: 'إصدارات تعريف العمل', en: 'Work Definition Versions' }, count: '2', label: { ar: 'إصدارات تعريف العمل', en: 'Work definition versions' }, type: 'ui' },
  { name: { ar: 'الإشعارات', en: 'Notifications' }, count: '2', label: { ar: 'الهيدر', en: 'Header' }, type: 'internal' },
  { name: { ar: 'الأدوات الداخلية', en: 'Internal' }, count: '2', label: { ar: 'فحص وترقية المستند', en: 'Scan and promote' }, type: 'internal' },
  { name: { ar: 'البحث', en: 'Search' }, count: '1', label: { ar: 'البحث الموحّد في الهيدر', en: 'Unified header search' }, type: 'ui' },
]

export const GAP_ITEMS: GapItem[] = [
  {
    rank: 'P0',
    title: { ar: 'سجل تدقيق مستقل وقابل للبحث', en: 'Independent searchable audit trail' },
    desc: { ar: 'لا يوجد endpoint للأحداث أو من غيّر ماذا ومتى. لذلك أزلت شاشة التدقيق السابقة.', en: 'No event endpoint, no record of who changed what and when. The previous audit screen was therefore removed.' },
  },
  {
    rank: 'P0',
    title: { ar: 'إنشاء المقاعد دفعة واحدة', en: 'Bulk seat creation' },
    desc: { ar: 'حالة 30 فنيًا تتطلب حاليًا 30 طلب إنشاء منفصلًا، ما يعرّض العملية للنجاح الجزئي.', en: 'A 30-technician cohort currently requires 30 separate creation requests, exposing the operation to partial success.' },
  },
  {
    rank: 'P0',
    title: { ar: 'تفاصيل وتعديل المسمى الوظيفي', en: 'Job title detail and edit' },
    desc: { ar: 'المسميات لها List & Create فقط؛ لا يوجد Get by ID أو Patch أو تعطيل صريح.', en: 'Job titles have List & Create only; no Get by ID, Patch, or explicit deactivation.' },
  },
  {
    rank: 'P0',
    title: { ar: 'إنهاء العلاقة الإشرافية', en: 'End supervisory relationship' },
    desc: { ar: 'يوجد List & Create فقط ولا يظهر مسار End أو Revoke للعلاقة.', en: 'Only List & Create exists; no End or Revoke path for the relationship.' },
  },
  {
    rank: 'P1',
    title: { ar: 'إدارة منح المستند كاملة', en: 'Full document-grant administration' },
    desc: { ar: 'إنشاء المنحة ظاهر، لكن عرضها وإلغاؤها كعمليات صريحة غير موجودين.', en: 'Grant creation is visible, but viewing and revoking as explicit operations are absent.' },
  },
  {
    rank: 'P1',
    title: { ar: 'إشعارات جماعية', en: 'Bulk notifications' },
    desc: { ar: 'يوجد تعليم إشعار واحد كمقروء فقط؛ يفضّل unread-count وmark-all-read.', en: 'Only single-notification mark-as-read exists; prefer unread-count and mark-all-read.' },
  },
]

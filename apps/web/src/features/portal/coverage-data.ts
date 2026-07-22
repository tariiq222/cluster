// Static inventory backing the product-review coverage screen.
// Not live data: these figures are maintained by hand alongside the coverage audit.

export interface CoverageStat {
  value: string
  label: string
}

export interface CoverageModule {
  name: string
  count: string
  label: string
  type: 'ui' | 'internal'
}

export interface GapItem {
  rank: string
  title: string
  desc: string
}

export const COVERAGE_STATS: CoverageStat[] = [
  { value: '13', label: 'نطاقًا تقنيًا في الجرد' },
  { value: '9', label: 'وجهات استخدام رئيسية' },
  { value: '2', label: 'عمليتان داخليتان بلا زر' },
  { value: '4', label: 'إضافات مؤكدة الأولوية' },
]

export const COVERAGE_MODULES: CoverageModule[] = [
  { name: 'Organization', count: '36', label: 'المنظمة والقوى العاملة', type: 'ui' },
  { name: 'Identity', count: '15', label: 'الدخول، الحساب، النطاق', type: 'ui' },
  { name: 'Workflow', count: '11', label: 'الإجراءات وسير العمل', type: 'ui' },
  { name: 'Documents', count: '10', label: 'مركز المستندات', type: 'ui' },
  { name: 'Authorization', count: '9', label: 'الأدوار وقرار الوصول', type: 'ui' },
  { name: 'Tasks', count: '8', label: 'المهام والتعليقات', type: 'ui' },
  { name: 'Reporting', count: '6', label: 'التقارير واللوحات', type: 'ui' },
  { name: 'Work Definitions', count: '5', label: 'تعريفات العمل', type: 'ui' },
  { name: 'Work Records', count: '5', label: 'سجلات العمل', type: 'ui' },
  { name: 'Work Definition Versions', count: '2', label: 'إصدارات تعريف العمل', type: 'ui' },
  { name: 'Notifications', count: '2', label: 'الهيدر', type: 'internal' },
  { name: 'Internal', count: '2', label: 'فحص وترقية المستند', type: 'internal' },
  { name: 'Search', count: '1', label: 'البحث الموحّد في الهيدر', type: 'ui' },
]

export const GAP_ITEMS: GapItem[] = [
  {
    rank: 'P0',
    title: 'سجل تدقيق مستقل وقابل للبحث',
    desc: 'لا يوجد endpoint للأحداث أو من غيّر ماذا ومتى. لذلك أزلت شاشة التدقيق السابقة.',
  },
  {
    rank: 'P0',
    title: 'إنشاء المقاعد دفعة واحدة',
    desc: 'حالة 30 فنيًا تتطلب حاليًا 30 طلب إنشاء منفصلًا، ما يعرّض العملية للنجاح الجزئي.',
  },
  {
    rank: 'P0',
    title: 'تفاصيل وتعديل المسمى الوظيفي',
    desc: 'المسميات لها List & Create فقط؛ لا يوجد Get by ID أو Patch أو تعطيل صريح.',
  },
  {
    rank: 'P0',
    title: 'إنهاء العلاقة الإشرافية',
    desc: 'يوجد List & Create فقط ولا يظهر مسار End أو Revoke للعلاقة.',
  },
  {
    rank: 'P1',
    title: 'إدارة منح المستند كاملة',
    desc: 'إنشاء المنحة ظاهر، لكن عرضها وإلغاؤها كعمليات صريحة غير موجودين.',
  },
  {
    rank: 'P1',
    title: 'إشعارات جماعية',
    desc: 'يوجد تعليم إشعار واحد كمقروء فقط؛ يفضّل unread-count وmark-all-read.',
  },
]

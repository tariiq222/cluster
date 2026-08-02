/*
 * Copy for the audit event detail page (route
 * `/reports/audit/events/:eventId`). The page is the full-page successor
 * of the former detail Sheet; the ledger keeps its own inline copy.
 */
export const auditCopy = {
  ar: {
    backToLedger: 'عودة إلى سجل التدقيق',
    eventDetail: 'تفاصيل الحدث',
    redacted: 'يعرض الخادم سياقًا منقحًا فقط. لا تتضمن هذه الواجهة التجزئات أو المفاتيح أو بصمة الطلب.',
    eventId: 'معرّف الحدث',
    correlationId: 'معرّف الارتباط',
    eventType: 'نوع الحدث',
    occurred: 'وقت الحدث',
    recorded: 'وقت التسجيل',
    retention: 'الاحتفاظ حتى',
    accessDecision: 'قرار الوصول',
    actor: 'المنفذ',
    subject: 'الموضوع',
    context: 'السياق المنقح',
    notAvailable: 'غير متاح',
    system: 'النظام',
    loading: 'جارٍ تحميل تفاصيل الحدث…',
  },
  en: {
    backToLedger: 'Back to audit ledger',
    eventDetail: 'Audit event detail',
    redacted:
      'Only server-redacted context is shown. Hashes, keys, and request fingerprints never enter this interface.',
    eventId: 'Event ID',
    correlationId: 'Correlation ID',
    eventType: 'Event type',
    occurred: 'Occurred',
    recorded: 'Recorded',
    retention: 'Retained until',
    accessDecision: 'Access decision',
    actor: 'Actor',
    subject: 'Subject',
    context: 'Redacted context',
    notAvailable: 'Not available',
    system: 'System',
    loading: 'Loading event detail…',
  },
} as const

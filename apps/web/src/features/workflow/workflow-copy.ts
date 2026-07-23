import type { Locale } from '../../app/copy'

export type WorkflowCopy = {
  approvalInbox: string
  approvalInboxDescription: string
  myRequests: string
  myRequestsDescription: string
  newProcedureRequest: string
  newProcedureRequestDescription: string
  procedureGuide: string
  procedureGuideDescription: string
  refresh: string
  retry: string
  loading: string
  loadingProcedures: string
  checkingAccess: string
  deniedTitle: string
  deniedBody: string
  recovery: string
  error: string
  conflict: string
  stale: string
  success: string
  subject: string
  age: string
  currentOwner: string
  status: string
  stepHistory: string
  noApprovals: string
  noApprovalsBody: string
  noRequests: string
  noRequestsBody: string
  noProcedures: string
  noProceduresBody: string
  approve: string
  reject: string
  reason: string
  reasonHint: string
  reasonRequired: string
  approving: string
  rejecting: string
  identification: string
  procedureName: string
  procedureNameHelp: string
  procedureCode: string
  procedureCodeHelp: string
  usageDescription: string
  usageDescriptionHelp: string
  fields: string
  fieldsDescription: string
  fieldLabel: string
  fieldType: string
  fieldDescription: string
  addField: string
  removeField: string
  chain: string
  chainDescription: string
  chainPlaceholder: string
  attachments: string
  attachmentsPlaceholder: string
  submitForReview: string
  submitting: string
  validation: string
  nameRequired: string
  codeRequired: string
  usageRequired: string
  fieldRequired: string
  chainRequired: string
  requestSubmitted: string
  requestPrepared: string
  requestPreparedBody: string
  contractUnavailable: string
  publishedProcedures: string
  openProcedure: string
  attachmentNote: string
  workflowState: (state: string) => string
  // Stage 3 — operations office procedure lifecycle surfaces
  procAuthoring: string
  procAuthoringDescription: string
  procOfficeReview: string
  procOfficeReviewDescription: string
  procGuide: string
  procGuideDescription: string
  procStepList: string
  procStepListHelp: string
  procCode: string
  procStepKey: string
  procStepType: string
  procStepAssignmentRule: string
  procStepAssignmentType: string
  procStepAssignmentStepKey: string
  procStepAssignmentRoleCode: string
  procStepAssignmentNone: string
  procAddStep: string
  procRemoveStep: string
  procMoveUp: string
  procMoveDown: string
  procSubmitForReview: string
  procSubmitting: string
  procSubmitFallback: string
  procAuthoringEmpty: string
  procAuthoringEmptyBody: string
  procOfficeEmpty: string
  procOfficeEmptyBody: string
  procGraphHash: string
  procGraphHashHelp: string
  procGraphHashRequired: string
  procApprove: string
  procApproving: string
  procReturnForRevision: string
  procReturning: string
  procReturnReason: string
  procReturnReasonHelp: string
  procReturnReasonRequired: string
  procSingleMemberBadge: string
  procSingleMemberBadgeHelp: string
  procSelfApprovalForbidden: string
  procSelfApprovalForbiddenHelp: string
  procSubmitter: string
  procSubmittedAt: string
  procApprover: string
  procApprovedAt: string
  procVersion: string
  procVersionNumber: string
  procReviewState: string
  procStateSubmitted: string
  procStateReturned: string
  procStateApproved: string
  procStatePublished: string
  procGuideEmpty: string
  procGuideEmptyBody: string
  procGuideDeepLink: string
  procGuideDeepLinkHelp: string
  procStepRequired: string
  procDraft: string
  procPending: string
  procReturned: string
  procApproved: string
  procPublished: string
  procRuleSupervisorOfInitiator: string
  procRuleSupervisorOfStep: string
  procRuleRole: string
  procAssignmentRuleStepKeyPlaceholder: string
  procAssignmentRuleRolePlaceholder: string
  procAssignmentRuleTypePlaceholder: string
  procStepTypePlaceholder: string
  procStepKeyPlaceholder: string
  procSubmitSuccess: string
  procApprovalSuccess: string
  procReturnSuccess: string
  procAuthoringLoading: string
  procOfficeLoading: string
  procAuditTrail: string
  procOfficeApprovedOnce: string
  // Stage 4 — request lifecycle surfaces
  reqApprovalInbox: string
  reqApprovalInboxDescription: string
  reqMyRequests: string
  reqMyRequestsDescription: string
  reqNewProcedureRequest: string
  reqNewProcedureRequestDescription: string
  reqLoading: string
  reqDeniedTitle: string
  reqDeniedBody: string
  reqError: string
  reqEmptyApprovals: string
  reqEmptyApprovalsBody: string
  reqEmptyRequests: string
  reqEmptyRequestsBody: string
  reqCurrentOwner: string
  reqStartedAt: string
  reqHistory: string
  reqClose: string
  reqNoHistory: string
  reqReasonMin: string
  reqDecisionPending: string
  reqRequestPrepared: string
  reqApiUpdating: string
  reqApiUpdatingBody: string
  detail: string
  backToList: string
  deepLink: string
  copyLink: string
  linkCopied: string
  noDetails: string
  noComments: string
  comments: string
  taskDetails: string
  taskDescription: string
  taskActions: string
  reassign: string
  escalate: string
  returnForCorrection: string
  returning: string
  escalating: string
  reassigning: string
  actionReasonRequired: string
  reassignmentTarget: string
  reassignmentTargetHelp: string
  reassignmentTargetRequired: string
}

export const workflowCopy: Record<Locale, WorkflowCopy> = {
  ar: {
    approvalInbox: 'اعتماداتي',
    approvalInboxDescription: 'الخطوات المسندة إليك فقط والمنتظرة لاتخاذ قرار.',
    myRequests: 'طلباتي',
    myRequestsDescription: 'تابع حالة كل طلب أرسلته ومن يملكه الآن.',
    newProcedureRequest: 'طلب إجراء جديد',
    newProcedureRequestDescription: 'اقترح إجراءً جديداً ليُراجع من مكتب إدارة العمليات.',
    procedureGuide: 'دليل الإجراءات',
    procedureGuideDescription: 'الإجراءات المنشورة المتاحة للقراءة والاستخدام.',
    refresh: 'تحديث',
    retry: 'إعادة المحاولة',
    loading: 'جارٍ تحميل البيانات…',
    loadingProcedures: 'جارٍ تحميل الإجراءات المنشورة…',
    checkingAccess: 'جارٍ التحقق من صلاحية الوصول…',
    deniedTitle: 'لا تملك صلاحية الوصول',
    deniedBody: 'تُحسم صلاحية هذا المسار من الخادم. اطلب الصلاحية من مسؤول المنصة أو عد إلى مساحة عملك.',
    recovery: 'العودة إلى الرئيسية',
    error: 'تعذر تحميل البيانات. أعد المحاولة.',
    conflict: 'تغيّرت البيانات أثناء تنفيذ القرار. حدّث الصفحة ثم أعد المحاولة.',
    stale: 'أصبحت هذه الخطوة قديمة. حدّث الصفحة قبل اتخاذ القرار.',
    success: 'اكتمل القرار بنجاح.',
    subject: 'موضوع الطلب',
    age: 'العمر',
    currentOwner: 'المسؤول الحالي',
    status: 'الحالة',
    stepHistory: 'عرض سجل الخطوات',
    noApprovals: 'لا توجد اعتمادات تنتظر قرارك',
    noApprovalsBody: 'ستظهر هنا الخطوات التي يملكها حسابك بعد إسنادها إليك.',
    noRequests: 'لا توجد طلبات مرسلة بعد',
    noRequestsBody: 'عند إرسال طلب سيظهر مساره ومالكه الحالي هنا.',
    noProcedures: 'لا توجد إجراءات منشورة',
    noProceduresBody: 'سيظهر دليل الإجراءات بعد نشر أول إجراء من مكتب إدارة العمليات.',
    approve: 'اعتماد',
    reject: 'رفض',
    reason: 'سبب القرار',
    reasonHint: 'اكتب سبباً واضحاً عند الرفض ليساعد مقدم الطلب على التصحيح.',
    reasonRequired: 'سبب الرفض مطلوب.',
    approving: 'جارٍ الاعتماد…',
    rejecting: 'جارٍ الرفض…',
    identification: '1. تعريف الإجراء',
    procedureName: 'اسم الإجراء',
    procedureNameHelp: 'استخدم اسماً واضحاً يصف النتيجة التي يطلبها الموظف.',
    procedureCode: 'رمز الإجراء',
    procedureCodeHelp: 'أحرف إنجليزية صغيرة وأرقام وشرطات فقط.',
    usageDescription: 'متى يُستخدم الإجراء؟',
    usageDescriptionHelp: 'اشرح متى يبدأ الطلب وما الغرض التشغيلي منه.',
    fields: '2. البيانات المطلوب جمعها',
    fieldsDescription: 'أضف الحقول في جدول بسيط. نوع الحقل من المفردات المغلقة للمنصة.',
    fieldLabel: 'اسم الحقل',
    fieldType: 'نوع الحقل',
    fieldDescription: 'وصف الحقل',
    addField: 'إضافة صف',
    removeField: 'حذف الصف',
    chain: '3. سلسلة الاعتماد المقترحة',
    chainDescription: 'اكتب السلسلة بلغة الهيكل، مثل: المدير المباشر → مدير الإدارة → الموارد البشرية.',
    chainPlaceholder: 'المدير المباشر → مدير الإدارة → الموارد البشرية',
    attachments: '4. المرفقات',
    attachmentsPlaceholder: 'رفع المرفقات سيُفعّل بعد نشر عقد المستندات الخاص بالطلب.',
    submitForReview: 'إرسال إلى مكتب إدارة العمليات',
    submitting: 'جارٍ إعداد الطلب وإرساله…',
    validation: 'أكمل الحقول المطلوبة ثم أعد الإرسال.',
    nameRequired: 'اسم الإجراء مطلوب.',
    codeRequired: 'رمز الإجراء مطلوب.',
    usageRequired: 'وصف الاستخدام مطلوب.',
    fieldRequired: 'أكمل اسم كل حقل ونوعه.',
    chainRequired: 'سلسلة الاعتماد المقترحة مطلوبة.',
    requestSubmitted: 'أُرسل الإجراء إلى مكتب إدارة العمليات للمراجعة.',
    requestPrepared: 'أُعدت مسودة الإجراء.',
    requestPreparedBody: 'تم حفظ التعريف الأولي، لكن عقد المراجعة غير متاح في هذه البيئة بعد. أعد المحاولة عند نشره.',
    contractUnavailable: 'عقد المراجعة والنشر غير متاح في هذه البيئة بعد. بقيت المسودة ولم يُعلن نجاح النشر.',
    publishedProcedures: 'الإجراءات المنشورة',
    openProcedure: 'فتح الإجراء',
    attachmentNote: 'المرفقات اختيارية، وسيتم ربطها بعد اكتمال عقد الرفع والفحص.',
    workflowState: (state) => ({
      draft: 'مسودة',
      submitted: 'مُرسل',
      pending_review: 'بانتظار المراجعة',
      in_review: 'قيد المراجعة',
      approved: 'معتمد',
      rejected: 'مرفوض',
      returned: 'معاد للتصحيح',
      completed: 'مكتمل',
      published: 'منشور',
      waiting: 'بانتظار القرار',
      active: 'نشط',
    }[state] ?? state),
    // Stage 3 surfaces
    procAuthoring: 'تصميم الإجراء',
    procAuthoringDescription: 'حرّر مسودة الإجراء ثم أرسلها إلى مكتب إدارة العمليات للمراجعة.',
    procOfficeReview: 'اعتمادات الإجراء',
    procOfficeReviewDescription: 'الإصدارات بانتظار مراجعة عضو آخر من مكتب العمليات قبل النشر.',
    procGuide: 'دليل الإجراءات المنشورة',
    procGuideDescription: 'الإجراءات المنشورة المتاحة للقراءة وبدء الاستخدام.',
    procStepList: 'سلسلة الاعتماد',
    procStepListHelp: 'رتّب الخطوات تصاعدياً. كل خطوة تستند إلى قاعدة إسناد من المفردات المغلقة.',
    procCode: 'رمز الإجراء',
    procStepKey: 'مفتاح الخطوة',
    procStepType: 'نوع الخطوة',
    procStepAssignmentRule: 'قاعدة الإسناد',
    procStepAssignmentType: 'نوع القاعدة',
    procStepAssignmentStepKey: 'مفتاح الخطوة السابقة',
    procStepAssignmentRoleCode: 'رمز الدور',
    procStepAssignmentNone: 'لا قاعدة',
    procAddStep: 'إضافة خطوة',
    procRemoveStep: 'حذف الخطوة',
    procMoveUp: 'نقل للأعلى',
    procMoveDown: 'نقل للأسفل',
    procSubmitForReview: 'إرسال للمراجعة',
    procSubmitting: 'جارٍ الإرسال…',
    procSubmitFallback: 'أُرسلت المسودة محلياً في انتظار نشر عقد مكتب العمليات.',
    procAuthoringEmpty: 'لا توجد مسودات بانتظار التصميم',
    procAuthoringEmptyBody: 'ستظهر هنا المسودات التي أنشأها أعضاء مكتب العمليات وبدأوا بتحريرها.',
    procOfficeEmpty: 'لا توجد إصدارات بانتظار المراجعة',
    procOfficeEmptyBody: 'تظهر هنا الإصدارات بعد أن يرسلها المؤلف وتبقى حتى اعتمادها أو إعادتها.',
    procGraphHash: 'بصمة السلسلة (graph_hash)',
    procGraphHashHelp: 'ألصق البصمة الظاهرة في شاشة المراجعة لتأكيد أن السلسلة لم تتغير منذ آخر معاينة.',
    procGraphHashRequired: 'تأكيد البصمة مطلوب للاعتماد.',
    procApprove: 'اعتماد الإصدار',
    procApproving: 'جارٍ الاعتماد…',
    procReturnForRevision: 'إعادة للتصحيح',
    procReturning: 'جارٍ الإعادة…',
    procReturnReason: 'سبب الإعادة',
    procReturnReasonHelp: 'اكتب سبباً واضحاً يساعد المؤلف على تصحيح السلسلة.',
    procReturnReasonRequired: 'سبب الإعادة مطلوب.',
    procSingleMemberBadge: 'اعتماد إقلاعي',
    procSingleMemberBadgeHelp: 'يُسمح باعتماد المؤلف لإصداره لوجود عضو نشط واحد فقط في المكتب. يُرفع الاستثناء تلقائياً بعد انضمام عضو ثانٍ.',
    procSelfApprovalForbidden: 'لا يحق لمؤلف الإصدار اعتماده بوجود عضوين فأكثر في المكتب.',
    procSelfApprovalForbiddenHelp: 'اعتمد عضو آخر من المكتب أو أعد الإصدار للتصحيح.',
    procSubmitter: 'المؤلف',
    procSubmittedAt: 'وقت الإرسال',
    procApprover: 'المعتمد',
    procApprovedAt: 'وقت الاعتماد',
    procVersion: 'الإصدار',
    procVersionNumber: 'رقم الإصدار',
    procReviewState: 'حالة المراجعة',
    procStateSubmitted: 'بانتظار المراجعة',
    procStateReturned: 'معاد للتصحيح',
    procStateApproved: 'معتمد',
    procStatePublished: 'منشور',
    procGuideEmpty: 'لا توجد إجراءات منشورة بعد',
    procGuideEmptyBody: 'تظهر هنا الإجراءات بعد نشرها من مكتب إدارة العمليات.',
    procGuideDeepLink: 'فتح نموذج التقديم',
    procGuideDeepLinkHelp: 'يبدأ التقديم من الدليل لضمان استخدام آخر إصدار منشور.',
    procStepRequired: 'أكمل مفتاح الخطوة ونوعها قبل الإرسال.',
    procDraft: 'مسودة',
    procPending: 'بانتظار المراجعة',
    procReturned: 'معاد للتصحيح',
    procApproved: 'معتمد',
    procPublished: 'منشور',
    procRuleSupervisorOfInitiator: 'المدير المباشر لمقدّم الطلب',
    procRuleSupervisorOfStep: 'مدير خطوة سابقة',
    procRuleRole: 'دور مؤسسي',
    procAssignmentRuleStepKeyPlaceholder: 'مفتاح الخطوة السابقة (مثل supervisor_step)',
    procAssignmentRuleRolePlaceholder: 'رمز الدور (مثل hr_officer)',
    procAssignmentRuleTypePlaceholder: 'اختر قاعدة الإسناد',
    procStepTypePlaceholder: 'اختر نوع الخطوة',
    procStepKeyPlaceholder: 'مفتاح قصير بالإنجليزية',
    procSubmitSuccess: 'أُرسل الإصدار للمراجعة.',
    procApprovalSuccess: 'اُعتمد الإصدار وأصبح جاهزاً للنشر.',
    procReturnSuccess: 'أُعيد الإصدار للمؤلف بالسبب المدخل.',
    procAuthoringLoading: 'جارٍ تحميل المسودات…',
    procOfficeLoading: 'جارٍ تحميل الإصدارات بانتظار المراجعة…',
     procAuditTrail: 'سجل المراجعة',
     procOfficeApprovedOnce: 'اعتُمد هذا الإصدار مرة واحدة بصلاحية الإقلاع.',
     reqApprovalInbox: 'اعتماداتي',
     reqApprovalInboxDescription: 'الخطوات المسندة إليك فقط والمنتظرة لاتخاذ قرار.',
     reqMyRequests: 'طلباتي',
     reqMyRequestsDescription: 'تابع حالة كل طلب أرسلته ومن يملكه الآن.',
     reqNewProcedureRequest: 'طلب إجراء جديد',
     reqNewProcedureRequestDescription: 'اقترح إجراءً جديداً ليُراجع من مكتب إدارة العمليات.',
     reqLoading: 'جارٍ تحميل الطلبات…',
     reqDeniedTitle: 'لا تملك صلاحية الوصول',
     reqDeniedBody: 'تُحسم صلاحية هذا المسار من الخادم. اطلب الصلاحية من مسؤول المنصة أو عد إلى مساحة عملك.',
     reqError: 'تعذر تحميل الطلبات. أعد المحاولة.',
     reqEmptyApprovals: 'لا توجد اعتمادات تنتظر قرارك',
     reqEmptyApprovalsBody: 'ستظهر هنا الخطوات التي يملكها حسابك بعد إسنادها إليك.',
     reqEmptyRequests: 'لا توجد طلبات مرسلة بعد',
     reqEmptyRequestsBody: 'عند إرسال طلب سيظهر مساره ومالكه الحالي هنا.',
     reqCurrentOwner: 'المسؤول الحالي',
     reqStartedAt: 'بدأ الطلب',
     reqHistory: 'عرض سجل الخطوات',
     reqClose: 'إغلاق',
     reqNoHistory: 'لا يوجد سجل خطوات بعد.',
     reqReasonMin: 'اكتب سبباً لا يقل عن 10 أحرف عند الرفض.',
     reqDecisionPending: 'جارٍ التنفيذ…',
     reqRequestPrepared: 'أُعدت مسودة الإجراء.',
     reqApiUpdating: 'عقد الطلب قيد التحديث',
      reqApiUpdatingBody: 'واجهة الإرسال إلى مكتب إدارة العمليات غير متاحة بعد. بقيت البيانات في الشاشة ولم يُعلن نجاح الإرسال.',
      detail: 'التفاصيل', backToList: 'العودة إلى القائمة', deepLink: 'الرابط المباشر', copyLink: 'نسخ الرابط', linkCopied: 'تم نسخ الرابط.', noDetails: 'لا تتوفر تفاصيل لهذا السجل.', noComments: 'لا توجد تعليقات بعد.', comments: 'التعليقات', taskDetails: 'تفاصيل المهمة', taskDescription: 'الوصف', taskActions: 'الإجراءات المسموحة', reassign: 'إعادة الإسناد', escalate: 'تصعيد', returnForCorrection: 'إعادة للتصحيح', returning: 'جارٍ الإرجاع…', escalating: 'جارٍ التصعيد…', reassigning: 'جارٍ إعادة الإسناد…', actionReasonRequired: 'سبب هذا الإجراء مطلوب.', reassignmentTarget: 'معرّف المستخدم الجديد', reassignmentTargetHelp: 'أدخل معرّف المستخدم الذي ستُسند إليه الخطوة.', reassignmentTargetRequired: 'معرّف المستخدم الجديد مطلوب.',
   },
  en: {
    approvalInbox: 'My approvals',
    approvalInboxDescription: 'Only steps assigned to you and waiting for a decision.',
    myRequests: 'My requests',
    myRequestsDescription: 'Track every request you submitted, its owner, and its current state.',
    newProcedureRequest: 'New procedure request',
    newProcedureRequestDescription: 'Suggest a procedure for the Operations Office to review.',
    procedureGuide: 'Procedure guide',
    procedureGuideDescription: 'Published procedures available to read and use.',
    refresh: 'Refresh',
    retry: 'Try again',
    loading: 'Loading data…',
    loadingProcedures: 'Loading published procedures…',
    checkingAccess: 'Checking access…',
    deniedTitle: 'You do not have access',
    deniedBody: 'The server decides access to this route. Ask a platform administrator for the capability or return to your workspace.',
    recovery: 'Back to home',
    error: 'We could not load the data. Try again.',
    conflict: 'The data changed while the decision was being submitted. Refresh and try again.',
    stale: 'This step is stale. Refresh before making a decision.',
    success: 'Decision completed successfully.',
    subject: 'Request subject',
    age: 'Age',
    currentOwner: 'Current owner',
    status: 'State',
    stepHistory: 'View step history',
    noApprovals: 'No approvals are waiting for you',
    noApprovalsBody: 'Steps owned by your account appear here when they are assigned to you.',
    noRequests: 'No submitted requests yet',
    noRequestsBody: 'A request path and its current owner will appear here after submission.',
    noProcedures: 'No published procedures',
    noProceduresBody: 'The guide will show the first procedure published by the Operations Office.',
    approve: 'Approve',
    reject: 'Reject',
    reason: 'Decision reason',
    reasonHint: 'Give a clear reason when rejecting so the requester can correct the submission.',
    reasonRequired: 'A rejection reason is required.',
    approving: 'Approving…',
    rejecting: 'Rejecting…',
    identification: '1. Procedure identification',
    procedureName: 'Procedure name',
    procedureNameHelp: 'Use a clear name that describes the requested outcome.',
    procedureCode: 'Procedure code',
    procedureCodeHelp: 'Use lowercase English letters, numbers, and hyphens only.',
    usageDescription: 'When is this procedure used?',
    usageDescriptionHelp: 'Explain when the request starts and its operational purpose.',
    fields: '2. Data to collect',
    fieldsDescription: 'Add fields in a simple table. Field types come from the platform vocabulary.',
    fieldLabel: 'Field name',
    fieldType: 'Field type',
    fieldDescription: 'Field description',
    addField: 'Add row',
    removeField: 'Remove row',
    chain: '3. Suggested approval chain',
    chainDescription: 'Write the chain in organizational language, for example: Direct supervisor → Department manager → HR.',
    chainPlaceholder: 'Direct supervisor → Department manager → HR',
    attachments: '4. Attachments',
    attachmentsPlaceholder: 'Attachments will be enabled when the request document contract is published.',
    submitForReview: 'Send to Operations Office',
    submitting: 'Preparing and submitting request…',
    validation: 'Complete the required fields, then submit again.',
    nameRequired: 'Procedure name is required.',
    codeRequired: 'Procedure code is required.',
    usageRequired: 'Usage description is required.',
    fieldRequired: 'Complete every field name and type.',
    chainRequired: 'A suggested approval chain is required.',
    requestSubmitted: 'The procedure was sent to the Operations Office for review.',
    requestPrepared: 'The procedure draft was prepared.',
    requestPreparedBody: 'The initial definition was saved, but the review contract is not available in this environment yet. Retry after it is published.',
    contractUnavailable: 'The review and publication contract is not available in this environment yet. The draft remains and publication was not reported as successful.',
    publishedProcedures: 'Published procedures',
    openProcedure: 'Open procedure',
    attachmentNote: 'Attachments are optional and will be linked after the upload and scan contract is complete.',
    workflowState: (state) => ({
      draft: 'Draft',
      submitted: 'Submitted',
      pending_review: 'Pending review',
      in_review: 'In review',
      approved: 'Approved',
      rejected: 'Rejected',
      returned: 'Returned for correction',
      completed: 'Completed',
      published: 'Published',
      waiting: 'Waiting for decision',
      active: 'Active',
    }[state] ?? state),
    // Stage 3 surfaces
    procAuthoring: 'Procedure authoring',
    procAuthoringDescription: 'Edit a procedure draft and submit it to the operations office for review.',
    procOfficeReview: 'Procedure office review',
    procOfficeReviewDescription: 'Versions awaiting review by a second operations office member before publication.',
    procGuide: 'Procedure guide',
    procGuideDescription: 'Published procedures available to read and start.',
    procStepList: 'Approval chain',
    procStepListHelp: 'Order the steps top to bottom. Every step uses one of the closed assignment rules.',
    procCode: 'Procedure code',
    procStepKey: 'Step key',
    procStepType: 'Step type',
    procStepAssignmentRule: 'Assignment rule',
    procStepAssignmentType: 'Rule type',
    procStepAssignmentStepKey: 'Previous step key',
    procStepAssignmentRoleCode: 'Role code',
    procStepAssignmentNone: 'No rule',
    procAddStep: 'Add step',
    procRemoveStep: 'Remove step',
    procMoveUp: 'Move up',
    procMoveDown: 'Move down',
    procSubmitForReview: 'Submit for review',
    procSubmitting: 'Submitting…',
    procSubmitFallback: 'The draft was kept locally because the operations office contract is not available yet.',
    procAuthoringEmpty: 'No drafts waiting for authoring',
    procAuthoringEmptyBody: 'Drafts created by operations office members appear here while they are being edited.',
    procOfficeEmpty: 'No versions awaiting review',
    procOfficeEmptyBody: 'Versions appear here after the author submits them and stay until approved or returned.',
    procGraphHash: 'Graph hash',
    procGraphHashHelp: 'Paste the graph hash visible on the review screen to confirm the chain has not changed.',
    procGraphHashRequired: 'Graph hash confirmation is required.',
    procApprove: 'Approve version',
    procApproving: 'Approving…',
    procReturnForRevision: 'Return for revision',
    procReturning: 'Returning…',
    procReturnReason: 'Return reason',
    procReturnReasonHelp: 'Give a clear reason so the author can correct the chain.',
    procReturnReasonRequired: 'A return reason is required.',
    procSingleMemberBadge: 'Bootstrap approval',
    procSingleMemberBadgeHelp: 'Self-approval is allowed because only one active member is on the office. The exception lifts as soon as a second member joins.',
    procSelfApprovalForbidden: 'The author cannot approve their own version while two or more office members are active.',
    procSelfApprovalForbiddenHelp: 'Have another office member approve or return the version for correction.',
    procSubmitter: 'Submitter',
    procSubmittedAt: 'Submitted at',
    procApprover: 'Approver',
    procApprovedAt: 'Approved at',
    procVersion: 'Version',
    procVersionNumber: 'Version number',
    procReviewState: 'Review state',
    procStateSubmitted: 'Pending review',
    procStateReturned: 'Returned for correction',
    procStateApproved: 'Approved',
    procStatePublished: 'Published',
    procGuideEmpty: 'No published procedures yet',
    procGuideEmptyBody: 'Procedures appear here after the operations office publishes them.',
    procGuideDeepLink: 'Open submission form',
    procGuideDeepLinkHelp: 'Submissions start from the guide to guarantee the latest published version is used.',
    procStepRequired: 'Complete every step key and type before submitting.',
    procDraft: 'Draft',
    procPending: 'Pending review',
    procReturned: 'Returned for correction',
    procApproved: 'Approved',
    procPublished: 'Published',
    procRuleSupervisorOfInitiator: "Initiator's direct supervisor",
    procRuleSupervisorOfStep: 'Previous step supervisor',
    procRuleRole: 'Organizational role',
    procAssignmentRuleStepKeyPlaceholder: 'Previous step key (e.g. supervisor_step)',
    procAssignmentRuleRolePlaceholder: 'Role code (e.g. hr_officer)',
    procAssignmentRuleTypePlaceholder: 'Pick an assignment rule',
    procStepTypePlaceholder: 'Pick a step type',
    procStepKeyPlaceholder: 'Short English key',
    procSubmitSuccess: 'The version was submitted for review.',
    procApprovalSuccess: 'The version was approved and is ready for publication.',
    procReturnSuccess: 'The version was returned to the author with the supplied reason.',
    procAuthoringLoading: 'Loading drafts…',
    procOfficeLoading: 'Loading pending versions…',
     procAuditTrail: 'Audit trail',
     procOfficeApprovedOnce: 'This version was approved once under bootstrap authority.',
     reqApprovalInbox: 'My approvals',
     reqApprovalInboxDescription: 'Only steps assigned to you and waiting for a decision.',
     reqMyRequests: 'My requests',
     reqMyRequestsDescription: 'Track every request you submitted, its owner, and its current state.',
     reqNewProcedureRequest: 'New procedure request',
     reqNewProcedureRequestDescription: 'Suggest a procedure for the Operations Office to review.',
     reqLoading: 'Loading requests…',
     reqDeniedTitle: 'You do not have access',
     reqDeniedBody: 'The server decides access to this route. Ask a platform administrator for the capability or return to your workspace.',
     reqError: 'We could not load the requests. Try again.',
     reqEmptyApprovals: 'No approvals are waiting for you',
     reqEmptyApprovalsBody: 'Steps owned by your account appear here when they are assigned to you.',
     reqEmptyRequests: 'No submitted requests yet',
     reqEmptyRequestsBody: 'A request path and its current owner will appear here after submission.',
     reqCurrentOwner: 'Current owner',
     reqStartedAt: 'Started',
     reqHistory: 'View step history',
     reqClose: 'Close',
     reqNoHistory: 'No step history is available yet.',
     reqReasonMin: 'Enter a reason of at least 10 characters when rejecting.',
     reqDecisionPending: 'Working…',
     reqRequestPrepared: 'The procedure draft was prepared.',
     reqApiUpdating: 'Request contract is updating',
      reqApiUpdatingBody: 'The Operations Office submission endpoint is not available yet. The form remains on screen and submission success was not reported.',
      detail: 'Details', backToList: 'Back to list', deepLink: 'Direct link', copyLink: 'Copy link', linkCopied: 'Link copied.', noDetails: 'No details are available for this record.', noComments: 'No comments yet.', comments: 'Comments', taskDetails: 'Task details', taskDescription: 'Description', taskActions: 'Allowed actions', reassign: 'Reassign', escalate: 'Escalate', returnForCorrection: 'Return for correction', returning: 'Returning…', escalating: 'Escalating…', reassigning: 'Reassigning…', actionReasonRequired: 'A reason is required for this action.', reassignmentTarget: 'Reassign to user ID', reassignmentTargetHelp: 'Enter the user ID that should receive this step.', reassignmentTargetRequired: 'A target user ID is required for reassignment.',
   },
}

export type ProcedureFieldType =
  | 'short_text'
  | 'long_text'
  | 'integer'
  | 'number'
  | 'date'
  | 'datetime'
  | 'boolean'
  | 'single_select'
  | 'multi_select'
  | 'attachment'

export const procedureFieldTypes: readonly ProcedureFieldType[] = [
  'short_text',
  'long_text',
  'integer',
  'number',
  'date',
  'datetime',
  'boolean',
  'single_select',
  'multi_select',
  'attachment',
]

export function fieldTypeLabel(locale: Locale, type: ProcedureFieldType): string {
  const labels: Record<Locale, Record<ProcedureFieldType, string>> = {
    ar: {
      short_text: 'نص قصير',
      long_text: 'نص طويل',
      integer: 'عدد صحيح',
      number: 'عدد عشري',
      date: 'تاريخ',
      datetime: 'تاريخ ووقت',
      boolean: 'نعم / لا',
      single_select: 'اختيار واحد',
      multi_select: 'اختيارات متعددة',
      attachment: 'مرفق',
    },
    en: {
      short_text: 'Short text',
      long_text: 'Long text',
      integer: 'Integer',
      number: 'Number',
      date: 'Date',
      datetime: 'Date and time',
      boolean: 'Yes / no',
      single_select: 'Single select',
      multi_select: 'Multi-select',
      attachment: 'Attachment',
    },
  }
  return labels[locale][type]
}

export function directionForWorkflow(locale: Locale): 'rtl' | 'ltr' {
  return locale === 'ar' ? 'rtl' : 'ltr'
}

export function formatAge(value: unknown, locale: Locale): string {
  const timestamp = typeof value === 'number'
    ? value
    : typeof value === 'string' && /^\d+$/.test(value)
      ? Number(value)
      : NaN
  const dateValue = Number.isFinite(timestamp) && timestamp > 0
    ? (timestamp < 10_000_000_000 ? timestamp * 1000 : timestamp)
    : typeof value === 'string'
      ? Date.parse(value)
      : NaN
  if (!Number.isFinite(dateValue)) return '—'

  const seconds = Math.round((Date.now() - dateValue) / 1000)
  const absolute = Math.abs(seconds)
  const [unit, divisor] = absolute >= 86_400
    ? ['day', 86_400]
    : absolute >= 3_600
      ? ['hour', 3_600]
      : absolute >= 60
        ? ['minute', 60]
        : ['second', 1]
  const amount = Math.max(0, Math.round(seconds / divisor))
  const unitMap: Record<string, Intl.RelativeTimeFormatUnit> = {
    day: 'day',
    hour: 'hour',
    minute: 'minute',
    second: 'second',
  }
  return new Intl.RelativeTimeFormat(locale === 'ar' ? 'ar-SA' : 'en-GB', { numeric: 'auto' })
    .format(-amount, unitMap[unit])
}

export function stringValue(value: unknown, fallback = '—'): string {
  return typeof value === 'string' && value.trim() ? value : fallback
}

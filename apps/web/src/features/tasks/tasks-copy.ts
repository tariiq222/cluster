import type { Locale } from '../../app/copy'

export type TaskStateCopy =
  | 'open'
  | 'inProgress'
  | 'blocked'
  | 'completed'
  | 'cancelled'

export type TaskPriorityCopy =
  | 'low'
  | 'normal'
  | 'high'
  | 'urgent'

export type TaskClassificationCopy =
  | 'public'
  | 'internal'
  | 'confidential'
  | 'restricted'

export type TaskRelationshipCopy =
  | 'all'
  | 'assigned'
  | 'created'
  | 'participating'

export type TaskActionCopy =
  | 'start'
  | 'block'
  | 'unblock'
  | 'complete'
  | 'cancel'
  | 'edit'
  | 'reassign'
  | 'addParticipant'
  | 'comment'
  | 'attachDocument'

export type TaskDialogCopy =
  | 'block'
  | 'unblock'
  | 'cancel'
  | 'complete'
  | 'reassign'

export type TasksCopy = {
  listTitle: string
  listDescription: string
  createTask: string
  refresh: string
  retry: string
  loading: string
  emptyTitle: string
  emptyBody: string
  forbiddenTitle: string
  forbiddenBody: string
  errorTitle: string
  retryLoading: string

  filterAll: string
  filterRelationshipLabel: string
  filterAssigned: string
  filterStateOpen: string
  filterStateBlocked: string
  filterStateCompleted: string
  filterStateCancelled: string
  filterStateAll: string
  filterStateInProgress: string

  columnTitle: string
  columnState: string
  columnPriority: string
  columnAssignee: string
  columnDueAt: string
  columnUpdatedAt: string
  columnAllowedActions: string

  priorityLow: string
  priorityNormal: string
  priorityHigh: string
  priorityUrgent: string

  classificationPublic: string
  classificationInternal: string
  classificationConfidential: string
  classificationRestricted: string

  stateOpen: string
  stateInProgress: string
  stateBlocked: string
  stateCompleted: string
  stateCancelled: string

  actionStart: string
  actionBlock: string
  actionUnblock: string
  actionComplete: string
  actionCancel: string
  actionEdit: string
  actionReassign: string
  actionAddParticipant: string
  actionComment: string
  actionAttachDocument: string
  actionOpen: string

  detailTitle: string
  detailDescription: string
  detailState: string
  detailPriority: string
  detailAssignee: string
  detailCreator: string
  detailParticipants: string
  detailDueAt: string
  detailAllowedActions: string
  detailAttachments: string
  detailNoAttachments: string
  detailAddParticipant: string
  detailAttachDocument: string
  detailNoComments: string
  detailAddComment: string
  detailCommentPlaceholder: string
  detailCommentSubmit: string
  detailNoDescription: string
  detailForbidden: string
  detailNotFound: string
  detailForbiddenBody: string
  detailNotFoundBody: string

  dialogBlockTitle: string
  dialogBlockDescription: string
  dialogBlockReasonLabel: string
  dialogBlockReasonPlaceholder: string
  dialogBlockReasonRequired: string
  dialogBlockConfirm: string

  dialogUnblockTitle: string
  dialogUnblockDescription: string
  dialogUnblockConfirm: string

  dialogCancelTitle: string
  dialogCancelDescription: string
  dialogCancelReasonLabel: string
  dialogCancelReasonPlaceholder: string
  dialogCancelReasonRequired: string
  dialogCancelConfirm: string

  dialogCompleteTitle: string
  dialogCompleteDescription: string
  dialogCompleteNoteLabel: string
  dialogCompleteNotePlaceholder: string
  dialogCompleteNoteRequired: string
  dialogCompleteConfirm: string

  dialogReassignTitle: string
  dialogReassignDescription: string
  dialogReassignTargetLabel: string
  dialogReassignTargetPlaceholder: string
  dialogReassignTargetRequired: string
  dialogReassignConfirm: string

  dialogSubmit: string
  dialogCancel: string

  createTitle: string
  createDescription: string
  createTitleLabel: string
  createTitlePlaceholder: string
  createTitleRequired: string
  createDescriptionLabel: string
  createDescriptionPlaceholder: string
  createAssigneeLabel: string
  createAssigneePlaceholder: string
  createPriorityLabel: string
  createDueAtLabel: string
  createClassificationLabel: string
  createParticipantsLabel: string
  createParticipantsHelp: string
  createSubmit: string
  createSubmitting: string
  createSuccess: string
  createTeamScopeError: string
  createAssigneeHelp: string

  apiError: string
  validationError: string
  loadingTasks: string
}

export const tasksCopy: Record<Locale, TasksCopy> = {
  ar: {
    listTitle: 'مهامي',
    listDescription: 'تصفح مهامك وفق الحالة أو علاقتك بها.',
    createTask: 'إنشاء مهمة',
    refresh: 'تحديث',
    retry: 'إعادة المحاولة',
    loading: 'جارٍ تحميل المهام…',
    emptyTitle: 'لا توجد مهام',
    emptyBody: 'ستظهر هنا كل المهام المسندة إليك أو التي أنشأتها أو تشارك فيها.',
    forbiddenTitle: 'لا تملك صلاحية الوصول',
    forbiddenBody: 'تحتاج صلاحية قراءة المهام لعرض هذه الصفحة.',
    errorTitle: 'تعذر تحميل المهام',
    retryLoading: 'جارٍ إعادة المحاولة…',

    filterAll: 'الكل',
    filterRelationshipLabel: 'تصفية بحسب العلاقة',
    filterAssigned: 'مسندة إليّ',
    filterCreated: 'أنشأتها',
    filterParticipating: 'أشارك فيها',
    filterStateOpen: 'مفتوحة',
    filterStateBlocked: 'محجوبة',
    filterStateCompleted: 'مكتملة',
    filterStateCancelled: 'ملغاة',
    filterStateAll: 'كل الحالات',
    filterStateInProgress: 'قيد التنفيذ',

    columnTitle: 'العنوان',
    columnState: 'الحالة',
    columnPriority: 'الأولوية',
    columnAssignee: 'المسند إليه',
    columnDueAt: 'تاريخ الاستحقاق',
    columnUpdatedAt: 'آخر تحديث',
    columnAllowedActions: 'الإجراءات المتاحة',

    priorityLow: 'منخفضة',
    priorityNormal: 'عادية',
    priorityHigh: 'عالية',
    priorityUrgent: 'عاجلة',

    classificationPublic: 'عام',
    classificationInternal: 'داخلي',
    classificationConfidential: 'سري',
    classificationRestricted: 'مقيّد',

    stateOpen: 'مفتوحة',
    stateInProgress: 'قيد التنفيذ',
    stateBlocked: 'محجوبة',
    stateCompleted: 'مكتملة',
    stateCancelled: 'ملغاة',

    actionStart: 'بدء',
    actionBlock: 'حجب',
    actionUnblock: 'رفع الحجب',
    actionComplete: 'إكمال',
    actionCancel: 'إلغاء',
    actionEdit: 'تعديل',
    actionReassign: 'إعادة إسناد',
    actionAddParticipant: 'إضافة مشارك',
    actionComment: 'تعليق',
    actionAttachDocument: 'إرفاق مستند',
    actionOpen: 'فتح',

    detailTitle: 'تفاصيل المهمة',
    detailDescription: 'الوصف',
    detailState: 'الحالة',
    detailPriority: 'الأولوية',
    detailAssignee: 'المسند إليه',
    detailCreator: 'المنشئ',
    detailParticipants: 'المشاركون',
    detailDueAt: 'تاريخ الاستحقاق',
    detailAllowedActions: 'الإجراءات المتاحة',
    detailAttachments: 'المرفقات',
    detailNoAttachments: 'لا توجد مرفقات.',
    detailAddParticipant: 'إضافة مشارك',
    detailAttachDocument: 'إرفاق مستند',
    detailNoComments: 'لا توجد تعليقات بعد.',
    detailAddComment: 'إضافة تعليق',
    detailCommentPlaceholder: 'اكتب تعليقاً…',
    detailCommentSubmit: 'إرسال التعليق',
    detailNoDescription: 'لا يوجد وصف.',
    detailForbidden: 'ممنوع',
    detailNotFound: 'غير موجود',
    detailForbiddenBody: 'لا تملك صلاحية قراءة هذه المهمة.',
    detailNotFoundBody: 'المهمة غير متاحة أو أُزيلت.',

    dialogBlockTitle: 'حجب المهمة',
    dialogBlockDescription: 'اشرح سبب الحجب ليتسنى للمعنيين متابعته.',
    dialogBlockReasonLabel: 'سبب الحجب',
    dialogBlockReasonPlaceholder: 'مثال: انتظار مراجعة من المورد البشري',
    dialogBlockReasonRequired: 'سبب الحجب مطلوب.',
    dialogBlockConfirm: 'تأكيد الحجب',

    dialogUnblockTitle: 'رفع الحجب عن المهمة',
    dialogUnblockDescription: 'سيُعاد فتح المهمة لتعود إلى قيد التنفيذ.',
    dialogUnblockConfirm: 'تأكيد رفع الحجب',

    dialogCancelTitle: 'إلغاء المهمة',
    dialogCancelDescription: 'لا يمكن التراجع عن الإلغاء. اكتب السبب لتوثيق القرار.',
    dialogCancelReasonLabel: 'سبب الإلغاء',
    dialogCancelReasonPlaceholder: 'مثال: ألغتها الأولويات المتغيرة',
    dialogCancelReasonRequired: 'سبب الإلغاء مطلوب.',
    dialogCancelConfirm: 'تأكيد الإلغاء',

    dialogCompleteTitle: 'إكمال المهمة',
    dialogCompleteDescription: 'سيُسجَّل الإكمال بعد إضافة ملاحظة قصيرة.',
    dialogCompleteNoteLabel: 'ملاحظة الإكمال',
    dialogCompleteNotePlaceholder: 'مثال: تم التسليم للعميل مع الملاحظات',
    dialogCompleteNoteRequired: 'ملاحظة الإكمال مطلوبة.',
    dialogCompleteConfirm: 'تأكيد الإكمال',

    dialogReassignTitle: 'إعادة إسناد المهمة',
    dialogReassignDescription: 'أدخل معرّف المستخدم داخل فريقك الإداري.',
    dialogReassignTargetLabel: 'معرّف المستخدم الجديد',
    dialogReassignTargetPlaceholder: 'مثال: 018f3a1c-…',
    dialogReassignTargetRequired: 'معرّف المستخدم الجديد مطلوب.',
    dialogReassignConfirm: 'تأكيد الإعادة',

    dialogSubmit: 'تنفيذ',
    dialogCancel: 'إلغاء',

    createTitle: 'إنشاء مهمة',
    createDescription: 'أدخل البيانات الأساسية للمهمة وعيّنها لمستخدم أو لفريقك.',
    createTitleLabel: 'عنوان المهمة',
    createTitlePlaceholder: 'مثال: تجهيز تقرير السفر للعميل',
    createTitleRequired: 'عنوان المهمة مطلوب.',
    createDescriptionLabel: 'الوصف',
    createDescriptionPlaceholder: 'اشرح ما يجب إنجازه والمعايير.',
    createAssigneeLabel: 'المسند إليه',
    createAssigneePlaceholder: 'اتركه فارغاً لإسنادها إلى نفسك',
    createPriorityLabel: 'الأولوية',
    createDueAtLabel: 'تاريخ الاستحقاق',
    createClassificationLabel: 'التصنيف',
    createParticipantsLabel: 'معرّفات المشاركين',
    createParticipantsHelp: 'افصل بين المعرّفات بفواصل.',
    createSubmit: 'إنشاء المهمة',
    createSubmitting: 'جارٍ الإنشاء…',
    createSuccess: 'أُنشئت المهمة.',
    createTeamScopeError: 'يجب أن يكون المسند إليه ضمن فريقك الإداري.',
    createAssigneeHelp: 'اتركه فارغاً لإسنادها إلى نفسك، أو أدخل معرّف عضو فريق.',

    apiError: 'تعذر إكمال الطلب. حاول مرة أخرى.',
    validationError: 'تحقق من الحقول المطلوبة.',
    loadingTasks: 'جارٍ تحميل المهام…',
  },
  en: {
    listTitle: 'My tasks',
    listDescription: 'Browse your tasks by state or relationship.',
    createTask: 'Create task',
    refresh: 'Refresh',
    retry: 'Try again',
    loading: 'Loading tasks…',
    emptyTitle: 'No tasks yet',
    emptyBody: 'Any task assigned to you, created by you, or with you as a participant will appear here.',
    forbiddenTitle: 'You do not have access',
    forbiddenBody: 'You need the tasks.read capability to view this page.',
    errorTitle: 'Could not load tasks',
    retryLoading: 'Retrying…',

    filterAll: 'All',
    filterRelationshipLabel: 'Filter by relationship',
    filterAssigned: 'Assigned to me',
    filterParticipating: 'I participate',
    filterStateOpen: 'Open',
    filterStateBlocked: 'Blocked',
    filterStateCompleted: 'Completed',
    filterStateCancelled: 'Cancelled',
    filterStateAll: 'All states',
    filterStateInProgress: 'In progress',

    columnTitle: 'Title',
    columnState: 'State',
    columnPriority: 'Priority',
    columnAssignee: 'Assignee',
    columnDueAt: 'Due at',
    columnUpdatedAt: 'Updated at',
    columnAllowedActions: 'Allowed actions',

    priorityLow: 'Low',
    priorityNormal: 'Normal',
    priorityHigh: 'High',
    priorityUrgent: 'Urgent',

    classificationPublic: 'Public',
    classificationInternal: 'Internal',
    classificationConfidential: 'Confidential',
    classificationRestricted: 'Restricted',

    stateOpen: 'Open',
    stateInProgress: 'In progress',
    stateBlocked: 'Blocked',
    stateCompleted: 'Completed',
    stateCancelled: 'Cancelled',

    actionStart: 'Start',
    actionBlock: 'Block',
    actionUnblock: 'Unblock',
    actionComplete: 'Complete',
    actionCancel: 'Cancel',
    actionEdit: 'Edit',
    actionReassign: 'Reassign',
    actionAddParticipant: 'Add participant',
    actionComment: 'Comment',
    actionAttachDocument: 'Attach document',
    actionOpen: 'Open',

    detailTitle: 'Task details',
    detailDescription: 'Description',
    detailState: 'State',
    detailPriority: 'Priority',
    detailAssignee: 'Assignee',
    detailCreator: 'Creator',
    detailParticipants: 'Participants',
    detailDueAt: 'Due at',
    detailAllowedActions: 'Allowed actions',
    detailAttachments: 'Attachments',
    detailNoAttachments: 'No attachments yet.',
    detailAddParticipant: 'Add participant',
    detailAttachDocument: 'Attach document',
    detailNoComments: 'No comments yet.',
    detailAddComment: 'Add a comment',
    detailCommentPlaceholder: 'Write a comment…',
    detailCommentSubmit: 'Post comment',
    detailNoDescription: 'No description provided.',
    detailForbidden: 'Forbidden',
    detailNotFound: 'Not found',
    detailForbiddenBody: 'You do not have permission to read this task.',
    detailNotFoundBody: 'This task is no longer available or has been removed.',

    dialogBlockTitle: 'Block task',
    dialogBlockDescription: 'Provide a reason so stakeholders can follow up.',
    dialogBlockReasonLabel: 'Block reason',
    dialogBlockReasonPlaceholder: 'e.g. Awaiting HR review',
    dialogBlockReasonRequired: 'A block reason is required.',
    dialogBlockConfirm: 'Confirm block',

    dialogUnblockTitle: 'Unblock task',
    dialogUnblockDescription: 'The task will return to the in-progress state.',
    dialogUnblockConfirm: 'Confirm unblock',

    dialogCancelTitle: 'Cancel task',
    dialogCancelDescription: 'Cancellation cannot be undone. Capture the reason for the audit log.',
    dialogCancelReasonLabel: 'Cancellation reason',
    dialogCancelReasonPlaceholder: 'e.g. Cancelled due to shifting priorities',
    dialogCancelReasonRequired: 'A cancellation reason is required.',
    dialogCancelConfirm: 'Confirm cancellation',

    dialogCompleteTitle: 'Complete task',
    dialogCompleteDescription: 'A short completion note will be recorded.',
    dialogCompleteNoteLabel: 'Completion note',
    dialogCompleteNotePlaceholder: 'e.g. Delivered to client with feedback',
    dialogCompleteNoteRequired: 'A completion note is required.',
    dialogCompleteConfirm: 'Confirm completion',

    dialogReassignTitle: 'Reassign task',
    dialogReassignDescription: 'Enter a user id within your manageable team.',
    dialogReassignTargetLabel: 'New assignee user id',
    dialogReassignTargetPlaceholder: 'e.g. 018f3a1c-…',
    dialogReassignTargetRequired: 'A new assignee user id is required.',
    dialogReassignConfirm: 'Confirm reassignment',

    dialogSubmit: 'Apply',
    dialogCancel: 'Cancel',

    createTitle: 'Create task',
    createDescription: 'Provide the task basics and assign it to a user or your team.',
    createTitleLabel: 'Task title',
    createTitlePlaceholder: 'e.g. Prepare client travel report',
    createTitleRequired: 'Task title is required.',
    createDescriptionLabel: 'Description',
    createDescriptionPlaceholder: 'Describe the outcome and acceptance criteria.',
    createAssigneeLabel: 'Assignee',
    createAssigneePlaceholder: 'Leave empty to assign yourself',
    createPriorityLabel: 'Priority',
    createDueAtLabel: 'Due at',
    createClassificationLabel: 'Classification',
    createParticipantsLabel: 'Participant user ids',
    createParticipantsHelp: 'Separate user ids with commas.',
    createSubmit: 'Create task',
    createSubmitting: 'Creating…',
    createSuccess: 'The task was created.',
    createTeamScopeError: 'Assignee must belong to a team you manage.',
    createAssigneeHelp: 'Leave empty to assign yourself, or enter a team member id.',

    apiError: 'Could not complete the request. Try again.',
    validationError: 'Please check the required fields.',
    loadingTasks: 'Loading tasks…',
  },
}

"""S6 Arabic overlay translator.

Provides a deterministic pure function `translate_card` that returns a 1-sentence
Arabic summary for one endpoint card, derived from its HTTP method, URL path,
and controller class.

Design goals:
- **Deterministic** — re-running on the same inputs yields the same Arabic string.
- **Idempotent at the call site** — the caller already replaces `{{AR:op_key}}`
  placeholders exactly once; this module never inspects prior output.
- **Path + controller aware** — entity noun comes from the controller's short
  class name; action qualifiers come from the path's last meaningful segment.
- **Method-driven verb** — GET → تسترجع, POST → تنشئ, PATCH/PUT → تعدّل,
  DELETE → تحذف.
"""

from __future__ import annotations

from typing import Optional


# HTTP method → Arabic present-tense verb (third person feminine, agreeing
# with the entity noun that follows).
ACTION_VERB = {
    "GET": "تسترجع",
    "POST": "تنشئ",
    "PATCH": "تعدّل",
    "PUT": "تستبدل",
    "DELETE": "تحذف",
}


# English entity names → Arabic noun (definite, singular). Order matters in the
# resolver: longest prefix wins so "WorkDefinition" matches before "Work".
ENTITY_TRANSLATIONS = {
    # Documents
    "DocumentUploadStatus": "حالة رفع المستند",
    "DocumentUpload": "رفع المستند",
    "DocumentGrant": "منحة المستند",
    "DocumentVersion": "إصدار المستند",
    "Document": "المستند",
    # Authorization
    "AccessDecision": "قرار الوصول",
    "AdminResource": "مورد إداري",
    "AuthorizationBootstrap": "تهيئة التفويض",
    "AuthorizationAdmin": "إدارة التفويض",
    "Authorization": "التفويض",
    # Identity
    "DevelopmentFixture": "بيئة التطوير",
    "UserAccount": "حساب المستخدم",
    "CurrentPrincipal": "المستخدم الحالي",
    "CurrentIdentity": "الهوية الحالية",
    "MyScope": "نطاق المستخدم الحالي",
    "IdentityCsrf": "رمز CSRF للهوية",
    "Activation": "التفعيل",
    "Password": "كلمة المرور",
    "Identity": "الهوية",
    "Scope": "النطاقات المتاحة",
    # Notifications
    "Notification": "الإشعار",
    # Organization
    "TemporaryAssignment": "التكليف المؤقت",
    "SupervisoryRelationship": "العلاقة الإشرافية",
    "ImportJobRow": "صفوف مهمة الاستيراد",
    "ImportJob": "مهمة الاستيراد",
    "JobTitle": "المسمى الوظيفي",
    "OrganizationUnit": "الوحدة التنظيمية",
    "Assignment": "التكليف",
    "PersonReference": "مرجع الشخص",
    "Cluster": "التجمع الصحي",
    "Facility": "المنشأة",
    "Position": "المنصب",
    "Person": "الشخص",
    "Organization": "المؤسسة",
    # Reporting
    "ReportExport": "تصدير تقرير",
    "Report": "التقرير",
    "Dashboard": "لوحة المعلومات",
    "Export": "ملف التصدير",
    # Search
    "Search": "البحث في السجلات",
    # Tasks / engagement
    "TaskEngagement": "مشاركة المهمة",
    "Comment": "تعليق على المهمة",
    "Task": "المهمة",
    # Workflow
    "WorkflowLifecycle": "دورة حياة سير العمل",
    "WorkflowDefinition": "تعريف سير العمل",
    "WorkflowInstance": "نسخة سير العمل",
    "WorkflowVersion": "إصدار سير العمل",
    "WorkflowStepDecision": "قرار خطوة سير العمل",
    "WorkflowStepAction": "إجراء خطوة سير العمل",
    "Workflow": "سير العمل",
    # Work definitions / records
    "WorkDefinition": "تعريف العمل",
    "AuthorizedWorkRecord": "سجل العمل المُصرَّح به",
    "SubmitWorkRecord": "تسجيل سجل عمل",
    "WorkRecordLifecycle": "دورة حياة سجل العمل",
    "WorkRecord": "سجل العمل",
    "LinkDocument": "ربط المستند",
}


# Last path segment qualifiers that introduce a different Arabic phrase than
# the bare noun (e.g. /download → "تنزيل المستند", /explanation → "شرح ...").
PATH_TAIL_OVERRIDES = {
    "explanation": "شرح قرار الوصول",
    "download": "تنزيل ملف",
    "complete": "اكتمال",
    "read": "تأكيد قراءة إشعار",
    "revoke": "سحب تكليف مؤقت",
    "end": "إنهاء تكليف",
    "reorder": "إعادة ترتيب الوحدات التنظيمية",
    "reconcile-promotion": "مطابقة ترقية إصدار المستند",
    "scan": "مسح إصدار المستند",
    "from-step": "إنشاء مهمة من خطوة",
    "decisions": "قرار خطوة",
    "cancel": "إلغاء نسخة سير العمل",
}


# Substring in path → special Arabic qualifier override. Evaluated in order;
# first match wins.
PATH_QUALIFIER_OVERRIDES = [
    ("/{adminResource}/{resourceId}/{authorizationAction}", "إجراء تفويض إداري"),
    ("/{adminResource}/{resourceId}", "مورد إداري محدد"),
    ("/{adminResource}", "مورد إداري"),
    ("/uploads/{uploadId}/complete", "اكتمال رفع المستند"),
    ("/uploads/{uploadId}", "حالة رفع المستند"),
    ("/uploads", "بدء رفع المستند"),
    ("/{documentId}/download", "تنزيل المستند"),
    ("/{documentId}/versions", "إصدارات المستند"),
    ("/{documentId}/{documentGrantType}-grant", "منحة على المستند"),
    ("/{documentId}/{documentAction}", "إجراء على المستند"),
    ("/{documentId}", "تفاصيل المستند"),
    ("/{documentId}/documents", "ربط مستند بسجل العمل"),
    ("/bootstrap/complete", "اكتمال تهيئة التفويض"),
    ("/bootstrap", "تهيئة التفويض"),
    ("/access-decisions/{decisionId}/explanation", "شرح قرار الوصول"),
    ("/access-decisions", "قرار الوصول"),
    ("/internal/documents/versions/{versionId}", "إصدار مستند داخلي"),
    ("/notifications/{notificationId}/read", "تأكيد قراءة إشعار"),
    ("/notifications", "الإشعارات"),
    ("/temporary-assignments/{temporaryAssignmentId}/revoke", "سحب تكليف مؤقت"),
    ("/temporary-assignments/{temporaryAssignmentId}", "تفاصيل تكليف مؤقت"),
    ("/temporary-assignments", "قائمة التكليف المؤقت"),
    ("/import-jobs/{jobId}/rows", "صفوف مهمة الاستيراد"),
    ("/import-jobs/{jobId}/{jobAction}", "إجراء على مهمة الاستيراد"),
    ("/import-jobs/{jobId}", "تفاصيل مهمة الاستيراد"),
    ("/import-jobs", "مهمة استيراد"),
    ("/supervisory-relationships", "العلاقات الإشرافية"),
    ("/units/reorder", "إعادة ترتيب الوحدات التنظيمية"),
    ("/units/{unitId}", "تفاصيل وحدة تنظيمية"),
    ("/units", "الوحدات التنظيمية"),
    ("/facilities/{facilityId}", "تفاصيل منشأة"),
    ("/facilities", "المنشآت"),
    ("/job-titles", "المسميات الوظيفية"),
    ("/positions/{positionId}", "تفاصيل منصب"),
    ("/positions", "المناصب"),
    ("/people/{personId}/reference", "مرجع الشخص"),
    ("/people/{personId}", "تفاصيل شخص"),
    ("/people", "الأشخاص"),
    ("/assignments/{assignmentId}/end", "إنهاء تكليف"),
    ("/assignments", "التكليفات"),
    ("/accounts/{accountId}/{accountAction}", "إجراء على حساب المستخدم"),
    ("/accounts/{accountId}/activation", "إصدار تفعيل الحساب"),
    ("/accounts/{accountId}", "تفاصيل حساب المستخدم"),
    ("/accounts", "حسابات المستخدمين"),
    ("/scopes", "النطاقات المتاحة"),
    ("/scope", "اختيار نطاق المستخدم"),
    ("/me", "المستخدم الحالي"),
    ("/password", "تغيير كلمة المرور"),
    ("/logout", "تسجيل الخروج"),
    ("/login", "تسجيل الدخول"),
    ("/activation", "تفعيل الحساب"),
    ("/csrf", "رمز CSRF للهوية"),
    ("/cluster", "التجمع الصحي"),
    ("/reports/{reportId}/exports", "إنشاء تصدير تقرير"),
    ("/reports/{reportId}", "تفاصيل تقرير"),
    ("/reports", "قائمة التقارير"),
    ("/dashboards/{dashboardId}", "تفاصيل لوحة معلومات"),
    ("/dashboards", "لوحات المعلومات"),
    ("/exports/{exportId}", "تنزيل ملف التصدير"),
    ("/search", "البحث في السجلات"),
    ("/tasks/{taskId}/comments", "تعليقات المهمة"),
    ("/tasks/{taskId}/participants", "مشاركو المهمة"),
    ("/tasks/{taskId}/{workflowTaskAction}", "إجراء على المهمة"),
    ("/tasks/{taskId}", "تفاصيل المهمة"),
    ("/tasks/from-step/{stepId}", "إنشاء مهمة من خطوة"),
    ("/tasks", "المهام"),
    ("/work-definition-versions/{versionId}/{versionAction}", "إجراء على إصدار تعريف عمل"),
    ("/work-definition-versions/{versionId}", "تفاصيل إصدار تعريف عمل"),
    ("/work-definitions/{definitionId}/versions", "إصدارات تعريف العمل"),
    ("/work-definitions/{definitionId}", "تفاصيل تعريف عمل"),
    ("/work-definitions", "تعريفات العمل"),
    ("/work-records/{recordId}/{recordAction}", "إجراء على سجل العمل"),
    ("/work-records/{recordId}/documents", "ربط مستند بسجل العمل"),
    ("/work-records/{recordId}", "تفاصيل سجل العمل"),
    ("/work-records", "سجلات العمل"),
    ("/workflow/definitions/{definitionId}/versions", "إصدارات تعريف سير العمل"),
    ("/workflow/definitions/{definitionId}", "تفاصيل تعريف سير عمل"),
    ("/workflow/definitions", "تعريفات سير العمل"),
    ("/workflow/versions/{versionId}/{workflowLifecycleAction}", "إجراء على إصدار سير العمل"),
    ("/workflow/instances/{instanceId}/cancel", "إلغاء نسخة سير العمل"),
    ("/workflow/instances/{instanceId}", "تفاصيل نسخة سير عمل"),
    ("/workflow/instances", "نسخ سير العمل"),
    ("/workflow/steps/{stepId}/decisions", "قرار على خطوة سير العمل"),
    ("/workflow/steps/{stepId}/{stepAction}", "إجراء على خطوة سير العمل"),
]


def _short_class_name(controller_fqcn: str) -> str:
    """Return `GetDocumentUploadStatus` from
    `App\\Http\\Controllers\\Documents\\GetDocumentUploadStatusController`.

    Strips trailing `Controller`, the FQCN namespace, and any `::method` suffix.
    """
    head = controller_fqcn.split("::", 1)[0]
    head = head.rsplit("\\", 1)[-1]
    if head.endswith("Controller"):
        head = head[: -len("Controller")]
    return head


def _resolve_entity(short_name: str) -> str:
    """Map a controller short class name to an Arabic entity noun."""
    for key in sorted(ENTITY_TRANSLATIONS, key=len, reverse=True):
        if short_name == key or short_name.startswith(key):
            return ENTITY_TRANSLATIONS[key]
    return "العنصر"


def _normalize_path_for_match(path: str) -> str:
    return path.rstrip("/") or "/"


def _path_qualifier(path: str, short_name: str) -> Optional[str]:
    """Return a specialized Arabic qualifier for the given path, if any."""
    normalized = _normalize_path_for_match(path)
    for template, arabic in PATH_QUALIFIER_OVERRIDES:
        if normalized.endswith(template):
            return arabic
    # Fall back to controller-driven translation.
    return None


def _tail_action(path: str) -> Optional[str]:
    """Specialty qualifiers keyed on the last meaningful path segment."""
    tail = path.rstrip("/").rsplit("/", 1)[-1]
    if tail in PATH_TAIL_OVERRIDES:
        return PATH_TAIL_OVERRIDES[tail]
    return None


def translate_card(
    method: str,
    path: str,
    controller_fqcn: str,
    controller_method: Optional[str] = None,
) -> str:
    """Return a 1-sentence Arabic summary for the given endpoint card.

    The result is always a single short Arabic sentence ending with a period.
    """
    verb = ACTION_VERB.get(method.upper(), "تنفذ")
    short_name = _short_class_name(controller_fqcn)

    qualifier = _path_qualifier(path, short_name)
    if qualifier is None:
        qualifier = _tail_action(path)

    if qualifier is not None:
        qualifier = f"{qualifier}."

        # Verb agreement: when the qualifier starts with a verb in past tense
        # (e.g. "إلغاء", "إنشاء"), drop the present-tense prefix verb.
        past_tense_leads = ("إلغاء", "إنشاء", "إنهاء", "سحب", "اكتمال", "تأكيد", "تغيير", "بدء", "مسح", "مطابقة")
        if any(qualifier.startswith(lead) for lead in past_tense_leads):
            return qualifier

        return f"{verb} {qualifier}"

    entity = _resolve_entity(short_name)
    return f"{verb} {entity}."

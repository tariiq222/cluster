import { useEffect, useState } from 'react'
import { Check, CircleHelp, Copy, LifeBuoy, X } from 'lucide-react'
import type { Locale } from '@/i18n'
import type { ScreenHelp } from '@/app/screen-help'
import { Button } from '@/components/ui/button'
import {
  Sheet,
  SheetClose,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
} from '@/components/ui/sheet'
import { SidebarMenuButton, useSidebar } from '@/components/ui/sidebar'

const helpCopy = {
  ar: {
    trigger: 'المساعدة',
    close: 'إغلاق المساعدة',
    scope: 'النطاق الحالي',
    currentState: 'الحالة الحالية',
    activeSection: 'القسم الحالي',
    next: 'ما الذي يمكنك فعله هنا',
    recovery: 'خطوات الاستعادة',
    supportTitle: 'الدعم الفني',
    supportBody:
      'إذا استمرت المشكلة، تواصل مع مكتب دعم تقنية المعلومات المعتمد في تجمعك الصحي واشرح الإجراء الذي كنت تنفذه.',
    supportBodyWithId:
      'إذا استمرت المشكلة، تواصل مع مكتب الدعم المعتمد وأرفق معرّف الارتباط التالي لتسريع التشخيص.',
    correlationId: 'معرّف الارتباط',
    copyCorrelationId: 'نسخ معرّف الارتباط',
    copied: 'تم نسخ معرّف الارتباط.',
    copyFailed: 'تعذر نسخ معرّف الارتباط. حدده وانسخه يدويًا.',
    topics: {
      home: {
        title: 'مساعدة مساحة العمليات',
        description: 'راجع حالة العمل والنطاق الفعّال قبل بدء أي إجراء.',
        steps: [
          'ابدأ بالعناصر التي تتطلب انتباهك.',
          'تحقق من النطاق قبل فتح سجل أو مهمة.',
        ],
      },
      tasks: {
        title: 'مساعدة المهام',
        description: 'تابع المسؤولية والحالة والموعد النهائي من سياق واحد.',
        steps: [
          'راجع المكلّفين والمشاركين.',
          'حدّث الحالة وأضف تعليقًا يوضح القرار التالي.',
        ],
      },
      documents: {
        title: 'مساعدة المستندات',
        description: 'تحقق من الإصدار والوصول قبل مشاركة مستند أو تحديثه.',
        steps: [
          'راجع الإصدار الحالي.',
          'تأكد من صلاحيات الوصول قبل إنشاء رابط.',
        ],
      },
      organization: {
        title: 'مساعدة المنظمة',
        description: 'أدر الهيكل التنظيمي ضمن النطاق والصلاحيات الفعّالة.',
        steps: [
          'اختر القسم المناسب قبل التعديل.',
          'راجع أثر التغيير قبل الحفظ أو الاستيراد.',
        ],
      },
      access: {
        title: 'مساعدة الحسابات والصلاحيات',
        description: 'راجع الحساب والدور والنطاق معًا قبل تغيير الوصول.',
        steps: [
          'ابدأ بالحساب أو الدور المطلوب.',
          'تحقق من النطاق وتعارضات الصلاحيات قبل الحفظ.',
        ],
      },
      reports: {
        title: 'مساعدة التقارير والمراقبة',
        description:
          'استخدم التقارير للتتبع، ثم انتقل إلى المصدر للتحقق من التفاصيل.',
        steps: [
          'حدد الفترة والنطاق.',
          'احتفظ بمعرّف الارتباط عند متابعة خطأ أو حدث تدقيق.',
        ],
      },
      platform: {
        title: 'مساعدة إدارة المنصة',
        description: 'نفّذ تغييرات المنصة بعد التحقق من الصحة والنطاق والأثر.',
        steps: [
          'راجع الحالة الحالية قبل التغيير.',
          'استخدم إجراءات الاستعادة والصيانة وفق نافذة تشغيل معتمدة.',
        ],
      },
      search: {
        title: 'مساعدة البحث',
        description: 'تعرض النتائج فقط المحتوى المسموح به ضمن نطاقك الحالي.',
        steps: [
          'استخدم كلمات دقيقة من العنوان أو المعرّف.',
          'غيّر النطاق إذا كنت تتوقع نتيجة غير ظاهرة.',
        ],
      },
      account: {
        title: 'مساعدة الحساب والسياق',
        description: 'راجع أمان حسابك والنطاق الذي تعمل من خلاله.',
        steps: [
          'تحقق من إعدادات الأمان.',
          'بدّل النطاق قبل العودة إلى العمل الإداري.',
        ],
      },
      notifications: {
        title: 'مساعدة الإشعارات',
        description: 'استخدم الإشعارات للعودة إلى العمل الذي يحتاج انتباهك.',
        steps: [
          'افتح الإشعار لمراجعة مصدره وسياقه.',
          'ارجع إلى السجل المرتبط لإكمال الإجراء المطلوب.',
        ],
      },
    },
  },
  en: {
    trigger: 'Help',
    close: 'Close help',
    scope: 'Current scope',
    currentState: 'Current state',
    activeSection: 'Current section',
    next: 'What you can do here',
    recovery: 'Recovery steps',
    supportTitle: 'Technical support',
    supportBody:
      "If the problem continues, contact your health cluster's approved IT service desk and describe the action you were taking.",
    supportBodyWithId:
      'If the problem continues, contact the approved service desk and include the following correlation ID to speed up diagnosis.',
    correlationId: 'Correlation ID',
    copyCorrelationId: 'Copy correlation ID',
    copied: 'Correlation ID copied.',
    copyFailed: 'Could not copy the correlation ID. Select and copy it manually.',
    topics: {
      home: {
        title: 'Operations workspace help',
        description:
          'Review work status and effective scope before taking action.',
        steps: [
          'Start with items that need attention.',
          'Confirm scope before opening a record or task.',
        ],
      },
      tasks: {
        title: 'Tasks help',
        description: 'Track ownership, status, and due dates in one context.',
        steps: [
          'Review assignees and participants.',
          'Update status and leave a comment that explains the next decision.',
        ],
      },
      documents: {
        title: 'Documents help',
        description:
          'Confirm version and access before sharing or updating a document.',
        steps: [
          'Review the current version.',
          'Check access permissions before creating a link.',
        ],
      },
      organization: {
        title: 'Organization help',
        description:
          'Manage structure within your effective scope and permissions.',
        steps: [
          'Choose the relevant section before editing.',
          'Review impact before saving or importing.',
        ],
      },
      access: {
        title: 'Accounts and permissions help',
        description:
          'Review account, role, and scope together before changing access.',
        steps: [
          'Start with the account or role.',
          'Check scope and permission conflicts before saving.',
        ],
      },
      reports: {
        title: 'Reports and monitoring help',
        description:
          'Use reports to identify activity, then verify details at the source.',
        steps: [
          'Set the time period and scope.',
          'Keep the correlation ID when investigating an error or audit event.',
        ],
      },
      platform: {
        title: 'Platform management help',
        description:
          'Make platform changes only after checking health, scope, and impact.',
        steps: [
          'Review current status before changing it.',
          'Use restore and maintenance actions within an approved operating window.',
        ],
      },
      search: {
        title: 'Search help',
        description:
          'Results include only content allowed in your current scope.',
        steps: [
          'Use precise words from the title or identifier.',
          'Change scope if an expected result is not visible.',
        ],
      },
      account: {
        title: 'Account and context help',
        description:
          'Review account security and the scope you are acting through.',
        steps: [
          'Check your security settings.',
          'Switch scope before returning to administrative work.',
        ],
      },
      notifications: {
        title: 'Notifications help',
        description: 'Use notifications to return to work that needs attention.',
        steps: [
          'Open a notification to review its source and context.',
          'Return to the linked record to complete the required action.',
        ],
      },
    },
  },
} as const

type TopicKey = keyof (typeof helpCopy)['en']['topics']

function topicForPath(pathname: string): TopicKey {
  if (pathname.startsWith('/tasks')) return 'tasks'
  if (pathname.startsWith('/documents')) return 'documents'
  if (pathname.startsWith('/organization')) return 'organization'
  if (pathname.startsWith('/access')) return 'access'
  if (pathname.startsWith('/reports')) return 'reports'
  if (pathname.startsWith('/platform')) return 'platform'
  if (pathname.startsWith('/search')) return 'search'
  if (pathname.startsWith('/me')) return 'account'
  if (pathname.startsWith('/notifications')) return 'notifications'
  return 'home'
}

export function ContextualHelpTrigger({
  locale,
  onOpen,
}: {
  locale: Locale
  onOpen: () => void
}) {
  const { isMobile, setOpenMobile } = useSidebar()
  const copy = helpCopy[locale]

  const showHelp = () => {
    if (isMobile) setOpenMobile(false)
    onOpen()
  }

  return (
    <SidebarMenuButton
      onClick={showHelp}
      /* Help is an action, never a route destination: the generated
       * `data-active={false}` would match the presence-based active
       * styles, so override it and omit the attribute entirely. */
      data-active={undefined}
      tooltip={{
        children: copy.trigger,
        side: locale === 'ar' ? 'left' : 'right',
      }}
      className="max-md:min-h-11 focus-visible:ring-sidebar-foreground!"
    >
      <CircleHelp aria-hidden="true" />
      <span>{copy.trigger}</span>
    </SidebarMenuButton>
  )
}

export function ContextualHelp({
  locale,
  pathname,
  scopeLabel,
  open,
  onOpenChange,
  screenHelp,
}: {
  locale: Locale
  pathname: string
  scopeLabel?: string
  open: boolean
  onOpenChange: (open: boolean) => void
  screenHelp?: ScreenHelp | null
}) {
  const { isMobile } = useSidebar()
  const copy = helpCopy[locale]
  const topic = copy.topics[topicForPath(pathname)]
  const [copyStatus, setCopyStatus] = useState<'idle' | 'copied' | 'failed'>(
    'idle',
  )

  useEffect(() => setCopyStatus('idle'), [screenHelp?.correlationId])

  const copyCorrelationId = async () => {
    const correlationId = screenHelp?.correlationId
    if (!correlationId) return
    try {
      if (!navigator.clipboard) throw new Error('Clipboard unavailable')
      await navigator.clipboard.writeText(correlationId)
      setCopyStatus('copied')
    } catch {
      setCopyStatus('failed')
    }
  }

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent
        side={locale === 'ar' ? 'left' : 'right'}
        dir={locale === 'ar' ? 'rtl' : 'ltr'}
        showCloseButton={false}
        className="w-full sm:max-w-md"
        onCloseAutoFocus={(event) => {
          if (!isMobile) return
          event.preventDefault()
          document.getElementById('app-sidebar-trigger')?.focus()
        }}
      >
          <SheetHeader className="border-b border-border">
            <div className="flex items-start justify-between gap-3">
              <div className="min-w-0">
                <SheetTitle>{topic.title}</SheetTitle>
                <SheetDescription className="mt-1">
                  {topic.description}
                </SheetDescription>
              </div>
              <SheetClose asChild>
                <Button
                  variant="ghost"
                  size="icon"
                  aria-label={copy.close}
                  className="max-md:size-11 shrink-0"
                >
                  <X aria-hidden="true" />
                </Button>
              </SheetClose>
            </div>
          </SheetHeader>
          <div className="space-y-6 overflow-y-auto p-4 pt-0">
            {(scopeLabel ||
              screenHelp?.currentState ||
              screenHelp?.activeSection) && (
              <dl className="grid gap-3 sm:grid-cols-2">
                {screenHelp?.currentState && (
                  <div>
                    <dt className="text-xs font-medium text-muted-foreground">
                      {copy.currentState}
                    </dt>
                    <dd className="mt-1 text-sm font-medium">
                      {screenHelp.currentState}
                    </dd>
                  </div>
                )}
                {scopeLabel && (
                  <div>
                    <dt className="text-xs font-medium text-muted-foreground">
                      {copy.scope}
                    </dt>
                    <dd className="mt-1 text-sm font-medium">{scopeLabel}</dd>
                  </div>
                )}
                {screenHelp?.activeSection && (
                  <div>
                    <dt className="text-xs font-medium text-muted-foreground">
                      {copy.activeSection}
                    </dt>
                    <dd className="mt-1 text-sm font-medium">
                      {screenHelp.activeSection}
                    </dd>
                  </div>
                )}
              </dl>
            )}
            <section>
              <h3 className="text-sm font-medium">{copy.next}</h3>
              {screenHelp?.permittedNextAction ? (
                <p className="mt-2 text-sm text-muted-foreground">
                  {screenHelp.permittedNextAction}
                </p>
              ) : (
                <ul className="mt-2 list-disc space-y-2 ps-5 text-sm text-muted-foreground">
                  {topic.steps.map((step) => (
                    <li key={step}>{step}</li>
                  ))}
                </ul>
              )}
            </section>
            {screenHelp?.recoveryGuidance?.length ? (
              <section>
                <h3 className="text-sm font-medium">{copy.recovery}</h3>
                <ul className="mt-2 list-disc space-y-2 ps-5 text-sm text-muted-foreground">
                  {screenHelp.recoveryGuidance.map((step) => (
                    <li key={step}>{step}</li>
                  ))}
                </ul>
              </section>
            ) : null}
            <section className="border-t border-border pt-4">
              <div className="flex items-center gap-2">
                <LifeBuoy className="size-4" aria-hidden="true" />
                <h3 className="text-sm font-medium">{copy.supportTitle}</h3>
              </div>
              <p className="mt-2 text-sm text-muted-foreground">
                {screenHelp?.correlationId
                  ? copy.supportBodyWithId
                  : copy.supportBody}
              </p>
              {screenHelp?.correlationId && (
                <div className="mt-3 border-y border-border py-2">
                  <p className="text-xs font-medium text-muted-foreground">
                    {copy.correlationId}
                  </p>
                  <div className="mt-1 flex items-center gap-2">
                    <code
                      dir="ltr"
                      className="min-w-0 flex-1 select-all font-mono text-xs [overflow-wrap:anywhere]"
                    >
                      {screenHelp.correlationId}
                    </code>
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      onClick={() => void copyCorrelationId()}
                      aria-label={copy.copyCorrelationId}
                    >
                      {copyStatus === 'copied' ? (
                        <Check aria-hidden="true" />
                      ) : (
                        <Copy aria-hidden="true" />
                      )}
                      <span className="hidden sm:inline">
                        {copy.copyCorrelationId}
                      </span>
                    </Button>
                  </div>
                  {copyStatus !== 'idle' && (
                    <p
                      role="status"
                      className="mt-2 text-xs text-muted-foreground"
                    >
                      {copyStatus === 'copied'
                        ? copy.copied
                        : copy.copyFailed}
                    </p>
                  )}
                </div>
              )}
            </section>
          </div>
      </SheetContent>
    </Sheet>
  )
}

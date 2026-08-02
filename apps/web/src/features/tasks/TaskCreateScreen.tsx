import { useMemo } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { ArrowRight } from 'lucide-react'
import * as generated from '../../api/generated/cluster'
import { useTaskMutations } from '../../api/hooks'
import { ApiError } from '../../api/http'
import { useNavigate } from '../../app/navigation-context'
import { useLocale, useSession } from '../../app/session-context'
import { PageHeader, PageLayout } from '@/components/page-layout'
import { Button } from '@/components/ui/button'
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/components/ui/form'
import { Input } from '@/components/ui/input'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Textarea } from '@/components/ui/textarea'
import {
  FormActionStack,
  FormSection,
  ReviewSummary,
  TwoRegionFormLayout,
  type ReviewSummaryRow,
} from '@/components/form-page-layout'

const copy = {
  ar: {
    pageTitle: 'إنشاء مهمة',
    pageDescription: 'أنشئ مهمة جديدة ضمن التجمع الصحي',
    back: 'عودة إلى المهام',
    titleLabel: 'العنوان',
    titlePlaceholder: 'عنوان موجز وواضح…',
    descriptionLabel: 'الوصف',
    descriptionPlaceholder: 'تفاصيل إضافية عن المهمة (اختياري)',
    priorityLabel: 'الأولوية',
    priorityLow: 'منخفضة',
    priorityNormal: 'عادية',
    priorityHigh: 'عالية',
    priorityUrgent: 'عاجلة',
    assigneeLabel: 'المسند إليه',
    assigneeHelp: 'يُسند افتراضياً إلى المستخدم الحالي إذا تُرك فارغاً.',
    classificationLabel: 'التصنيف',
    classificationPublic: 'عام',
    classificationInternal: 'داخلي',
    classificationConfidential: 'سري',
    classificationTopSecret: 'سري للغاية',
    dueAtLabel: 'تاريخ الاستحقاق',
    dueAtHelp: 'تاريخ ووقت الاستحقاق (اختياري)',
    submit: 'أنشئ المهمة',
    cancel: 'إلغاء',
    titleRequired: 'العنوان مطلوب.',
    titleTooLong: 'يجب ألا يتجاوز العنوان 255 حرفاً.',
    descriptionTooLong: 'يجب ألا يتجاوز الوصف 4000 حرفاً.',
    invalidDueAt: 'صيغة تاريخ الاستحقاق غير صحيحة.',
    submitError: 'تعذر إنشاء المهمة. يرجى إعادة المحاولة.',
    forbidden: 'غير مصرح لك بإنشاء المهام.',
    conflict: 'تعارض في إنشاء المهمة، يرجى إعادة المحاولة.',
    loading: 'جارٍ الإنشاء…',
    essentialsHeading: 'معلومات أساسية',
    planningHeading: 'التخطيط والصلاحيات',
    reviewHeading: 'مراجعة قبل الإنشاء',
    reviewTitleLabel: 'العنوان',
    reviewTitleFallback: 'سيُستخدم اسم الملف كعنوان',
    reviewDescriptionLabel: 'الوصف',
    reviewDescriptionFallback: 'لا يوجد وصف',
    reviewPriorityLabel: 'الأولوية',
    reviewClassificationLabel: 'التصنيف',
    reviewAssigneeLabel: 'المسند إليه',
    reviewAssigneeFallback: 'لم يُسند إلى أحد',
    reviewDueAtLabel: 'تاريخ الاستحقاق',
    reviewDueAtFallback: 'بدون تاريخ استحقاق',
    notProvided: '—',
  },
  en: {
    pageTitle: 'Create task',
    pageDescription: 'Create a new task within the health cluster',
    back: 'Back to tasks',
    titleLabel: 'Title',
    titlePlaceholder: 'A short, clear title…',
    descriptionLabel: 'Description',
    descriptionPlaceholder: 'Additional details (optional)',
    priorityLabel: 'Priority',
    priorityLow: 'Low',
    priorityNormal: 'Normal',
    priorityHigh: 'High',
    priorityUrgent: 'Urgent',
    assigneeLabel: 'Assignee',
    assigneeHelp: 'Defaults to the current user when left empty.',
    classificationLabel: 'Classification',
    classificationPublic: 'Public',
    classificationInternal: 'Internal',
    classificationConfidential: 'Confidential',
    classificationTopSecret: 'Top secret',
    dueAtLabel: 'Due at',
    dueAtHelp: 'Due date and time (optional)',
    submit: 'Create task',
    cancel: 'Cancel',
    titleRequired: 'A title is required.',
    titleTooLong: 'The title must be at most 255 characters.',
    descriptionTooLong: 'The description must be at most 4000 characters.',
    invalidDueAt: 'The due date format is invalid.',
    submitError: 'Could not create the task. Please try again.',
    forbidden: 'You are not authorized to create tasks.',
    conflict: 'Conflict while creating the task, please try again.',
    loading: 'Creating…',
    essentialsHeading: 'Task essentials',
    planningHeading: 'Planning and access',
    reviewHeading: 'Review before creating',
    reviewTitleLabel: 'Title',
    reviewTitleFallback: 'Will use the file name as the title',
    reviewDescriptionLabel: 'Description',
    reviewDescriptionFallback: 'No description',
    reviewPriorityLabel: 'Priority',
    reviewClassificationLabel: 'Classification',
    reviewAssigneeLabel: 'Assignee',
    reviewAssigneeFallback: 'Not assigned',
    reviewDueAtLabel: 'Due at',
    reviewDueAtFallback: 'No due date',
    notProvided: '—',
  },
} as const

interface TaskFormValues {
  title: string
  description: string
  priority: string
  assignee: string
  classification: string
  dueAt: string
}

export function TaskCreateScreen() {
  const locale = useLocale()
  const session = useSession()
  const navigate = useNavigate()
  const t = copy[locale]
  const { create } = useTaskMutations()
  const saving = create.isPending

  const schema = useMemo(
    () =>
      z.object({
        title: z.string().trim().min(1, t.titleRequired).max(255, t.titleTooLong),
        description: z.string().max(4000, t.descriptionTooLong),
        priority: z.string(),
        assignee: z.string(),
        classification: z.string(),
        dueAt: z
          .string()
          .refine((value) => !value || !Number.isNaN(new Date(value).getTime()), t.invalidDueAt),
      }),
    [t],
  )

  const form = useForm<TaskFormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      title: '',
      description: '',
      priority: 'normal',
      assignee: session.session.userId,
      classification: 'internal',
      dueAt: '',
    },
  })

  const submit = form.handleSubmit(async (values) => {
    try {
      const input: generated.TaskCreate = {
        title: values.title,
        priority: values.priority as generated.TaskCreatePriority,
        classification: values.classification as generated.Classification,
        assignee_user_id: values.assignee.trim() || undefined,
        ...(values.description.trim() ? { description: values.description.trim() } : {}),
        ...(values.dueAt ? { due_at: new Date(values.dueAt).toISOString() } : {}),
      }
      const created = (await create.mutateAsync(input)) as generated.Task
      navigate(`/tasks/${created.id}`)
    } catch (cause) {
      if (cause instanceof ApiError && cause.status === 403) {
        form.setError('title', { message: t.forbidden })
      } else if (cause instanceof ApiError && cause.status === 409) {
        form.setError('title', { message: t.conflict })
      } else {
        form.setError('title', { message: t.submitError })
      }
    }
  })

  // Live watched values feed the review surface. The form is the single
  // source of truth; the review re-renders on each change.
  const titleValue = form.watch('title')
  const descriptionValue = form.watch('description')
  const priorityValue = form.watch('priority')
  const classificationValue = form.watch('classification')
  const assigneeValue = form.watch('assignee')
  const dueAtValue = form.watch('dueAt')

  const priorityLabel = (value: string): string => {
    switch (value) {
      case 'low':
        return t.priorityLow
      case 'high':
        return t.priorityHigh
      case 'urgent':
        return t.priorityUrgent
      case 'normal':
      default:
        return t.priorityNormal
    }
  }

  const classificationLabel = (value: string): string => {
    switch (value) {
      case 'public':
        return t.classificationPublic
      case 'confidential':
        return t.classificationConfidential
      case 'top_secret':
        return t.classificationTopSecret
      case 'internal':
      default:
        return t.classificationInternal
    }
  }

  const reviewRows: ReviewSummaryRow[] = [
    {
      label: t.reviewTitleLabel,
      value: titleValue.trim() || null,
      empty: (
        <span className="text-muted-foreground">{t.notProvided}</span>
      ),
      isolate: true,
    },
    {
      label: t.reviewDescriptionLabel,
      value: descriptionValue.trim() || null,
      empty: (
        <span className="text-muted-foreground">
          {t.reviewDescriptionFallback}
        </span>
      ),
    },
    {
      label: t.reviewPriorityLabel,
      value: priorityLabel(priorityValue),
    },
    {
      label: t.reviewClassificationLabel,
      value: classificationLabel(classificationValue),
    },
    {
      label: t.reviewAssigneeLabel,
      value: assigneeValue.trim() || null,
      empty: (
        <span className="text-muted-foreground">
          {t.reviewAssigneeFallback}
        </span>
      ),
      isolate: true,
    },
    {
      label: t.reviewDueAtLabel,
      value: dueAtValue || null,
      empty: (
        <span className="text-muted-foreground">
          {t.reviewDueAtFallback}
        </span>
      ),
      isolate: true,
    },
  ]

  return (
    <PageLayout>
      <div>
        <Button variant="ghost" size="sm" onClick={() => navigate('/tasks')} className="-ms-2">
          <ArrowRight aria-hidden="true" />
          {t.back}
        </Button>
      </div>
      <PageHeader title={t.pageTitle} description={t.pageDescription} />

      <Form {...form}>
        <TwoRegionFormLayout
          testId="task-create-form"
          mainTestId="task-create-main"
          reviewTestId="task-create-review"
          onSubmit={(event) => void submit(event)}
          main={
            <>
              <FormSection
                headingId="task-create-section-essentials"
                title={t.essentialsHeading}
              >
                <FormField
                  control={form.control}
                  name="title"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel htmlFor="task-create-title">{t.titleLabel}</FormLabel>
                      <FormControl>
                        <Input id="task-create-title" disabled={saving} maxLength={255} placeholder={t.titlePlaceholder} {...field} />
                      </FormControl>
                      <FormMessage role="alert" />
                    </FormItem>
                  )}
                />
                <FormField
                  control={form.control}
                  name="description"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel htmlFor="task-create-description">{t.descriptionLabel}</FormLabel>
                      <FormControl>
                        <Textarea id="task-create-description" disabled={saving} maxLength={4000} placeholder={t.descriptionPlaceholder} {...field} />
                      </FormControl>
                      <FormMessage role="alert" />
                    </FormItem>
                  )}
                />
              </FormSection>

              <FormSection
                headingId="task-create-section-planning"
                title={t.planningHeading}
                divided
              >
                <div className="grid gap-4 sm:grid-cols-2">
                  <FormField
                    control={form.control}
                    name="priority"
                    render={({ field }) => (
                      <FormItem>
                        <FormLabel htmlFor="task-create-priority">{t.priorityLabel}</FormLabel>
                        <Select value={field.value} onValueChange={field.onChange}>
                          <FormControl>
                            <SelectTrigger id="task-create-priority" className="w-full">
                              <SelectValue />
                            </SelectTrigger>
                          </FormControl>
                          <SelectContent>
                            <SelectItem value="low">{t.priorityLow}</SelectItem>
                            <SelectItem value="normal">{t.priorityNormal}</SelectItem>
                            <SelectItem value="high">{t.priorityHigh}</SelectItem>
                            <SelectItem value="urgent">{t.priorityUrgent}</SelectItem>
                          </SelectContent>
                        </Select>
                        <FormMessage role="alert" />
                      </FormItem>
                    )}
                  />
                  <FormField
                    control={form.control}
                    name="classification"
                    render={({ field }) => (
                      <FormItem>
                        <FormLabel htmlFor="task-create-classification">{t.classificationLabel}</FormLabel>
                        <Select value={field.value} onValueChange={field.onChange}>
                          <FormControl>
                            <SelectTrigger id="task-create-classification" className="w-full">
                              <SelectValue />
                            </SelectTrigger>
                          </FormControl>
                          <SelectContent>
                            <SelectItem value="public">{t.classificationPublic}</SelectItem>
                            <SelectItem value="internal">{t.classificationInternal}</SelectItem>
                            <SelectItem value="confidential">{t.classificationConfidential}</SelectItem>
                            <SelectItem value="top_secret">{t.classificationTopSecret}</SelectItem>
                          </SelectContent>
                        </Select>
                        <FormMessage role="alert" />
                      </FormItem>
                    )}
                  />
                  <FormField
                    control={form.control}
                    name="assignee"
                    render={({ field }) => (
                      <FormItem>
                        <FormLabel htmlFor="task-create-assignee">{t.assigneeLabel}</FormLabel>
                        <FormControl>
                          <Input id="task-create-assignee" disabled={saving} {...field} />
                        </FormControl>
                        <p className="text-muted-foreground text-xs">{t.assigneeHelp}</p>
                        <FormMessage role="alert" />
                      </FormItem>
                    )}
                  />
                  <FormField
                    control={form.control}
                    name="dueAt"
                    render={({ field }) => (
                      <FormItem>
                        <FormLabel htmlFor="task-create-due-at">{t.dueAtLabel}</FormLabel>
                        <FormControl>
                          <Input id="task-create-due-at" type="datetime-local" disabled={saving} {...field} />
                        </FormControl>
                        <p className="text-muted-foreground text-xs">{t.dueAtHelp}</p>
                        <FormMessage role="alert" />
                      </FormItem>
                    )}
                  />
                </div>
              </FormSection>
            </>
          }
          review={
            <>
              <FormSection
                headingId="task-create-section-review"
                title={t.reviewHeading}
                density="tight"
              >
                <ReviewSummary rows={reviewRows} />
              </FormSection>

              <FormActionStack>
                <Button
                  type="submit"
                  className="w-full"
                  disabled={saving}
                  data-testid="task-create-submit"
                >
                  {saving ? t.loading : t.submit}
                </Button>
                <Button
                  type="button"
                  variant="outline"
                  className="w-full"
                  disabled={saving}
                  onClick={() => navigate('/tasks')}
                >
                  {t.cancel}
                </Button>
              </FormActionStack>
            </>
          }
        />
      </Form>
    </PageLayout>
  )
}

import { FileText, Paperclip } from 'lucide-react'
import * as generated from '../../../api/generated/cluster'
import { useLocale } from '../../../app/session-context'
import { formatDate, statusLabel } from '../../../i18n'
import { EmptyState } from '@/components/states'
import { Badge } from '@/components/ui/badge'
import { tasksCopy } from '../tasks-copy'

function classificationLabel(classification: generated.Classification, locale: 'ar' | 'en'): string {
  const t = tasksCopy[locale]
  switch (classification) {
    case 'public':
      return t.classificationPublic
    case 'internal':
      return t.classificationInternal
    case 'confidential':
      return t.classificationConfidential
    case 'top_secret':
      return t.classificationTopSecret
    default:
      return classification
  }
}

export function TaskDetailsTab({ task }: { task: generated.Task }) {
  const locale = useLocale()
  const t = tasksCopy[locale]
  const priorityLabel =
    task.priority === 'low'
      ? t.priorityLow
      : task.priority === 'high'
        ? t.priorityHigh
        : task.priority === 'urgent'
          ? t.priorityUrgent
          : t.priorityNormal

  return (
    <div className="space-y-4">
      <dl className="grid gap-x-6 gap-y-3 sm:grid-cols-2">
        <div>
          <dt className="text-muted-foreground text-sm">{t.state}</dt>
          <dd className="mt-1">
            <Badge variant="outline">{statusLabel(task.state, locale)}</Badge>
          </dd>
        </div>
        <div>
          <dt className="text-muted-foreground text-sm">{t.priority}</dt>
          <dd className="mt-1 text-sm font-medium">{priorityLabel}</dd>
        </div>
        <div>
          <dt className="text-muted-foreground text-sm">{t.classification}</dt>
          <dd className="mt-1 text-sm font-medium">{classificationLabel(task.classification, locale)}</dd>
        </div>
        {task.assignee_user_id ? (
          <div>
            <dt className="text-muted-foreground text-sm">{t.assignee}</dt>
            <dd className="mt-1 text-sm font-medium">{task.assignee_user_id}</dd>
          </div>
        ) : null}
        {task.creator_user_id ? (
          <div>
            <dt className="text-muted-foreground text-sm">{t.creator}</dt>
            <dd className="mt-1 text-sm font-medium">{task.creator_user_id}</dd>
          </div>
        ) : null}
        {task.due_at ? (
          <div>
            <dt className="text-muted-foreground text-sm">{t.dueAt}</dt>
            <dd className="mt-1 text-sm font-medium">
              <time dateTime={task.due_at}>{formatDate(task.due_at, locale)}</time>
            </dd>
          </div>
        ) : null}
        <div>
          <dt className="text-muted-foreground text-sm">{t.createdAt}</dt>
          <dd className="mt-1 text-sm font-medium">
            <time dateTime={task.created_at}>{formatDate(task.created_at, locale)}</time>
          </dd>
        </div>
        <div>
          <dt className="text-muted-foreground text-sm">{t.updatedAt}</dt>
          <dd className="mt-1 text-sm font-medium">
            <time dateTime={task.updated_at}>{formatDate(task.updated_at, locale)}</time>
          </dd>
        </div>
      </dl>

      <div>
        <h3 className="text-sm font-medium">{t.description}</h3>
        <p className="text-muted-foreground mt-1 text-sm">{task.description || t.noDescription}</p>
      </div>

      <div>
        <h3 className="flex items-center gap-2 text-sm font-medium">
          <Paperclip aria-hidden="true" className="size-4" />
          {t.attachments}
        </h3>
        {task.attachments && task.attachments.length > 0 ? (
          <ul className="mt-2 space-y-2">
            {task.attachments.map((attachment) => (
              <li key={attachment.document_id} className="flex items-center gap-2 text-sm">
                <FileText aria-hidden="true" className="text-muted-foreground size-4" />
                {attachment.title ?? attachment.document_id}
              </li>
            ))}
          </ul>
        ) : (
          <EmptyState icon={<Paperclip aria-hidden="true" />} title={t.noAttachments} />
        )}
      </div>
    </div>
  )
}

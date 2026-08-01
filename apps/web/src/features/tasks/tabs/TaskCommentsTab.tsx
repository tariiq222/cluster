import { useState } from 'react'
import { MessageSquare } from 'lucide-react'
import { useLocale } from '../../../app/session-context'
import { formatDate } from '../../../i18n'
import { EmptyState } from '@/components/states'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { tasksCopy } from '../tasks-copy'

export interface TaskComment {
  id: string
  body: string
  author_user_id: string
  created_at: string
}

export function TaskCommentsTab({
  comments,
  canComment,
  onAddComment,
}: {
  comments: TaskComment[]
  canComment: boolean
  onAddComment: (body: string, mentionedUserIds: string[]) => Promise<void>
}) {
  const locale = useLocale()
  const t = tasksCopy[locale]
  const [body, setBody] = useState('')
  const [mentions, setMentions] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const submit = async () => {
    const trimmed = body.trim()
    if (!trimmed) {
      setError(t.commentRequired)
      return
    }
    setError(null)
    setBusy(true)
    try {
      await onAddComment(trimmed, parseUserIds(mentions))
      setBody('')
      setMentions('')
    } catch {
      setError(t.actionError)
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="space-y-4">
      {comments.length > 0 ? (
        <ul className="space-y-3">
          {comments.map((comment) => (
            <li key={comment.id} className="rounded-lg border p-3">
              <p className="text-sm font-medium">{comment.author_user_id || '—'}</p>
              <p className="mt-1 text-sm">{comment.body}</p>
              <p className="text-muted-foreground mt-1 text-xs">
                <time dateTime={comment.created_at}>{formatDate(comment.created_at, locale)}</time>
              </p>
            </li>
          ))}
        </ul>
      ) : (
        <EmptyState icon={<MessageSquare aria-hidden="true" />} title={t.noComments} />
      )}

      {canComment ? (
        <div className="space-y-3 rounded-lg border p-4">
          <h3 className="text-sm font-medium">{t.commentTitle}</h3>
          <div className="grid gap-1">
            <label htmlFor="task-comment-body" className="text-sm">
              {t.commentBodyLabel}
            </label>
            <Textarea
              id="task-comment-body"
              value={body}
              onChange={(event) => setBody(event.target.value)}
              disabled={busy}
              placeholder={t.commentBodyPlaceholder}
            />
          </div>
          <div className="grid gap-1">
            <label htmlFor="task-comment-mentions" className="text-sm">
              {t.mentionLabel}
            </label>
            <Input
              id="task-comment-mentions"
              value={mentions}
              onChange={(event) => setMentions(event.target.value)}
              disabled={busy}
              placeholder={t.mentionPlaceholder}
            />
          </div>
          {error ? <p className="text-destructive text-sm" role="alert">{error}</p> : null}
          <Button size="sm" onClick={() => void submit()} disabled={busy}>
            {t.confirm}
          </Button>
        </div>
      ) : null}
    </div>
  )
}

function parseUserIds(raw: string): string[] {
  return raw
    .split(/[\s,]+/)
    .map((part) => part.trim())
    .filter((part) => part.length > 0)
}

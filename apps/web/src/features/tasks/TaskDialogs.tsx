import { useEffect, useState } from 'react'
import { useLocale } from '../../app/session-context'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Textarea } from '@/components/ui/textarea'
import { tasksCopy } from './tasks-copy'

export type TransitionAction = 'start' | 'block' | 'unblock' | 'complete' | 'cancel'

export type TaskDialog =
  | { kind: 'transition'; action: TransitionAction }
  | { kind: 'edit' }
  | { kind: 'attach-document' }

export type TaskDialogSubmit =
  | { type: 'transition'; action: TransitionAction; reason?: string; note?: string }
  | { type: 'edit'; title: string; description: string; priority: string }
  | { type: 'attach'; documentId: string }

export function TaskDialogs({
  dialog,
  busy,
  task,
  onSubmit,
  onClose,
}: {
  dialog: TaskDialog | null
  busy: boolean
  task: { title: string; description?: string | null; priority: string } | null
  onSubmit: (payload: TaskDialogSubmit) => Promise<void>
  onClose: () => void
}) {
  const locale = useLocale()
  const t = tasksCopy[locale]
  const [reason, setReason] = useState('')
  const [note, setNote] = useState('')
  const [editTitle, setEditTitle] = useState('')
  const [editDescription, setEditDescription] = useState('')
  const [editPriority, setEditPriority] = useState('normal')
  const [documentId, setDocumentId] = useState('')
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    if (!dialog) return
    setReason('')
    setNote('')
    setDocumentId('')
    setError(null)
    if (dialog.kind === 'edit' && task) {
      setEditTitle(task.title)
      setEditDescription(task.description ?? '')
      setEditPriority(task.priority)
    }
  }, [dialog, task])

  if (!dialog) return null

  const close = () => {
    setError(null)
    onClose()
  }

  const submit = async () => {
    setError(null)
    if (dialog.kind === 'transition' && (dialog.action === 'block' || dialog.action === 'cancel') && !reason.trim()) {
      setError(t.reasonRequired)
      return
    }
    if (dialog.kind === 'transition' && dialog.action === 'complete' && !note.trim()) {
      setError(t.noteRequired)
      return
    }
    if (dialog.kind === 'edit' && !editTitle.trim()) {
      setError(t.titleRequired)
      return
    }
    if (dialog.kind === 'edit' && editTitle.trim().length > 255) {
      setError(t.titleTooLong)
      return
    }
    if (dialog.kind === 'edit' && editDescription.length > 4000) {
      setError(t.descriptionTooLong)
      return
    }
    if (dialog.kind === 'attach-document' && !documentId.trim()) {
      setError(t.documentIdRequired)
      return
    }
    try {
      if (dialog.kind === 'transition') {
        await onSubmit({
          type: 'transition',
          action: dialog.action,
          ...(dialog.action === 'block' || dialog.action === 'cancel' ? { reason: reason.trim() } : {}),
          ...(dialog.action === 'complete' ? { note: note.trim() } : {}),
        })
      } else if (dialog.kind === 'edit') {
        await onSubmit({ type: 'edit', title: editTitle.trim(), description: editDescription.trim(), priority: editPriority })
      } else {
        await onSubmit({ type: 'attach', documentId: documentId.trim() })
      }
      setReason('')
      setNote('')
      setDocumentId('')
    } catch {
      setError(t.actionError)
    }
  }

  if (dialog.kind === 'transition' && (dialog.action === 'block' || dialog.action === 'cancel')) {
    const isBlock = dialog.action === 'block'
    return (
      <Dialog open onOpenChange={(open) => { if (!open) close() }}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{isBlock ? t.dialogBlockTitle : t.dialogCancelTitle}</DialogTitle>
            <DialogDescription>{isBlock ? t.dialogBlockDescription : t.dialogCancelDescription}</DialogDescription>
          </DialogHeader>
          <div className="grid gap-2">
            <Label htmlFor="task-transition-reason">{t.dialogReasonLabel}</Label>
            <Textarea
              id="task-transition-reason"
              value={reason}
              onChange={(event) => setReason(event.target.value)}
              disabled={busy}
              placeholder={t.dialogReasonPlaceholder}
            />
          </div>
          {error ? <p className="text-destructive text-sm" role="alert">{error}</p> : null}
          <DialogFooter>
            <Button variant="outline" onClick={close} disabled={busy}>
              {t.cancel}
            </Button>
            <Button onClick={() => void submit()} disabled={busy}>
              {t.confirm}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    )
  }

  if (dialog.kind === 'transition') {
    return (
      <Dialog open onOpenChange={(open) => { if (!open) close() }}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{t.dialogCompleteTitle}</DialogTitle>
            <DialogDescription>{t.dialogCompleteDescription}</DialogDescription>
          </DialogHeader>
          <div className="grid gap-2">
            <Label htmlFor="task-transition-note">{t.dialogNoteLabel}</Label>
            <Textarea
              id="task-transition-note"
              value={note}
              onChange={(event) => setNote(event.target.value)}
              disabled={busy}
              placeholder={t.dialogNotePlaceholder}
            />
          </div>
          {error ? <p className="text-destructive text-sm" role="alert">{error}</p> : null}
          <DialogFooter>
            <Button variant="outline" onClick={close} disabled={busy}>
              {t.cancel}
            </Button>
            <Button onClick={() => void submit()} disabled={busy}>
              {t.confirm}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    )
  }

  if (dialog.kind === 'edit') {
    return (
      <Dialog open onOpenChange={(open) => { if (!open) close() }}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{t.editTitle}</DialogTitle>
          </DialogHeader>
          <div className="grid gap-4">
            <div className="grid gap-2">
              <Label htmlFor="task-edit-title">{t.editTitleLabel}</Label>
              <Input
                id="task-edit-title"
                value={editTitle}
                onChange={(event) => setEditTitle(event.target.value)}
                disabled={busy}
                maxLength={255}
              />
            </div>
            <div className="grid gap-2">
              <Label htmlFor="task-edit-description">{t.editDescriptionLabel}</Label>
              <Textarea
                id="task-edit-description"
                value={editDescription}
                onChange={(event) => setEditDescription(event.target.value)}
                disabled={busy}
                maxLength={4000}
              />
            </div>
            <div className="grid gap-2">
              <Label htmlFor="task-edit-priority">{t.editPriorityLabel}</Label>
              <Select value={editPriority} onValueChange={setEditPriority}>
                <SelectTrigger id="task-edit-priority">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="low">{t.priorityLow}</SelectItem>
                  <SelectItem value="normal">{t.priorityNormal}</SelectItem>
                  <SelectItem value="high">{t.priorityHigh}</SelectItem>
                  <SelectItem value="urgent">{t.priorityUrgent}</SelectItem>
                </SelectContent>
              </Select>
            </div>
          </div>
          {error ? <p className="text-destructive text-sm" role="alert">{error}</p> : null}
          <DialogFooter>
            <Button variant="outline" onClick={close} disabled={busy}>
              {t.cancel}
            </Button>
            <Button onClick={() => void submit()} disabled={busy}>
              {t.save}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    )
  }

  return (
    <Dialog open onOpenChange={(open) => { if (!open) close() }}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t.attachTitle}</DialogTitle>
        </DialogHeader>
        <div className="grid gap-2">
          <Label htmlFor="task-attach-document-id">{t.attachDocumentIdLabel}</Label>
          <Input
            id="task-attach-document-id"
            value={documentId}
            onChange={(event) => setDocumentId(event.target.value)}
            disabled={busy}
            placeholder={t.attachDocumentIdPlaceholder}
          />
        </div>
        {error ? <p className="text-destructive text-sm" role="alert">{error}</p> : null}
        <DialogFooter>
          <Button variant="outline" onClick={close} disabled={busy}>
            {t.cancel}
          </Button>
          <Button onClick={() => void submit()} disabled={busy}>
            {t.confirm}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

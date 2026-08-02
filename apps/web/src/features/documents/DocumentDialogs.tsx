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
import { documentsCopy } from './documents-copy'

export type DocumentAction = 'archive' | 'unarchive' | 'place-hold' | 'release-hold'

export type DocumentDialog =
  | { kind: 'transition'; action: DocumentAction }
  | { kind: 'link' }

export type DocumentDialogSubmit =
  | { type: 'transition'; action: DocumentAction; reason: string }
  | { type: 'link'; relation: string; module: string; recordType: string; recordId: string }

export function DocumentDialogs({
  dialog,
  busy,
  onSubmit,
  onClose,
}: {
  dialog: DocumentDialog | null
  busy: boolean
  onSubmit: (payload: DocumentDialogSubmit) => Promise<void>
  onClose: () => void
}) {
  const locale = useLocale()
  const t = documentsCopy[locale]
  const [reason, setReason] = useState('')
  const [relation, setRelation] = useState('attachment')
  const [module, setModule] = useState('tasks')
  const [recordType, setRecordType] = useState('task')
  const [recordId, setRecordId] = useState('')
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    if (!dialog) return
    setReason('')
    setRecordId('')
    setError(null)
  }, [dialog])

  if (!dialog) return null

  const close = () => {
    setError(null)
    onClose()
  }

  if (dialog.kind === 'transition') {
    return (
      <Dialog open onOpenChange={(open) => { if (!open) close() }}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{t.reasonTitle}</DialogTitle>
            <DialogDescription>{t.actionsTitle}</DialogDescription>
          </DialogHeader>
          <div className="grid gap-2">
            <Label htmlFor="document-reason">{t.reasonLabel}</Label>
            <Textarea
              id="document-reason"
              value={reason}
              onChange={(event) => setReason(event.target.value)}
              disabled={busy}
              placeholder={t.reasonPlaceholder}
            />
          </div>
          {error ? <p className="text-destructive text-sm" role="alert">{error}</p> : null}
          <DialogFooter>
            <Button variant="outline" onClick={close} disabled={busy}>
              {t.cancel}
            </Button>
            <Button
              disabled={busy}
              onClick={() => {
                if (!reason.trim()) {
                  setError(t.reasonRequired)
                  return
                }
                void onSubmit({ type: 'transition', action: dialog.action, reason: reason.trim() })
              }}
            >
              {t.confirm}
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
          <DialogTitle>{t.linkTitle}</DialogTitle>
        </DialogHeader>
        <div className="grid gap-4">
          <div className="grid gap-2">
            <Label htmlFor="document-link-relation">{t.linkRelationLabel}</Label>
            <Select value={relation} onValueChange={setRelation} disabled={busy}>
              <SelectTrigger id="document-link-relation" className="w-full">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="attachment">{t.linkRelationAttachment}</SelectItem>
                <SelectItem value="evidence">{t.linkRelationEvidence}</SelectItem>
              </SelectContent>
            </Select>
          </div>
          <div className="grid grid-cols-2 gap-4">
            <div className="grid gap-2">
              <Label htmlFor="document-link-module">{t.linkModuleLabel}</Label>
              <Input id="document-link-module" value={module} onChange={(event) => setModule(event.target.value)} disabled={busy} placeholder={t.linkModulePlaceholder} />
            </div>
            <div className="grid gap-2">
              <Label htmlFor="document-link-record-type">{t.linkRecordTypeLabel}</Label>
              <Input id="document-link-record-type" value={recordType} onChange={(event) => setRecordType(event.target.value)} disabled={busy} placeholder={t.linkRecordTypePlaceholder} />
            </div>
          </div>
          <div className="grid gap-2">
            <Label htmlFor="document-link-record-id">{t.linkRecordIdLabel}</Label>
            <Input id="document-link-record-id" value={recordId} onChange={(event) => setRecordId(event.target.value)} disabled={busy} placeholder={t.linkRecordIdPlaceholder} />
          </div>
        </div>
        {error ? <p className="text-destructive text-sm" role="alert">{error}</p> : null}
        <DialogFooter>
          <Button variant="outline" onClick={close} disabled={busy}>
            {t.cancel}
          </Button>
          <Button
            disabled={busy}
            onClick={() => {
              if (!recordId.trim()) {
                setError(t.recordIdRequired)
                return
              }
              void onSubmit({ type: 'link', relation, module, recordType, recordId: recordId.trim() })
            }}
          >
            {t.confirm}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

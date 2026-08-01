import { useState } from 'react'
import { UserPlus, Users } from 'lucide-react'
import { useLocale } from '../../../app/session-context'
import { EmptyState } from '@/components/states'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { tasksCopy } from '../tasks-copy'

export function TaskParticipantsTab({
  participantUserIds,
  canAddParticipant,
  onAddParticipant,
}: {
  participantUserIds: string[]
  canAddParticipant: boolean
  onAddParticipant: (userId: string) => Promise<void>
}) {
  const locale = useLocale()
  const t = tasksCopy[locale]
  const [userId, setUserId] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const submit = async () => {
    const trimmed = userId.trim()
    if (!trimmed) {
      setError(t.addParticipantRequired)
      return
    }
    setError(null)
    setBusy(true)
    try {
      await onAddParticipant(trimmed)
      setUserId('')
    } catch {
      setError(t.actionError)
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="space-y-4">
      {participantUserIds.length > 0 ? (
        <ul className="space-y-2">
          {participantUserIds.map((participant) => (
            <li key={participant} className="flex items-center gap-2 text-sm">
              <Users aria-hidden="true" className="text-muted-foreground size-4" />
              {participant}
            </li>
          ))}
        </ul>
      ) : (
        <EmptyState icon={<Users aria-hidden="true" />} title={t.noParticipants} />
      )}

      {canAddParticipant ? (
        <div className="space-y-3 rounded-lg border p-4">
          <h3 className="text-sm font-medium">{t.addParticipant}</h3>
          <div className="grid gap-1">
            <label htmlFor="task-participant-user-id" className="text-sm">
              {t.addParticipantLabel}
            </label>
            <Input
              id="task-participant-user-id"
              value={userId}
              onChange={(event) => setUserId(event.target.value)}
              disabled={busy}
              placeholder={t.addParticipantPlaceholder}
            />
          </div>
          {error ? <p className="text-destructive text-sm" role="alert">{error}</p> : null}
          <Button size="sm" onClick={() => void submit()} disabled={busy}>
            <UserPlus aria-hidden="true" />
            {t.addParticipant}
          </Button>
        </div>
      ) : null}
    </div>
  )
}

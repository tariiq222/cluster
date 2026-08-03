import { type FormEvent } from 'react'
import { useLocale } from '../../../app/session-context'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { importsCopy } from '../imports-copy'

export function ValidateStep({
  quarantineId,
  onQuarantineIdChange,
  onSubmit,
  submitting,
  disabled = false,
  error,
}: {
  quarantineId: string
  onQuarantineIdChange: (quarantineId: string) => void
  onSubmit: () => Promise<void>
  submitting: boolean
  disabled?: boolean
  error: 'validation' | 'save' | null
}) {
  const locale = useLocale()
  const text = importsCopy[locale]

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    void onSubmit()
  }

  return (
    <form className="grid gap-4" onSubmit={(event) => void submit(event)} noValidate>
      {error === 'validation' ? (
        <p className="text-destructive text-sm" role="alert">{text.validation}</p>
      ) : error === 'save' ? (
        <p className="text-destructive text-sm" role="alert">{text.submitError}</p>
      ) : null}
      <div className="grid gap-2">
        <Label htmlFor="import-quarantine-id">{text.quarantineId}</Label>
        <Input
          id="import-quarantine-id"
          dir="ltr"
          value={quarantineId}
          aria-invalid={error === 'validation' || undefined}
          onChange={(event) => onQuarantineIdChange(event.target.value)}
        />
      </div>
      <div>
        <Button type="submit" disabled={disabled || submitting}>
          {submitting ? text.executing : text.submit}
        </Button>
      </div>
    </form>
  )
}

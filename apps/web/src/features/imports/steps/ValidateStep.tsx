import { type FormEvent } from 'react'
import { useLocale, useSessionToken } from '../../../app/session-context'
import { requestInit, unwrap } from '../../../api/http'
import * as generated from '../../../api/generated/cluster'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { importsCopy } from '../imports-copy'

const UUID_V7 = /^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/

export function ValidateStep({
  quarantineId,
  onQuarantineIdChange,
  onSubmitted,
  submitting,
  error,
}: {
  quarantineId: string
  onQuarantineIdChange: (quarantineId: string) => void
  onSubmitted: (job: generated.ImportJob) => void
  submitting: boolean
  error: 'validation' | 'save' | null
}) {
  const locale = useLocale()
  const token = useSessionToken()
  const text = importsCopy[locale]
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!UUID_V7.test(quarantineId)) return
    try {
      const created = unwrap<generated.ImportJob>(
        await generated.submitOrganizationImport(
          {
            quarantine_object_id: quarantineId,
            template_code: 'people_assignments',
            import_type: 'csv',
          },
          requestInit(token, { command: true, idempotency: 'import-submit' }),
        ),
      )
      onSubmitted(created)
    } catch {
      // the wizard surfaces the error banner
    }
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
        <Button type="submit" disabled={submitting}>
          {submitting ? text.executing : text.submit}
        </Button>
      </div>
    </form>
  )
}

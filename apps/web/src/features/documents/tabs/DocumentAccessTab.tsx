import { useState } from 'react'
import { KeyRound } from 'lucide-react'
import * as generated from '../../../api/generated/cluster'
import { requestInit, unwrap } from '../../../api/http'
import { useLocale, useSessionToken } from '../../../app/session-context'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Input } from '@/components/ui/input'
import { documentsCopy } from '../documents-copy'
import type { DocumentVersion } from './DocumentVersionsTab'

export function DocumentAccessTab({
  documentId,
  versions,
  canGrant,
}: {
  documentId: string
  versions: DocumentVersion[]
  canGrant: boolean
}) {
  const locale = useLocale()
  const csrfToken = useSessionToken()
  const t = documentsCopy[locale]

  const [grantType, setGrantType] = useState<'preview' | 'download'>('preview')
  const [versionId, setVersionId] = useState('')
  const [purpose, setPurpose] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [granted, setGranted] = useState(false)

  const submit = async () => {
    if (!versionId) {
      setError(t.grantVersionRequired)
      return
    }
    setError(null)
    setGranted(false)
    setBusy(true)
    try {
      unwrap<generated.Entity>(
        await generated.createDocumentAccessGrant(
          documentId,
          grantType,
          { version_id: versionId, ...(purpose.trim() ? { purpose: purpose.trim() } : {}) },
          requestInit(csrfToken, { command: true, idempotency: 'document-access-grant' }),
        ),
      )
      setGranted(true)
      setVersionId('')
      setPurpose('')
    } catch {
      setError(t.actionError)
    } finally {
      setBusy(false)
    }
  }

  if (!canGrant) {
    return (
      <div className="flex items-center gap-2 text-muted-foreground text-sm">
        <KeyRound aria-hidden="true" className="size-4" />
        {t.noActions}
      </div>
    )
  }

  return (
    <div className="max-w-md space-y-4">
      <h3 className="text-sm font-medium">{t.accessGrantTitle}</h3>
      <div className="grid gap-2">
        <Label htmlFor="document-grant-type">{t.grantTypeLabel}</Label>
        <Select value={grantType} onValueChange={(value) => setGrantType(value as 'preview' | 'download')}>
          <SelectTrigger id="document-grant-type">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="preview">{t.grantTypePreview}</SelectItem>
            <SelectItem value="download">{t.grantTypeDownload}</SelectItem>
          </SelectContent>
        </Select>
      </div>
      <div className="grid gap-2">
        <Label htmlFor="document-grant-version">{t.grantVersionLabel}</Label>
        <Select value={versionId} onValueChange={setVersionId}>
          <SelectTrigger id="document-grant-version">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {versions.map((version) => (
              <SelectItem key={version.id} value={version.id}>
                {version.file_name ?? version.id}
                {version.version_number ? ` (${t.versionNumber} ${version.version_number})` : ''}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>
      <div className="grid gap-2">
        <Label htmlFor="document-grant-purpose">{t.grantPurposeLabel}</Label>
        <Input
          id="document-grant-purpose"
          value={purpose}
          onChange={(event) => setPurpose(event.target.value)}
          disabled={busy}
          maxLength={500}
          placeholder={t.grantPurposePlaceholder}
        />
      </div>
      {error ? <p className="text-destructive text-sm" role="alert">{error}</p> : null}
      {granted ? <p className="text-sm">{t.grantCreated}</p> : null}
      <Button size="sm" onClick={() => void submit()} disabled={busy}>
        <KeyRound aria-hidden="true" />
        {t.accessGrantTitle}
      </Button>
    </div>
  )
}

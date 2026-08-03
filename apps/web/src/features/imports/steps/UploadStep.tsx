import { useState, type FormEvent } from 'react'
import { useLocale, useSessionToken } from '../../../app/session-context'
import { requestInit, unwrap } from '../../../api/http'
import * as generated from '../../../api/generated/cluster'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { importsCopy } from '../imports-copy'

const templateOptions: Array<[generated.ImportFileUploadTemplateCode, keyof typeof importsCopy.ar]> = [
  ['people_assignments', 'peopleAssignments'],
  ['facilities', 'facilities'],
  ['organization_units', 'organizationUnits'],
  ['positions', 'positions'],
]

export interface UploadSelection {
  file: File | null
  templateCode: generated.ImportFileUploadTemplateCode
}

export interface UploadedImportFile {
  quarantineId: string
  templateCode: generated.ImportFileUploadTemplateCode
  file: File
  generation?: number
}

interface UploadStepProps {
  generation?: number
  onUploaded: (upload: UploadedImportFile) => void
  onSelectionChange?: (selection: UploadSelection) => void
  onUploadStarted?: () => number | void
  onUploadFailed?: () => void
}

export function UploadStep({
  generation = 0,
  onUploaded,
  onSelectionChange,
  onUploadStarted,
  onUploadFailed,
}: UploadStepProps) {
  const locale = useLocale()
  const token = useSessionToken()
  const text = importsCopy[locale]
  const [file, setFile] = useState<File | null>(null)
  const [templateCode, setTemplateCode] = useState<generated.ImportFileUploadTemplateCode>('people_assignments')
  const [phase, setPhase] = useState<'ready' | 'uploading' | 'complete'>('ready')
  const [error, setError] = useState<'file' | 'invalid' | 'size' | 'upload' | null>(null)

  const busy = phase === 'uploading'
  const status = phase === 'uploading' ? text.uploading : phase === 'complete' ? text.uploadComplete : text.uploadReady
  const errorMessage =
    error === 'file' ? text.fileRequired : error === 'invalid' ? text.fileInvalid : error === 'size' ? text.fileTooLarge : text.uploadError

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!file) {
      setError('file')
      return
    }
    if (!file.name.toLowerCase().endsWith('.csv')) {
      setError('invalid')
      return
    }
    if (file.size < 1 || file.size > 10 * 1024 * 1024) {
      setError('size')
      return
    }
    setError(null)
    const uploadGeneration = onUploadStarted?.() ?? generation
    try {
      setPhase('uploading')
      const reference = unwrap<generated.ImportFileReference>(
        await generated.uploadOrganizationImportFile(
          {
            file,
            template_code: templateCode,
            import_type: 'csv',
          },
          requestInit(token, { command: true, idempotency: 'import-file' }),
        ),
      )
      onUploaded({
        quarantineId: reference.quarantine_object_id,
        templateCode,
        file,
        generation: uploadGeneration,
      })
      setPhase('complete')
    } catch {
      onUploadFailed?.()
      setPhase('ready')
      setError('upload')
    }
  }

  return (
    <div className="grid gap-4">
      <p className="text-muted-foreground text-sm">{text.uploadHelp}</p>
      <form className="grid gap-4" onSubmit={(event) => void submit(event)} noValidate>
        {error ? (
          <p className="text-destructive text-sm" role="alert">{errorMessage}</p>
        ) : null}
        <div className="grid gap-2">
          <Label htmlFor="import-upload-file">{text.file}</Label>
          <Input
            id="import-upload-file"
            type="file"
            accept=".csv,text/csv"
            aria-invalid={Boolean(error)}
            disabled={busy}
            onChange={(event) => {
              const nextFile = event.target.files?.[0] ?? null
              setFile(nextFile)
              setError(null)
              setPhase('ready')
              onSelectionChange?.({ file: nextFile, templateCode })
            }}
          />
        </div>
        <div className="grid gap-2">
          <Label htmlFor="import-upload-template">{text.template}</Label>
          <Select
            value={templateCode}
            onValueChange={(value) => {
              const nextTemplateCode = value as generated.ImportFileUploadTemplateCode
              if (nextTemplateCode === templateCode) return
              setTemplateCode(nextTemplateCode)
              setError(null)
              setPhase('ready')
              onSelectionChange?.({ file, templateCode: nextTemplateCode })
            }}
            disabled={busy}
          >
            <SelectTrigger id="import-upload-template">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {templateOptions.map(([value, key]) => (
                <SelectItem key={value} value={value}>
                  {text[key]}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
        <p role="status" aria-live="polite" aria-atomic="true" className="text-muted-foreground text-sm">
          {status}
        </p>
        <div>
          <Button type="submit" disabled={busy}>
            {busy ? status : text.upload}
          </Button>
        </div>
      </form>
    </div>
  )
}

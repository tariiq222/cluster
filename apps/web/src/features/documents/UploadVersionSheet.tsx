import { useCallback, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { FileUp, ShieldAlert, ShieldCheck } from 'lucide-react'
import * as generated from '../../api/generated/cluster'
import { ApiError, customFetch, requestInit, unwrap } from '../../api/http'
import { useLocale, useSessionToken } from '../../app/session-context'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { Progress } from '@/components/ui/progress'
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
} from '@/components/ui/sheet'
import { documentsCopy } from './documents-copy'

type UploadState = 'idle' | 'hashing' | 'initiating' | 'uploading' | 'checking' | 'completing' | 'done'

const PROGRESS_BY_STATE: Record<UploadState, number> = {
  idle: 0,
  hashing: 15,
  initiating: 30,
  uploading: 60,
  checking: 80,
  completing: 95,
  done: 100,
}

export function UploadVersionSheet({
  document,
  open,
  onOpenChange,
}: {
  document: { id: string; classification: string }
  open: boolean
  onOpenChange: (open: boolean) => void
}) {
  const locale = useLocale()
  const csrfToken = useSessionToken()
  const t = documentsCopy[locale]
  const queryClient = useQueryClient()

  const [file, setFile] = useState<File | null>(null)
  const [state, setState] = useState<UploadState>('idle')
  const [error, setError] = useState<string | null>(null)

  const uploading = state === 'hashing' || state === 'initiating' || state === 'uploading' || state === 'checking' || state === 'completing'

  const completeUpload = useMutation({
    mutationFn: async ({ uploadId, sha256, byteSize }: { uploadId: string; sha256: string; byteSize: number }) =>
      unwrap<generated.DocumentUploadCompletion>(
        await generated.completeDocumentUpload(
          uploadId,
          { sha256, byte_size: byteSize },
          requestInit(csrfToken, { command: true, idempotency: 'document-upload-complete' }),
        ),
      ),
  })

  const runUpload = useCallback(async () => {
    if (!file || uploading || !document) return
    const chosen = file
    setError(null)
    try {
      setState('hashing')
      const sha256 = await sha256ForFile(chosen)
      setState('initiating')
      const intent = unwrap<generated.DocumentUploadInitiated>(
        await generated.initiateDocumentUpload(
          {
            purpose: 'document_version',
            name: chosen.name,
            file_name: chosen.name,
            content_type: chosen.type || 'application/octet-stream',
            byte_size: chosen.size,
            sha256,
            classification: document.classification as generated.Classification,
            description: null,
          },
          requestInit(csrfToken, { command: true, idempotency: 'document-upload' }),
        ),
      )
      setState('uploading')
      const putResult = await customFetch(intent.upload_url, {
        method: 'PUT',
        body: chosen,
        headers: { 'Content-Type': chosen.type || 'application/octet-stream' },
      })
      if (putResult.status >= 400) {
        throw new ApiError(putResult.status, {
          type: 'about:blank',
          title: 'Upload to storage failed',
          status: putResult.status,
        })
      }
      setState('checking')
      const uploadStatus = unwrap<generated.DocumentUploadStatus>(
        await generated.getDocumentUploadStatus(intent.upload_id, requestInit(csrfToken)),
      )
      if (uploadStatus.scan_status === 'rejected') {
        setError(t.uploadRejected)
        setState('idle')
        return
      }
      setState('completing')
      const completion = await completeUpload.mutateAsync({
        uploadId: intent.upload_id,
        sha256,
        byteSize: chosen.size,
      })
      if (!completion.accepted) {
        setError(completion.failure_codes.length > 0 ? `${t.uploadRejected} (${completion.failure_codes.join(', ')})` : t.uploadRejected)
        setState('idle')
        return
      }
      setState('done')
      setFile(null)
      void queryClient.invalidateQueries({ queryKey: ['document-versions', document.id] })
    } catch (cause) {
      setError(errorMessage(cause, t.uploadError))
      setState('idle')
    }
  }, [completeUpload, csrfToken, document, file, queryClient, t.uploadError, t.uploadRejected, uploading])

  const statusCopy: Record<UploadState, string> = {
    idle: t.uploadIdle,
    hashing: t.uploadHashing,
    initiating: t.uploadInitiating,
    uploading: t.uploadUploading,
    checking: t.uploadChecking,
    completing: t.uploadCompleting,
    done: t.uploadDone,
  }

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent>
        <SheetHeader>
          <SheetTitle>{t.uploadTitle}</SheetTitle>
          <SheetDescription>{t.uploadIdle}</SheetDescription>
        </SheetHeader>
        <div className="grid gap-4">
          <div className="grid gap-2">
            <Label htmlFor="document-upload-file">{t.uploadFileLabel}</Label>
            <input
              id="document-upload-file"
              className="text-sm"
              type="file"
              onChange={(event) => {
                setFile(event.target.files?.[0] ?? null)
                setError(null)
                setState('idle')
              }}
              disabled={uploading}
            />
          </div>

          {state !== 'idle' ? (
            <div className="grid gap-2">
              <Progress value={PROGRESS_BY_STATE[state]} aria-label={statusCopy[state]} />
              <p className="text-muted-foreground text-sm">{statusCopy[state]}</p>
            </div>
          ) : null}

          {state === 'checking' ? (
            <Badge variant="outline" className="gap-1 self-start">
              <ShieldCheck aria-hidden="true" />
              {t.scanScanning}
            </Badge>
          ) : null}
          {state === 'done' ? (
            <Badge variant="outline" className="gap-1 self-start">
              <ShieldAlert aria-hidden="true" />
              {t.uploadDone}
            </Badge>
          ) : null}

          {error ? (
            <p className="text-destructive text-sm" role="alert">{error}</p>
          ) : null}

          <div>
            <Button onClick={() => void runUpload()} disabled={!file || uploading || state === 'done'}>
              <FileUp aria-hidden="true" />
              {t.uploadNow}
            </Button>
          </div>
        </div>
      </SheetContent>
    </Sheet>
  )
}

async function sha256ForFile(file: File): Promise<string> {
  const digest = await crypto.subtle.digest('SHA-256', await file.arrayBuffer())
  return Array.from(new Uint8Array(digest), (byte) => byte.toString(16).padStart(2, '0')).join('')
}

function errorMessage(error: unknown, fallback: string): string {
  if (error instanceof ApiError) {
    return error.problem.detail ?? error.problem.title ?? fallback
  }
  if (error instanceof Error && error.message) return error.message
  return fallback
}

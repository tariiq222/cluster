import { useCallback, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, ArrowRight, FileUp, ShieldAlert, ShieldCheck } from 'lucide-react'
import * as generated from '../../api/generated/cluster'
import { useDocument } from '../../api/hooks'
import { ApiError, customFetch, requestInit, stateFromError, unwrap } from '../../api/http'
import { useNavigate } from '../../app/navigation-context'
import { useLocale, useSessionToken } from '../../app/session-context'
import { PageHeader, PageLayout } from '@/components/page-layout'
import { DeniedState, ErrorState, LoadingState } from '@/components/states'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Progress } from '@/components/ui/progress'
import {
  FormSection,
  SingleRegionFormLayout,
} from '@/components/form-page-layout'
import { LocalizedFilePicker } from '@/components/localized-file-picker'
import { documentsCopy } from './documents-copy'
import type { DocumentRecord } from './tabs/DocumentPreviewTab'

/*
 * Full-page version upload (route `/documents/:documentId/versions/new`).
 * The document is fetched by id through the same `['document', documentId]`
 * query key the detail screen uses, so the page works after a hard refresh
 * and shares the cache with the detail screen. The page is the destination —
 * no Sheet. The upload flow itself is the sheet's flow moved verbatim:
 * hash → initiate (version-scoped) → signed upload → status → complete,
 * with the ONE progress surface the sheet had.
 *
 * The form uses the shared `SingleRegionFormLayout` so:
 *   • the native browser file control is visually replaced by a localized
 *     Button (choose/replace) that drives the real `<input type="file">`
 *     which stays in the DOM (sr-only) for tests and assistive tech;
 *   • the intake has a real `<form noValidate>` so Enter/action semantics
 *     work and the primary action is the form's `type="submit"` button.
 */

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

export function UploadVersionScreen({ documentId }: { documentId: string }) {
  const locale = useLocale()
  const csrfToken = useSessionToken()
  const navigate = useNavigate()
  const t = documentsCopy[locale]
  const queryClient = useQueryClient()

  const documentQuery = useDocument(documentId)
  const document = documentQuery.data as DocumentRecord | undefined

  const [file, setFile] = useState<File | null>(null)
  const [state, setState] = useState<UploadState>('idle')
  const [error, setError] = useState<string | null>(null)

  const uploading =
    state === 'hashing' ||
    state === 'initiating' ||
    state === 'uploading' ||
    state === 'checking' ||
    state === 'completing'

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
      // Versions attach to an existing document — the version-scoped
      // initiate (`POST /documents/{id}/versions`) is the correct entry
      // point, not the generic `initiateDocumentUpload` which would
      // create another document.
      const intent = unwrap<generated.DocumentUploadInitiated>(
        await generated.addDocumentVersion(
          document.id,
          {
            file_name: chosen.name,
            content_type: chosen.type || 'application/octet-stream',
            byte_size: chosen.size,
            sha256,
          },
          requestInit(csrfToken, { command: true, idempotency: 'document-upload' }),
        ),
      )
      setState('uploading')
      // The signed URL's HTTP method (often POST or PUT) and the
      // `required_headers` returned by the backend must be honored
      // verbatim — never substitute a hard-coded Content-Type.
      const putResult = await customFetch(intent.upload_url, {
        method: intent.method,
        body: chosen,
        headers: { ...intent.required_headers },
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
      void queryClient.invalidateQueries({ queryKey: ['document-versions', documentId] })
      navigate(`/documents/${documentId}`)
    } catch (cause) {
      setError(errorMessage(cause, t.uploadError))
      setState('idle')
    }
  }, [completeUpload, csrfToken, document, documentId, file, navigate, queryClient, t.uploadError, t.uploadRejected, uploading])

  const handleSubmit = (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    void runUpload()
  }

  const derived = documentQuery.isError ? stateFromError(documentQuery.error) : null

  if (documentQuery.isLoading) {
    return (
      <PageLayout data-testid="upload-version-screen">
        <LoadingState rows={4} announce={t.loading} />
      </PageLayout>
    )
  }

  if (documentQuery.isError && (derived === 'forbidden' || derived === 'not-found')) {
    // 403 and 404 collapse into the same shared, non-disclosing copy. The
    // server is the only guard; this branch is defense in depth.
    return (
      <PageLayout data-testid="upload-version-screen">
        <DeniedState locale={locale} />
      </PageLayout>
    )
  }

  if (documentQuery.isError || !document) {
    return (
      <PageLayout data-testid="upload-version-screen">
        <ErrorState
          locale={locale}
          onRetry={() => void documentQuery.refetch()}
          correlationId={
            documentQuery.error instanceof ApiError ? documentQuery.error.correlationId : null
          }
        />
      </PageLayout>
    )
  }

  const allowed = document.allowed_actions ?? []
  const canUpload = allowed.includes('add-version') || allowed.includes('initiate-upload')
  if (!canUpload) {
    return (
      <PageLayout data-testid="upload-version-screen">
        <DeniedState locale={locale} />
      </PageLayout>
    )
  }

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
    <PageLayout data-testid="upload-version-screen">
      <div>
        <Button
          variant="ghost"
          size="sm"
          onClick={() => navigate(`/documents/${documentId}`)}
          className="-ms-2"
        >
          {locale === 'ar' ? (
            <ArrowRight aria-hidden="true" />
          ) : (
            <ArrowLeft aria-hidden="true" />
          )}
          {t.back}
        </Button>
      </div>

      <PageHeader title={t.uploadTitle} description={t.uploadPageDescription} />

      <SingleRegionFormLayout
        testId="upload-version-form"
        onSubmit={handleSubmit}
        actions={
          <Button
            type="submit"
            disabled={!file || uploading || state === 'done'}
            data-testid="upload-version-submit"
          >
            <FileUp aria-hidden="true" />
            {t.uploadNow}
          </Button>
        }
      >
        <FormSection
          headingId="upload-version-section-file"
          title={t.uploadSectionHeading}
        >
          <LocalizedFilePicker
            inputId="document-upload-file"
            testIdPrefix="document-upload-file"
            label={t.uploadFileLabel}
            chooseLabel={t.chooseFile}
            replaceLabel={t.fileReplaceLabel}
            helpText={t.uploadIdle}
            chosenLabel={t.fileChosenLabel}
            file={file}
            disabled={uploading}
            onChange={(next) => {
              setFile(next)
              setError(null)
              setState('idle')
            }}
          />
        </FormSection>

        {state !== 'idle' ? (
          <div className="grid gap-2" data-testid="upload-version-progress">
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
          <Alert role="alert">
            <AlertTitle>{t.uploadError}</AlertTitle>
            <AlertDescription>{error}</AlertDescription>
          </Alert>
        ) : null}
      </SingleRegionFormLayout>
    </PageLayout>
  )
}

async function sha256ForFile(file: File): Promise<string> {
  const digest = await crypto.subtle.digest('SHA-256', await file.arrayBuffer())
  return Array.from(
    new Uint8Array(digest),
    (byte) => byte.toString(16).padStart(2, '0'),
  ).join('')
}

function errorMessage(error: unknown, fallback: string): string {
  if (error instanceof ApiError) {
    return error.problem.detail ?? error.problem.title ?? fallback
  }
  if (error instanceof Error && error.message) return error.message
  return fallback
}

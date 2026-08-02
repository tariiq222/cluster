import { useEffect, useMemo, useRef, useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { ArrowLeft, ArrowRight, FileUp, ShieldCheck } from 'lucide-react'
import * as generated from '../../api/generated/cluster'
import { ApiError, customFetch, requestInit, unwrap } from '../../api/http'
import { useNavigate } from '../../app/navigation-context'
import { usePrincipal } from '../../app/principal-context'
import { useLocale, useSessionToken } from '../../app/session-context'
import { PageHeader, PageLayout } from '@/components/page-layout'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/components/ui/form'
import { Input } from '@/components/ui/input'
import { Progress } from '@/components/ui/progress'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Textarea } from '@/components/ui/textarea'
import {
  FormActionStack,
  FormSection,
  ReviewSummary,
  TwoRegionFormLayout,
  type ReviewSummaryRow,
} from '@/components/form-page-layout'
import { LocalizedFilePicker } from '@/components/localized-file-picker'
import { documentsCopy } from './documents-copy'

/*
 * Full-page document intake. The page is the destination — no Sheet/Dialog.
 *
 * The form is built on the shared routed-form primitives so every routed
 * add/edit page renders the same way:
 *   • `TwoRegionFormLayout` is the actual <form> (no nested form element).
 *   • The main intake surface uses semantic `FormSection`s: ownership +
 *     protection, document metadata, first file.
 *   • The review surface uses another `FormSection` and a `ReviewSummary`
 *     fed by `form.watch` values with human labels and fallbacks.
 *   • The primary action lives in a `FormActionStack` (cancel + submit).
 *   • The file control is the shared `LocalizedFilePicker` so the visible
 *     affordance is localized and the native `<input type="file">` remains
 *     in the DOM (sr-only) for tests and assistive tech.
 *
 * The upload choreography is preserved verbatim: hash → initiate (version-
 * scoped through `initiateDocumentUpload` with `purpose: 'document_version'`)
 * → signed PUT → status → complete, with the file's `intent.method` and
 * `intent.required_headers` honored on the storage call. An in-session
 * retry reuses the same `Intent` so we never create a duplicate document.
 */

const MAX_FILE_BYTES = 1024 * 1024 * 1024 // 1 GiB, per contract maximum

type UploadStage =
  | 'idle'
  | 'hashing'
  | 'initiating'
  | 'uploading'
  | 'checking'
  | 'completing'
  | 'done'

const STAGE_PROGRESS: Record<UploadStage, number> = {
  idle: 0,
  hashing: 15,
  initiating: 30,
  uploading: 60,
  checking: 80,
  completing: 95,
  done: 100,
}

interface Intent {
  uploadId: string
  uploadUrl: string
  method: string
  requiredHeaders: Record<string, string>
  sha256: string
  byteSize: number
  fileName: string
  contentType: string
}

interface DocumentFormValues {
  title: string
  description: string
  classification: generated.Classification
}

export function DocumentCreateScreen() {
  const locale = useLocale()
  const csrfToken = useSessionToken()
  const principal = usePrincipal()
  const navigate = useNavigate()
  const t = documentsCopy[locale]

  const [file, setFile] = useState<File | null>(null)
  const [fileError, setFileError] = useState<string | null>(null)
  const [stage, setStage] = useState<UploadStage>('idle')
  const [error, setError] = useState<string | null>(null)
  const [intent, setIntent] = useState<Intent | null>(null)
  const [resumeNotice, setResumeNotice] = useState(false)

  const effectiveFacilityId = principal.effectiveScope?.scopeType === 'facility'
    ? principal.effectiveScope.scopeId
    : null
  const effectiveFacilityLabel = effectiveFacilityId
    ? principal.effectiveScope?.label ?? ''
    : ''

  // Build facility options from `availableScopes` and merge in the
  // current effective facility so a page-open succeeds even when the
  // backend has not advertised the active scope as swappable (e.g.
  // facility-only users with no scope switcher surface). Dedupe on id.
  const facilityOptions = useMemo(() => {
    const options = principal.availableScopes.filter(
      (scope) => scope.scopeType === 'facility',
    )
    if (
      effectiveFacilityId &&
      effectiveFacilityLabel &&
      !options.some((scope) => scope.scopeId === effectiveFacilityId)
    ) {
      options.unshift({
        scopeType: 'facility',
        scopeId: effectiveFacilityId,
        label: effectiveFacilityLabel,
      })
    }
    return options
  }, [principal.availableScopes, effectiveFacilityId, effectiveFacilityLabel])

  const facilitySelected = facilityOptions.some(
    (scope) => scope.scopeId === effectiveFacilityId,
  )

  const uploading =
    stage === 'hashing' ||
    stage === 'initiating' ||
    stage === 'uploading' ||
    stage === 'checking' ||
    stage === 'completing'

  const submitBlocked = !principal.scopeReady

  const schema = useMemo(
    () =>
      z.object({
        title: z
          .string()
          .trim()
          .max(255, t.titleTooLong),
        description: z
          .string()
          .max(2000, t.descriptionTooLong),
        classification: z.enum([
          generated.Classification.public,
          generated.Classification.internal,
          generated.Classification.confidential,
          generated.Classification.top_secret,
        ]),
      }),
    [t],
  )

  const form = useForm<DocumentFormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      title: '',
      description: '',
      classification: generated.Classification.internal,
    },
  })

  const titleValue = form.watch('title')
  const descriptionValue = form.watch('description')
  const classificationValue = form.watch('classification')

  // Reset the post-initiation resume intent if the file or facility changes;
  // the retained upload is only safe to resume against the same byte stream
  // and the same owning facility.
  const fileRef = useRef<string | null>(null)
  useEffect(() => {
    const key = file ? `${file.name}:${file.size}:${file.lastModified}` : null
    if (key !== fileRef.current) {
      fileRef.current = key
      setIntent(null)
      setResumeNotice(false)
    }
  }, [file])

  useEffect(() => {
    setIntent(null)
    setResumeNotice(false)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [effectiveFacilityId])

  const stageCopy: Record<UploadStage, string> = {
    idle: t.uploadIdle,
    hashing: t.uploadHashing,
    initiating: t.uploadInitiating,
    uploading: t.uploadUploading,
    checking: t.uploadChecking,
    completing: t.uploadCompleting,
    done: t.uploadDone,
  }

  const classifyLabel = (value: generated.Classification): string => {
    switch (value) {
      case generated.Classification.public:
        return t.classificationPublic
      case generated.Classification.internal:
        return t.classificationInternal
      case generated.Classification.confidential:
        return t.classificationConfidential
      case generated.Classification.top_secret:
        return t.classificationTopSecret
      default:
        return value
    }
  }

  const handleFileChange = (next: File | null) => {
    setFileError(null)
    if (!next) {
      setFile(null)
      return
    }
    if (next.size === 0) {
      setFile(null)
      setFileError(t.fileEmpty)
      return
    }
    if (next.size > MAX_FILE_BYTES) {
      setFile(null)
      setFileError(t.fileTooLarge)
      return
    }
    setFile(next)
    if (!titleValue.trim()) {
      form.setValue('title', next.name.replace(/\.[^./\\]+$/, ''), {
        shouldValidate: false,
        shouldDirty: true,
      })
    }
  }

  const handleFacilityPick = async (scopeId: string) => {
    if (scopeId === effectiveFacilityId) return
    setError(null)
    try {
      await principal.selectScope('facility', scopeId)
    } catch (cause) {
      setError(errorMessage(cause, t.intakeErrorGeneral))
    }
  }

  const performUpload = async (chosen: File, existing: Intent | null) => {
    let activeIntent = existing
    try {
      if (!activeIntent) {
        setStage('hashing')
        const sha256 = await sha256ForFile(chosen)
        setStage('initiating')
        const initiated = unwrap<generated.DocumentUploadInitiated>(
          await generated.initiateDocumentUpload(
            {
              purpose: generated.DocumentUploadInitiateRequestPurpose.document_version,
              name: titleValue.trim() || chosen.name,
              file_name: chosen.name,
              content_type: chosen.type || 'application/octet-stream',
              byte_size: chosen.size,
              sha256,
              classification: classificationValue,
              description: descriptionValue.trim() ? descriptionValue.trim() : null,
            },
            requestInit(csrfToken, {
              command: true,
              idempotency: 'document-create-upload',
            }),
          ),
        )
        activeIntent = {
          uploadId: initiated.upload_id,
          uploadUrl: initiated.upload_url,
          method: initiated.method || 'PUT',
          requiredHeaders: initiated.required_headers ?? {},
          sha256,
          byteSize: chosen.size,
          fileName: chosen.name,
          contentType: chosen.type || 'application/octet-stream',
        }
        setIntent(activeIntent)
        setResumeNotice(false)
      } else {
        setResumeNotice(true)
      }

      setStage('uploading')
      const putResult = await customFetch(activeIntent.uploadUrl, {
        method: activeIntent.method,
        body: chosen,
        headers: activeIntent.requiredHeaders,
      })
      if (putResult.status >= 400) {
        throw new ApiError(putResult.status, {
          type: 'about:blank',
          title: 'Upload to storage failed',
          status: putResult.status,
        })
      }

      setStage('checking')
      const status = unwrap<generated.DocumentUploadStatus>(
        await generated.getDocumentUploadStatus(
          activeIntent.uploadId,
          requestInit(csrfToken),
        ),
      )
      if (status.scan_status === 'rejected') {
        setError(t.scanRejectedIntake)
        setStage('idle')
        setFile(null)
        setIntent(null)
        return
      }

      setStage('completing')
      const completion = unwrap<generated.DocumentUploadCompletion>(
        await generated.completeDocumentUpload(
          activeIntent.uploadId,
          { sha256: activeIntent.sha256, byte_size: activeIntent.byteSize },
          requestInit(csrfToken, {
            command: true,
            idempotency: 'document-upload-complete',
          }),
        ),
      )
      if (!completion.accepted) {
        // Localized rejection only — raw failure_codes are diagnostic
        // signals for the backend; surfacing them would leak internal
        // codes into the user-facing message.
        setError(t.scanRejectedIntake)
        setStage('idle')
        setFile(null)
        setIntent(null)
        return
      }
      setStage('done')
      setIntent(null)
      setFile(null)
      navigate(`/documents/${completion.document_id}`)
    } catch (cause) {
      // Preserve intent for retry: if we already initiated, keep the
      // uploadId/uploadUrl/sha256 so the next submit reuses the same
      // server-side upload without creating a new document.
      setStage('idle')
      setError(intakeErrorMessage(cause))
    }
  }

  const submit = form.handleSubmit(async () => {
    setError(null)
    if (!chosenFileOrError()) return
    if (!facilitySelected) {
      setError(t.owningFacilityNoneBody)
      return
    }
    await performUpload(file!, intent)
  })

  function chosenFileOrError(): boolean {
    if (!file) {
      setFileError(t.fileRequired)
      return false
    }
    if (file.size === 0) {
      setFileError(t.fileEmpty)
      return false
    }
    if (file.size > MAX_FILE_BYTES) {
      setFileError(t.fileTooLarge)
      return false
    }
    setFileError(null)
    return true
  }

  function intakeErrorMessage(cause: unknown): string {
    if (cause instanceof ApiError) {
      switch (cause.status) {
        case 400:
          return t.intakeErrorMetadata
        case 401:
        case 403:
          return t.intakeErrorAuth
        case 409:
        case 412:
          return t.intakeErrorConflict
        default:
          return cause.problem.detail ?? cause.problem.title ?? t.intakeErrorGeneral
      }
    }
    if (cause instanceof Error && cause.message) return cause.message
    return t.intakeErrorGeneral
  }

  // ─────────────────────────── render ───────────────────────────

  if (facilityOptions.length === 0) {
    return (
      <PageLayout data-testid="document-create-screen">
        <div>
          <Button
            variant="ghost"
            size="sm"
            onClick={() => navigate('/documents')}
            className="-ms-2"
          >
            {locale === 'ar' ? (
              <ArrowRight aria-hidden="true" data-testid="document-create-back-icon" />
            ) : (
              <ArrowLeft aria-hidden="true" data-testid="document-create-back-icon" />
            )}
            {t.back}
          </Button>
        </div>

        <PageHeader
          title={t.createPageTitle}
          description={t.createPageDescription}
        />

        <div
          className="mx-auto w-full max-w-2xl rounded-lg border p-4"
          data-testid="document-create-blocked"
        >
          <Alert role="alert">
            <AlertTitle>{t.owningFacilityNoneTitle}</AlertTitle>
            <AlertDescription>{t.owningFacilityNoneBody}</AlertDescription>
          </Alert>
          <div className="mt-4">
            <Button variant="outline" onClick={() => navigate('/documents')}>
              {t.returnToDocuments}
            </Button>
          </div>
        </div>
      </PageLayout>
    )
  }

  const reviewRows: ReviewSummaryRow[] = [
    {
      label: t.reviewTitleLabel,
      value: titleValue.trim() || null,
      empty: (
        <span className="text-muted-foreground">{t.reviewTitleFallback}</span>
      ),
      isolate: true,
    },
    {
      label: t.reviewClassificationLabel,
      value: classifyLabel(classificationValue),
    },
    {
      label: t.reviewFacilityLabel,
      value: effectiveFacilityLabel || null,
      empty: (
        <span className="text-muted-foreground">
          {t.owningFacilityPlaceholder}
        </span>
      ),
      isolate: true,
    },
    {
      label: t.reviewFileLabel,
      value: file
        ? (
          <>
            <bdi dir="auto" className="break-all">
              {file.name}
            </bdi>
            {' · '}
            {formatBytes(file.size)}
          </>
        )
        : null,
      empty: <span className="text-muted-foreground">{t.fileRequired}</span>,
    },
    {
      label: t.reviewPolicyLabel,
      value: t.reviewPolicyValue,
    },
  ]

  return (
    <PageLayout data-testid="document-create-screen">
      <div>
        <Button
          variant="ghost"
          size="sm"
          onClick={() => navigate('/documents')}
          className="-ms-2"
        >
          {locale === 'ar' ? (
            <ArrowRight aria-hidden="true" data-testid="document-create-back-icon" />
          ) : (
            <ArrowLeft aria-hidden="true" data-testid="document-create-back-icon" />
          )}
          {t.back}
        </Button>
      </div>

      <PageHeader title={t.createPageTitle} description={t.createPageDescription} />

      <Form {...form}>
        <TwoRegionFormLayout
          onSubmit={(event) => void submit(event)}
          testId="document-create-form"
          reviewTestId="document-create-review-panel"
          main={
            <>
              <FormSection
                headingId="document-create-section-facility"
                title={t.ownershipSectionHeading}
              >
                <div className="grid gap-1" data-testid="document-create-owner">
                  <label
                    htmlFor="document-create-owner-select"
                    className="text-sm font-medium"
                  >
                    {t.owningFacilityLabel}
                  </label>
                  <Select
                    value={effectiveFacilityId ?? ''}
                    onValueChange={(value) => void handleFacilityPick(value)}
                    disabled={uploading || principal.scopeReady === false}
                  >
                    <SelectTrigger
                      id="document-create-owner-select"
                      className="w-full"
                    >
                      <SelectValue placeholder={t.owningFacilityPlaceholder} />
                    </SelectTrigger>
                    <SelectContent>
                      {facilityOptions.map((option) => (
                        <SelectItem
                          key={option.scopeId}
                          value={option.scopeId}
                          data-testid={`document-create-owner-option-${option.scopeId}`}
                        >
                          {option.label}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  <p className="text-muted-foreground text-xs">
                    {principal.scopeReady
                      ? effectiveFacilityLabel
                        ? `${t.owningFacilityCurrent}: ${effectiveFacilityLabel}`
                        : t.owningFacilityPlaceholder
                      : t.owningFacilitySwitching}
                  </p>
                </div>

                <div
                  className="text-muted-foreground flex items-start gap-2 text-sm"
                  data-testid="document-create-policy"
                >
                  <ShieldCheck aria-hidden="true" className="mt-0.5 size-4 shrink-0" />
                  <p>{t.policyAutomaticBody}</p>
                </div>
              </FormSection>

              <FormSection
                headingId="document-create-section-basic"
                title={t.metadataTitle}
                divided
              >
                <div className="grid gap-4">
                  <FormField
                    control={form.control}
                    name="title"
                    render={({ field }) => (
                      <FormItem>
                        <FormLabel htmlFor="document-create-title">
                          {t.createTitleLabel}
                        </FormLabel>
                        <FormControl>
                          <Input
                            id="document-create-title"
                            autoComplete="off"
                            disabled={uploading}
                            maxLength={255}
                            placeholder={t.createTitlePlaceholder}
                            data-testid="document-create-title-input"
                            {...field}
                          />
                        </FormControl>
                        <FormMessage role="alert" />
                      </FormItem>
                    )}
                  />

                  <FormField
                    control={form.control}
                    name="description"
                    render={({ field }) => (
                      <FormItem>
                        <FormLabel htmlFor="document-create-description">
                          {t.descriptionLabel}
                        </FormLabel>
                        <FormControl>
                          <Textarea
                            id="document-create-description"
                            autoComplete="off"
                            disabled={uploading}
                            maxLength={2000}
                            placeholder={t.descriptionPlaceholder}
                            rows={3}
                            data-testid="document-create-description-input"
                            {...field}
                          />
                        </FormControl>
                        <FormMessage role="alert" />
                      </FormItem>
                    )}
                  />

                  <FormField
                    control={form.control}
                    name="classification"
                    render={({ field }) => (
                      <FormItem>
                        <FormLabel htmlFor="document-create-classification">
                          {t.classificationLabel}
                        </FormLabel>
                        <Select
                          value={field.value}
                          onValueChange={(value) =>
                            field.onChange(value as generated.Classification)
                          }
                          disabled={uploading}
                        >
                          <FormControl>
                            <SelectTrigger
                              id="document-create-classification"
                              className="w-full"
                            >
                              <SelectValue />
                            </SelectTrigger>
                          </FormControl>
                          <SelectContent>
                            <SelectItem value={generated.Classification.public}>
                              {t.classificationPublic}
                            </SelectItem>
                            <SelectItem value={generated.Classification.internal}>
                              {t.classificationInternal}
                            </SelectItem>
                            <SelectItem
                              value={generated.Classification.confidential}
                            >
                              {t.classificationConfidential}
                            </SelectItem>
                            <SelectItem
                              value={generated.Classification.top_secret}
                            >
                              {t.classificationTopSecret}
                            </SelectItem>
                          </SelectContent>
                        </Select>
                        <FormMessage role="alert" />
                      </FormItem>
                    )}
                  />
                </div>
              </FormSection>

              <FormSection
                headingId="document-create-section-file"
                title={t.fileSectionHeading}
                divided
              >
                <LocalizedFilePicker
                  inputId="document-create-file"
                  testIdPrefix="document-create-file"
                  label={t.fileLabel}
                  chooseLabel={t.chooseFile}
                  replaceLabel={t.fileReplaceLabel}
                  helpText={t.fileMaxSize}
                  chosenLabel={t.fileChosenLabel}
                  file={file}
                  disabled={uploading}
                  error={fileError}
                  onChange={handleFileChange}
                />
              </FormSection>
            </>
          }
          review={
            <>
              <FormSection
                headingId="document-create-section-review"
                title={t.reviewTitle}
                testId="document-create-review"
                density="tight"
              >
                <ReviewSummary rows={reviewRows} />
              </FormSection>

              {resumeNotice ? (
                <p
                  role="status"
                  className="text-muted-foreground text-xs"
                  data-testid="document-create-resume-notice"
                >
                  {t.resumeHint}
                </p>
              ) : null}

              {error ? (
                <Alert role="alert" data-testid="document-create-error">
                  <AlertTitle>{t.intakeErrorGeneral}</AlertTitle>
                  <AlertDescription>{error}</AlertDescription>
                </Alert>
              ) : null}

              {stage !== 'idle' ? (
                <div
                  className="grid gap-2"
                  data-testid="document-create-progress"
                  role="status"
                  aria-live="polite"
                >
                  <Progress
                    value={STAGE_PROGRESS[stage]}
                    aria-label={stageCopy[stage]}
                    aria-valuetext={stageCopy[stage]}
                  />
                  <p className="text-muted-foreground text-sm">
                    {stageCopy[stage]}
                  </p>
                  {stage === 'checking' ? (
                    <Badge variant="outline" className="gap-1 self-start">
                      <ShieldCheck aria-hidden="true" />
                      {t.scanScanning}
                    </Badge>
                  ) : null}
                </div>
              ) : null}

              <FormActionStack>
                <Button
                  type="submit"
                  className="w-full"
                  disabled={
                    uploading ||
                    !facilitySelected ||
                    submitBlocked
                  }
                  data-testid="document-create-submit"
                >
                  <FileUp aria-hidden="true" />
                  {uploading ? t.submitting : t.submitCreate}
                </Button>
                <Button
                  type="button"
                  variant="outline"
                  className="w-full"
                  disabled={uploading}
                  onClick={() => navigate('/documents')}
                >
                  {t.cancel}
                </Button>
              </FormActionStack>
            </>
          }
        />
      </Form>
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

function formatBytes(value: number): string {
  if (value < 1024) return `${value} B`
  const units = ['KB', 'MB', 'GB']
  let current = value / 1024
  let unitIndex = 0
  while (current >= 1024 && unitIndex < units.length - 1) {
    current /= 1024
    unitIndex += 1
  }
  return `${current.toFixed(current >= 100 ? 0 : 1)} ${units[unitIndex]}`
}

function errorMessage(error: unknown, fallback: string): string {
  if (error instanceof ApiError) {
    return error.problem.detail ?? error.problem.title ?? fallback
  }
  if (error instanceof Error && error.message) return error.message
  return fallback
}

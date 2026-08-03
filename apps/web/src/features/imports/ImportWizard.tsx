import { useCallback, useEffect, useRef, useState } from 'react'
import { useLocale, useSessionToken } from '../../app/session-context'
import { ApiError, requestInit, unwrap, unwrapWithEtag, uuidV7 } from '../../api/http'
import { formatNumber } from '../../i18n'
import * as generated from '../../api/generated/cluster'
import { PageHeader, PageLayout } from '@/components/page-layout'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { DeniedState, ErrorState, LoadingState } from '@/components/states'
import { importsCopy } from './imports-copy'
import { UploadStep, type UploadSelection, type UploadedImportFile } from './steps/UploadStep'
import { ValidateStep } from './steps/ValidateStep'
import { ReviewStep } from './steps/ReviewStep'
import { CommitStep } from './steps/CommitStep'

type JobAction = 'validate' | 'approve' | 'reject' | 'apply' | 'cancel'

const UUID_V7 = /^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/
const DEFAULT_TEMPLATE_CODE: generated.ImportJobCreateTemplateCode = 'people_assignments'

type UploadedArtifact = {
  quarantineId: string
  templateCode: generated.ImportJobCreateTemplateCode
  generation: number
  idempotencyKey: string
}

const STEP_ORDER = ['upload', 'validate', 'review', 'commit'] as const
const STEP_LABELS: Record<(typeof STEP_ORDER)[number], 'stepUpload' | 'stepValidate' | 'stepReview' | 'stepCommit'> = {
  upload: 'stepUpload',
  validate: 'stepValidate',
  review: 'stepReview',
  commit: 'stepCommit',
}

export function ImportWizard({ jobId }: { jobId?: string }) {
  const locale = useLocale()
  const token = useSessionToken()
  const text = importsCopy[locale]
  const [activeJobId, setActiveJobId] = useState<string | undefined>(jobId)
  const [job, setJob] = useState<generated.ImportJob | null>(null)
  const [rows, setRows] = useState<generated.ImportJobRow[]>([])
  const [loading, setLoading] = useState(Boolean(jobId))
  const [state, setState] = useState<'ready' | 'forbidden' | 'not-found' | 'error'>('ready')
  const [staleMessage, setStaleMessage] = useState<string | null>(null)
  const [selection, setSelection] = useState<UploadSelection>({
    file: null,
    templateCode: DEFAULT_TEMPLATE_CODE,
  })
  const [artifact, setArtifact] = useState<UploadedArtifact | null>(null)
  const [quarantineId, setQuarantineId] = useState('')
  const [generation, setGeneration] = useState(0)
  const [submitting, setSubmitting] = useState(false)
  const [submitError, setSubmitError] = useState<'validation' | 'save' | null>(null)
  const [transitioning, setTransitioning] = useState(false)
  const loadRequestRef = useRef(0)
  const generationRef = useRef(0)
  const selectionRef = useRef<UploadSelection>({
    file: null,
    templateCode: DEFAULT_TEMPLATE_CODE,
  })
  const artifactRef = useRef<UploadedArtifact | null>(null)
  const submitInFlightRef = useRef<string | null>(null)

  const load = useCallback(
    async (jobIdToLoad: string | undefined = activeJobId) => {
      const epoch = ++loadRequestRef.current
      if (!jobIdToLoad) {
        if (epoch !== loadRequestRef.current) return
        setJob(null)
        setRows([])
        setLoading(false)
        setState('ready')
        return
      }
      setLoading(true)
      setState('ready')
      try {
        const [jobValue, rowPage] = await Promise.all([
          unwrap<generated.ImportJob>(await generated.getOrganizationImport(jobIdToLoad, requestInit(token))),
          unwrap<generated.ImportJobRowCollection>(
            await generated.listOrganizationImportRows(jobIdToLoad, { limit: 100 }, requestInit(token)),
          ),
        ])
        if (epoch !== loadRequestRef.current) return
        setJob(jobValue)
        setRows(rowPage.items)
        setStaleMessage(null)
      } catch (caught) {
        if (epoch !== loadRequestRef.current) return
        setJob(null)
        setRows([])
        if (caught instanceof ApiError && (caught.status === 403 || caught.status === 404)) {
          setState(caught.status === 403 ? 'forbidden' : 'not-found')
        } else {
          setState('error')
        }
      } finally {
        if (epoch === loadRequestRef.current) setLoading(false)
      }
    },
    [activeJobId, token],
  )

  useEffect(() => {
    void load(jobId)
  }, [jobId, load])

  const advanceGeneration = useCallback(() => {
    const next = generationRef.current + 1
    generationRef.current = next
    setGeneration(next)
    return next
  }, [])

  const invalidateArtifact = useCallback(
    (clearReference = true) => {
      const next = advanceGeneration()
      artifactRef.current = null
      setArtifact(null)
      setSubmitError(null)
      if (clearReference) setQuarantineId('')
      if (submitInFlightRef.current !== null) {
        submitInFlightRef.current = null
        setSubmitting(false)
      }
      return next
    },
    [advanceGeneration],
  )

  const artifactMatchesSelection = useCallback((candidate: UploadedArtifact | null) => {
    const currentSelection = selectionRef.current
    return candidate !== null
      && candidate.generation === generationRef.current
      && currentSelection.file !== null
      && candidate.templateCode === currentSelection.templateCode
  }, [])

  const handleSelectionChange = useCallback(
    (nextSelection: UploadSelection) => {
      selectionRef.current = nextSelection
      setSelection(nextSelection)
      invalidateArtifact()
    },
    [invalidateArtifact],
  )

  const handleUploadStarted = useCallback(() => invalidateArtifact(), [invalidateArtifact])

  const handleUploadFailed = useCallback(() => {
    invalidateArtifact()
  }, [invalidateArtifact])

  const handleUploaded = useCallback((upload: UploadedImportFile) => {
    const currentSelection = selectionRef.current
    const uploadGeneration = upload.generation ?? generationRef.current
    if (
      uploadGeneration !== generationRef.current
      || upload.file !== currentSelection.file
      || upload.templateCode !== currentSelection.templateCode
    ) {
      return
    }

    const nextArtifact: UploadedArtifact = {
      quarantineId: upload.quarantineId,
      templateCode: upload.templateCode as generated.ImportJobCreateTemplateCode,
      generation: uploadGeneration,
      idempotencyKey: `import-submit-${uuidV7()}`,
    }
    artifactRef.current = nextArtifact
    setArtifact(nextArtifact)
    setQuarantineId(upload.quarantineId)
    setSubmitError(null)
  }, [])

  const handleQuarantineIdChange = useCallback(
    (value: string) => {
      setQuarantineId(value)
      const currentArtifact = artifactRef.current
      if (currentArtifact !== null && value !== currentArtifact.quarantineId) {
        invalidateArtifact(false)
      }
      setSubmitError(null)
    },
    [invalidateArtifact],
  )

  const handleSubmit = useCallback(async () => {
    const currentArtifact = artifactRef.current
    if (!currentArtifact || !artifactMatchesSelection(currentArtifact) || !UUID_V7.test(currentArtifact.quarantineId)) {
      setSubmitError('validation')
      return
    }

    const idempotencyKey = currentArtifact.idempotencyKey
    if (submitInFlightRef.current === idempotencyKey) return

    submitInFlightRef.current = idempotencyKey
    setSubmitting(true)
    setSubmitError(null)
    let accepted = false
    try {
      const created = unwrap<generated.ImportJob>(
        await generated.submitOrganizationImport(
          {
            quarantine_object_id: currentArtifact.quarantineId,
            template_code: currentArtifact.templateCode,
            import_type: 'csv',
          },
          requestInit(token, { command: true, idempotencyKey }),
        ),
      )
      if (!artifactMatchesSelection(currentArtifact)) return
      accepted = true
      invalidateArtifact()
      setActiveJobId(created.id)
      await load(created.id)
    } catch {
      if (!accepted && artifactMatchesSelection(currentArtifact)) {
        setSubmitError('save')
      }
    } finally {
      if (submitInFlightRef.current === idempotencyKey) {
        submitInFlightRef.current = null
        setSubmitting(false)
      }
    }
  }, [artifactMatchesSelection, invalidateArtifact, load, token])

  const handleTransition = useCallback(
    async (action: JobAction, reason?: string) => {
      if (!job) return
      setTransitioning(true)
      setStaleMessage(null)
      try {
        const { etag } = unwrapWithEtag<generated.ImportJob>(
          await generated.getOrganizationImport(job.id, requestInit(token)),
        )
        if (!etag) throw new ApiError(502, { type: 'about:blank', title: 'Missing import version', status: 502 })
        const changed = unwrap<generated.ImportJob>(
          await generated.transitionOrganizationImport(
            job.id,
            action,
            reason ? { reason } : {},
            requestInit(token, { command: true, idempotency: `import-${action}`, lockVersion: etag }),
          ),
        )
        setJob(changed)
        await load(job.id)
      } catch (caught) {
        if (caught instanceof ApiError && caught.status === 412) {
          setStaleMessage(text.stale)
          await load(job.id)
        } else {
          setStaleMessage(text.transitionError)
        }
      } finally {
        setTransitioning(false)
      }
    },
    [job, load, text.stale, text.transitionError, token],
  )

  const currentStep =
    job === null ? 'upload' : job.status === 'received' ? 'validate' : job.status === 'validated' ? 'review' : job.status === 'approved' ? 'commit' : 'review'
  const stepIndex = STEP_ORDER.indexOf(currentStep)
  // The submit button is strictly gated on the latest upload succeeding AND the
  // visible file/template selection still matching the artifact. Any drift
  // (file changed, template changed, stale manual reference) must invalidate
  // the artifact and force a re-upload; there is no manual bypass.
  const validateEnabled = artifact !== null
    && artifact.generation === generation
    && selection.file !== null
    && artifact.templateCode === selection.templateCode

  /*
   * Every wizard branch — loading, denied, error, and ready — renders inside
   * the shared PageLayout shell so the wizard is visually anchored to the
   * same outer column the rest of the workspace uses.
   */
  return (
    <PageLayout>
      <PageHeader title={text.title} description={text.intro} />

      {loading ? (
        <LoadingState rows={4} announce={text.loading} />
      ) : state === 'forbidden' || state === 'not-found' ? (
        // DESIGN-RULES §4.3: 403 and 404 render the exact same non-disclosing
        // surface so a forbidden resource and a missing resource are
        // indistinguishable — the difference would be a resource-existence leak.
        <DeniedState locale={locale} />
      ) : state === 'error' ? (
        <ErrorState
          locale={locale}
          onRetry={() => void load()}
        />
      ) : (
        <div className="space-y-4">
          <div className="flex items-center gap-2">
            {STEP_ORDER.map((step, index) => (
              <div key={step} className="flex items-center gap-2">
                <Badge variant={index <= stepIndex ? 'default' : 'outline'}>
                  {index + 1}
                </Badge>
                <span className={index <= stepIndex ? 'text-sm font-medium' : 'text-muted-foreground text-sm'}>
                  {text[STEP_LABELS[step]]}
                </span>
                {index < STEP_ORDER.length - 1 ? <span className="text-muted-foreground">—</span> : null}
              </div>
            ))}
          </div>

          {staleMessage ? (
            <p className="text-destructive text-sm" role="status">{staleMessage}</p>
          ) : null}

          {job === null ? (
            <UploadStep
              generation={generation}
              onSelectionChange={handleSelectionChange}
              onUploadStarted={handleUploadStarted}
              onUploadFailed={handleUploadFailed}
              onUploaded={handleUploaded}
            />
          ) : null}

          {job === null ? (
            <ValidateStep
              quarantineId={quarantineId}
              onQuarantineIdChange={handleQuarantineIdChange}
              onSubmit={handleSubmit}
              submitting={submitting}
              disabled={!validateEnabled}
              error={submitError}
            />
          ) : null}



          {job !== null && job.status === 'received' ? (
            <div className="space-y-2">
              <p role="status" className="text-sm">{text.received}</p>
              <Button size="sm" onClick={() => void handleTransition('validate')} disabled={transitioning}>
                {transitioning ? text.executing : text.validate}
              </Button>
            </div>
          ) : null}

          {job !== null && (job.status === 'validated' || job.status === 'approved') ? (
            <ReviewStep
              rows={rows.map((row) => ({
                id: row.id,
                row_number: row.row_number,
                proposed_action: row.proposed_action ?? undefined,
                decision: row.decision,
                validation_errors: row.validation_errors,
              }))}
              status={job.status}
              busy={transitioning}
              onTransition={(action, reason) => void handleTransition(action, reason)}
            />
          ) : null}

          {job !== null && job.status === 'approved' ? (
            <CommitStep
              totalRows={job.total_rows}
              validRows={job.valid_rows}
              errorRows={job.error_rows}
              status={job.status}
              busy={transitioning}
              onApply={() => void handleTransition('apply')}
              onCancelImport={() => void handleTransition('cancel', text.cancelled)}
            />
          ) : null}

          {job !== null && job.status === 'applied' ? (
            <p role="status" className="text-sm">
              {text.applied} · {formatNumber(job.total_rows, locale)}
            </p>
          ) : null}
        </div>
      )}
    </PageLayout>
  )
}

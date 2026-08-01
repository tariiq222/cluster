import { useCallback, useEffect, useRef, useState } from 'react'
import { useLocale, useSessionToken } from '../../app/session-context'
import { ApiError, requestInit, unwrap, unwrapWithEtag } from '../../api/http'
import { formatNumber } from '../../i18n'
import * as generated from '../../api/generated/cluster'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { importsCopy } from './imports-copy'
import { UploadStep } from './steps/UploadStep'
import { ValidateStep } from './steps/ValidateStep'
import { ReviewStep } from './steps/ReviewStep'
import { CommitStep } from './steps/CommitStep'

type JobAction = 'validate' | 'approve' | 'reject' | 'apply' | 'cancel'

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
  const [quarantineId, setQuarantineId] = useState('')
  const [submitError, setSubmitError] = useState<'validation' | 'save' | null>(null)
  const [transitioning, setTransitioning] = useState(false)
  const loadRequestRef = useRef(0)

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

  if (loading) {
    return (
      <div className="space-y-3">
        <div className="h-10 w-full animate-pulse rounded-md bg-muted" />
        <div className="h-10 w-full animate-pulse rounded-md bg-muted" />
      </div>
    )
  }

  if (state === 'forbidden' || state === 'not-found' || state === 'error') {
    return (
      <div className="space-y-2">
        <p className="text-destructive text-sm" role="alert">{text.error}</p>
        <Button variant="outline" size="sm" onClick={() => void load()}>
          {text.retry}
        </Button>
      </div>
    )
  }

  return (
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
        <UploadStep onUploaded={setQuarantineId} />
      ) : null}

      {job === null ? (
        <ValidateStep
          quarantineId={quarantineId}
          onQuarantineIdChange={(value) => {
            setQuarantineId(value)
            setSubmitError(null)
          }}
          onSubmitted={async (created) => {
            setActiveJobId(created.id)
            await load(created.id)
          }}
          submitting={false}
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
  )
}

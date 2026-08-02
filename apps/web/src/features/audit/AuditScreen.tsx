import { useCallback, useEffect, useMemo, useState, type FormEvent } from 'react'
import { flexRender, getCoreRowModel, useReactTable, type ColumnDef } from '@tanstack/react-table'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import {
  CheckCircle2,
  CircleAlert,
  ShieldAlert,
  ShieldCheck,
  ShieldQuestion,
  ShieldX,
} from 'lucide-react'
import { toast } from 'sonner'
import * as generated from '../../api/generated/cluster'
import type { AuditEvent, AuditExportDescriptor, AuditIntegrityResult } from '../../api/generated/cluster'
import { ApiError, requestInit, unwrap } from '../../api/http'
import { useAuditEvents } from '../../api/hooks'
import { useNavigate } from '../../app/navigation-context'
import { usePrincipal } from '../../app/principal-context'
import { useLocale, useSession, useSessionToken } from '../../app/session-context'
import { formatDate } from '../../i18n'
import { registerExport } from '../reports/export-tracker'
import { queryFromFilters, type FilterDraft } from './audit-utils'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Label } from '@/components/ui/label'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { DeniedState, EmptyState, ErrorState, LoadingState } from '@/components/states'

const copy = {
  ar: {
    title: 'سجل التدقيق',
    description: 'استعرض سجلًا غير قابل للتغيير، نزّل لقطات مبررة، وتحقق من سلامة السلاسل دون كشف مواد التجزئة.',
    filters: 'تصفية السجل',
    sourceModule: 'الوحدة المصدر',
    action: 'الإجراء',
    classification: 'التصنيف',
    allClassifications: 'كل التصنيفات',
    from: 'وقع من',
    to: 'وقع إلى',
    apply: 'تطبيق المرشحات',
    clear: 'مسح',
    ledger: 'الأحداث',
    loading: 'جارٍ تحميل سجل التدقيق',
    loadFailed: 'تعذر تحميل أحداث التدقيق.',
    retry: 'إعادة المحاولة',
    empty: 'لا توجد أحداث مطابقة',
    emptyBody: 'غيّر المرشحات أو وسّع النطاق الزمني.',
    occurred: 'وقت الحدث',
    actor: 'المنفذ',
    subject: 'الموضوع',
    outcome: 'النتيجة',
    integrity: 'السلامة',
    inspect: 'فحص الحدث',
    loadMore: 'تحميل المزيد',
    exportTitle: 'تصدير لقطة',
    exportReason: 'سبب التصدير',
    exportReasonHelp: 'سبب مهني محدد؛ يُسجل مع طلب التصدير.',
    format: 'التنسيق',
    createExport: 'إنشاء التصدير',
    exportQueued: 'جارٍ تجهيز تصدير سجل التدقيق…',
    exportFailed: 'تعذر إنشاء التصدير.',
    expires: 'ينتهي',
    events: 'حدث',
    verifyTitle: 'التحقق من سلامة سلسلة',
    streamKey: 'مفتاح السلسلة',
    firstSequence: 'أول تسلسل (اختياري)',
    lastSequence: 'آخر تسلسل (اختياري)',
    rangeHelp: 'أدخل الحدين معًا، أو اتركهما فارغين للتحقق من السلسلة المتاحة.',
    verify: 'تحقق الآن',
    verified: 'تم التحقق',
    range: 'النطاق',
    pairRequired: 'يجب إدخال أول وآخر تسلسل معًا.',
    operationFailed: 'تعذر إكمال العملية.',
    notAvailable: 'غير متاح',
    system: 'النظام',
  },
  en: {
    title: 'Audit ledger',
    description:
      'Inspect the immutable ledger, download reason-bound snapshots, and verify stream integrity without exposing hash material.',
    filters: 'Filter ledger',
    sourceModule: 'Source module',
    action: 'Action',
    classification: 'Classification',
    allClassifications: 'All classifications',
    from: 'Occurred from',
    to: 'Occurred to',
    apply: 'Apply filters',
    clear: 'Clear',
    ledger: 'Events',
    loading: 'Loading audit ledger',
    loadFailed: 'Audit events could not be loaded.',
    retry: 'Retry',
    empty: 'No matching events',
    emptyBody: 'Change the filters or widen the time range.',
    occurred: 'Occurred',
    actor: 'Actor',
    subject: 'Subject',
    outcome: 'Outcome',
    integrity: 'Integrity',
    inspect: 'Inspect event',
    loadMore: 'Load more',
    exportTitle: 'Export snapshot',
    exportReason: 'Export reason',
    exportReasonHelp: 'State a specific business purpose; it is recorded with the export request.',
    format: 'Format',
    createExport: 'Create export',
    exportQueued: 'Preparing audit export…',
    exportFailed: 'The export could not be created.',
    expires: 'Expires',
    events: 'events',
    verifyTitle: 'Verify stream integrity',
    streamKey: 'Stream key',
    firstSequence: 'First sequence (optional)',
    lastSequence: 'Last sequence (optional)',
    rangeHelp: 'Enter both bounds, or leave both empty to verify the available stream.',
    verify: 'Verify now',
    verified: 'Verification complete',
    range: 'Range',
    pairRequired: 'First and last sequence must be supplied together.',
    operationFailed: 'The operation could not be completed.',
    notAvailable: 'Not available',
    system: 'System',
  },
} as const

const EMPTY_FILTERS: FilterDraft = {
  sourceModule: '',
  action: '',
  classification: '',
  occurredFrom: '',
  occurredTo: '',
}

function apiMessage(error: unknown, fallback: string): string {
  return error instanceof ApiError ? (error.problem.detail ?? error.problem.title) : fallback
}

export function AuditScreen() {
  const locale = useLocale()
  const principal = usePrincipal()
  const canRead = principal.capabilities?.includes('audit.event.read') ?? false

  if (!canRead) {
    // 403 and 404 collapse into the same shared, non-disclosing copy. The
    // server is the only guard; this branch is defense in depth.
    return <DeniedState locale={locale} />
  }

  return <AuditLedger locale={locale} />
}

function AuditLedger({ locale }: { locale: 'ar' | 'en' }) {
  const csrfToken = useSessionToken()
  const { session } = useSession()
  const principal = usePrincipal()
  const navigate = useNavigate()
  const t = copy[locale]
  const canExport = principal.capabilities?.includes('audit.event.export') ?? false
  const canVerify = principal.capabilities?.includes('audit.integrity.verify') ?? false

  const queryClient = useQueryClient()
  const invalidateLedger = () => void queryClient.invalidateQueries({ queryKey: ['audit-events'] })

  const [draft, setDraft] = useState<FilterDraft>(EMPTY_FILTERS)
  const [filters, setFilters] = useState<FilterDraft>(EMPTY_FILTERS)
  const appliedFilters = useMemo(() => queryFromFilters(filters), [filters])
  const ledgerQuery = useAuditEvents({ ...appliedFilters, limit: 50 })
  const ledgerData = ledgerQuery.data as generated.AuditEventCollection | undefined
  const [appended, setAppended] = useState<AuditEvent[]>([])
  const [nextCursor, setNextCursor] = useState<string | null>(null)
  const [loadingMore, setLoadingMore] = useState(false)
  const [loadMoreError, setLoadMoreError] = useState<string | null>(null)

  useEffect(() => {
    setAppended([])
    setNextCursor(ledgerData?.next_cursor ?? null)
  }, [ledgerData])

  const items = useMemo(
    () => [...(ledgerData?.items ?? []), ...appended],
    [ledgerData, appended],
  )
  const loading = ledgerQuery.isLoading
  /*
   * The previous implementation collapsed both flavors of failure into a
   * single `loadError` and rendered it INSTEAD of the table — hiding an
   * already-loaded ledger when load-more failed. We now distinguish:
   *  - initial-load failure (no items rendered) → blocking ErrorState
   *  - load-more failure (items already rendered) → inline notice below table
   * The defensive `!loadingMore` guard prevents a brief initial-load error
   * from being swamped by the inline notice while pagination is mid-flight.
   */
  const ledgerHasItems = items.length > 0
  const initialLoadError =
    ledgerQuery.isError && !ledgerHasItems && !loadingMore
      ? apiMessage(ledgerQuery.error, t.loadFailed)
      : null
  const inlineLoadMoreError =
    loadMoreError && ledgerHasItems ? loadMoreError : null

  const retryLedger = () => {
    setLoadMoreError(null)
    void ledgerQuery.refetch()
  }

  const loadMore = useCallback(async () => {
    if (!nextCursor || loadingMore) return
    setLoadingMore(true)
    setLoadMoreError(null)
    try {
      const page = unwrap<generated.AuditEventCollection>(
        await generated.listAuditEvents(
          { ...appliedFilters, limit: 50, cursor: nextCursor },
          requestInit(csrfToken),
        ),
      )
      setAppended((current) => [...current, ...page.items])
      setNextCursor(page.next_cursor)
    } catch (error) {
      setLoadMoreError(apiMessage(error, t.loadFailed))
    } finally {
      setLoadingMore(false)
    }
  }, [appliedFilters, nextCursor, loadingMore, csrfToken, t.loadFailed])

  function submitFilters(event: FormEvent<HTMLFormElement>): void {
    event.preventDefault()
    setFilters({ ...draft })
  }

  const [exportReason, setExportReason] = useState('')
  const [exportFormat, setExportFormat] = useState<'csv' | 'ndjson'>('csv')

  const createExportMutation = useMutation({
    mutationFn: async (input: generated.AuditExportCreate) =>
      unwrap<AuditExportDescriptor>(
        await generated.createAuditExport(input, requestInit(csrfToken, { command: true })),
      ),
    onSuccess: (descriptor) => {
      // 201 Accepted: return control immediately. Register the export in the
      // session-local tracker (owned by the current user) so the Exports tab
      // can poll and download it; no blocking overlay and no polling here.
      toast(t.exportQueued)
      registerExport({
        id: descriptor.id,
        kind: 'audit',
        name: `${descriptor.format.toUpperCase()} · ${formatDate(descriptor.created_at, locale)} · ${descriptor.id}`,
        format: descriptor.format,
        createdAt: descriptor.created_at,
        ownerUserId: session.userId,
      })
      setExportReason('')
      invalidateLedger()
    },
  })
  const exportBusy = createExportMutation.isPending
  const exportNotice = createExportMutation.isPending
    ? null
    : createExportMutation.isError
      ? apiMessage(createExportMutation.error, t.operationFailed)
      : null

  function submitExport(event: FormEvent<HTMLFormElement>): void {
    event.preventDefault()
    const query = queryFromFilters(filters)
    createExportMutation.mutate({
      format: exportFormat,
      reason: exportReason.trim(),
      filters: {
        ...(query.source_module ? { source_module: query.source_module } : {}),
        ...(query.action ? { action: query.action } : {}),
        ...(query.occurred_from ? { occurred_from: query.occurred_from } : {}),
        ...(query.occurred_to ? { occurred_to: query.occurred_to } : {}),
      },
    })
  }

  const [streamKey, setStreamKey] = useState('')
  const [firstSequence, setFirstSequence] = useState('')
  const [lastSequence, setLastSequence] = useState('')
  const [verification, setVerification] = useState<AuditIntegrityResult | null>(null)
  const [verifyNotice, setVerifyNotice] = useState<string | null>(null)

  const verifyMutation = useMutation({
    mutationFn: async (input: generated.AuditIntegrityRequest) =>
      unwrap<AuditIntegrityResult>(
        await generated.verifyAuditIntegrity(input, requestInit(csrfToken, { command: true })),
      ),
    onSuccess: (result) => {
      setVerification(result)
      invalidateLedger()
    },
  })
  const verifyBusy = verifyMutation.isPending
  const displayedVerifyNotice = verifyMutation.isPending
    ? null
    : verifyMutation.isError
      ? apiMessage(verifyMutation.error, t.operationFailed)
      : verifyNotice

  function submitVerification(event: FormEvent<HTMLFormElement>): void {
    event.preventDefault()
    const hasFirst = firstSequence !== ''
    const hasLast = lastSequence !== ''
    if (hasFirst !== hasLast) {
      setVerifyNotice(t.pairRequired)
      return
    }
    setVerifyNotice(null)
    verifyMutation.mutate({
      stream_key: streamKey.trim(),
      ...(hasFirst && hasLast
        ? { first_sequence: Number(firstSequence), last_sequence: Number(lastSequence) }
        : {}),
    })
  }

  const integrityViolations = items.filter((item) => item.integrity_status === 'violated').length
  const deniedEvents = items.filter((item) => item.outcome === 'denied').length
  const visibleModules = new Set(items.map((item) => item.source_module)).size

  const ledgerColumns = useMemo<ColumnDef<AuditEvent>[]>(
    () => [
      {
        id: 'occurred',
        header: t.occurred,
        cell: ({ row }) => (
          <time dateTime={row.original.occurred_at}>{formatDate(row.original.occurred_at, locale)}</time>
        ),
      },
      {
        id: 'action',
        header: t.action,
        cell: ({ row }) => (
          <div className="grid gap-0.5">
            <strong>{row.original.action}</strong>
            <span className="text-muted-foreground text-xs">{row.original.source_module}</span>
          </div>
        ),
      },
      {
        id: 'actor',
        header: t.actor,
        cell: ({ row }) => (
          <div className="grid gap-0.5">
            {row.original.actor_id ?? t.system}
            <span className="text-muted-foreground text-xs">{row.original.actor_type}</span>
          </div>
        ),
      },
      {
        id: 'subject',
        header: t.subject,
        cell: ({ row }) => (
          <div className="grid gap-0.5">
            {row.original.subject_id ?? t.notAvailable}
            <span className="text-muted-foreground text-xs">{row.original.subject_type}</span>
          </div>
        ),
      },
      {
        id: 'outcome',
        header: t.outcome,
        cell: ({ row }) => (
          <Badge variant="outline">
            <OutcomeIcon outcome={row.original.outcome} />
            {row.original.outcome}
          </Badge>
        ),
      },
      {
        id: 'integrity',
        header: t.integrity,
        cell: ({ row }) => (
          <Badge variant="outline">
            <IntegrityIcon status={row.original.integrity_status} />
            {row.original.integrity_status}
          </Badge>
        ),
      },
      {
        id: 'inspect',
        header: t.inspect,
        cell: ({ row }) => (
          <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={() => navigate(`/reports/audit/events/${row.original.event_id}`)}
          >
            {t.inspect}
          </Button>
        ),
      },
    ],
    [t, locale, navigate],
  )

  const table = useReactTable({
    data: items,
    columns: ledgerColumns,
    getCoreRowModel: getCoreRowModel(),
  })

  return (
    <div className="space-y-6">
      <div>
        <h2 className="text-xl font-semibold tracking-tight">{t.title}</h2>
        <p className="text-muted-foreground text-sm">{t.description}</p>
      </div>

      {/* Compact summary band — a definition list, never colored tiles. */}
      <dl
        className="grid grid-cols-2 gap-3 sm:grid-cols-4"
        data-testid="audit-summary"
        aria-label={t.ledger}
      >
        <SummaryItem label={t.ledger} value={items.length} />
        <SummaryItem label={t.integrity} value={integrityViolations} />
        <SummaryItem label={t.outcome} value={deniedEvents} />
        <SummaryItem label={t.sourceModule} value={visibleModules} />
      </dl>

      <Card data-testid="audit-filters">
        <CardHeader>
          <CardTitle className="text-base font-semibold">{t.filters}</CardTitle>
        </CardHeader>
        <CardContent>
          <form className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" onSubmit={submitFilters}>
            <div className="grid gap-1.5">
              <Label htmlFor="audit-source-module">{t.sourceModule}</Label>
              <Input
                id="audit-source-module"
                value={draft.sourceModule}
                onChange={(event) => setDraft({ ...draft, sourceModule: event.currentTarget.value })}
              />
            </div>
            <div className="grid gap-1.5">
              <Label htmlFor="audit-action">{t.action}</Label>
              <Input
                id="audit-action"
                value={draft.action}
                onChange={(event) => setDraft({ ...draft, action: event.currentTarget.value })}
              />
            </div>
            <div className="grid gap-1.5">
              <Label htmlFor="audit-classification">{t.classification}</Label>
              {/*
                * Radix Select reports the "__all" sentinel for the "All"
                * option. Normalize it to an empty string so the sentinel
                * never reaches the API as a filter — mirroring the
                * search/type filter pattern.
                */}
              <Select
                value={draft.classification || '__all'}
                onValueChange={(value) =>
                  setDraft({
                    ...draft,
                    classification: (value === '__all' ? '' : value) as FilterDraft['classification'],
                  })
                }
              >
                <SelectTrigger id="audit-classification" className="w-full">
                  <SelectValue placeholder={t.allClassifications} />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="__all">{t.allClassifications}</SelectItem>
                  <SelectItem value="public">Public</SelectItem>
                  <SelectItem value="internal">Internal</SelectItem>
                  <SelectItem value="confidential">Confidential</SelectItem>
                  <SelectItem value="top_secret">Top secret</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div className="grid gap-1.5">
              <Label htmlFor="audit-occurred-from">{t.from}</Label>
              <Input
                id="audit-occurred-from"
                type="datetime-local"
                value={draft.occurredFrom}
                onChange={(event) => setDraft({ ...draft, occurredFrom: event.currentTarget.value })}
              />
            </div>
            <div className="grid gap-1.5">
              <Label htmlFor="audit-occurred-to">{t.to}</Label>
              <Input
                id="audit-occurred-to"
                type="datetime-local"
                value={draft.occurredTo}
                onChange={(event) => setDraft({ ...draft, occurredTo: event.currentTarget.value })}
              />
            </div>
            <div className="flex items-end gap-2">
              <Button type="submit">{t.apply}</Button>
              <Button
                type="button"
                variant="outline"
                onClick={() => {
                  setDraft(EMPTY_FILTERS)
                  setFilters(EMPTY_FILTERS)
                }}
              >
                {t.clear}
              </Button>
            </div>
          </form>
        </CardContent>
      </Card>

      <Card data-testid="audit-ledger">
        <CardHeader>
          <CardTitle className="text-base font-semibold">{t.ledger}</CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          {loading ? <LoadingState rows={6} /> : null}
          {!loading && initialLoadError ? (
            <ErrorState locale={locale} onRetry={retryLedger} />
          ) : null}
          {!loading && !initialLoadError && items.length === 0 ? (
            <EmptyState title={t.empty} body={t.emptyBody} />
          ) : null}
          {!loading && !initialLoadError && items.length > 0 ? (
            <>
              <Table>
                <TableHeader>
                  {table.getHeaderGroups().map((headerGroup) => (
                    <TableRow key={headerGroup.id}>
                      {headerGroup.headers.map((header) => (
                        <TableHead key={header.id}>
                          {header.isPlaceholder
                            ? null
                            : flexRender(header.column.columnDef.header, header.getContext())}
                        </TableHead>
                      ))}
                    </TableRow>
                  ))}
                </TableHeader>
                <TableBody>
                  {table.getRowModel().rows.map((row) => (
                    <TableRow key={row.id}>
                      {row.getVisibleCells().map((cell) => (
                        <TableCell key={cell.id}>
                          {flexRender(cell.column.columnDef.cell, cell.getContext())}
                        </TableCell>
                      ))}
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
              {nextCursor ? (
                <div className="flex justify-center">
                  <Button type="button" variant="outline" disabled={loadingMore} onClick={() => void loadMore()}>
                    {t.loadMore}
                  </Button>
                </div>
              ) : null}
              {/*
                * Load-more failure: keep the already-loaded table visible and
                * surface a compact inline alert below pagination. Only
                * initial-load errors deserve a blocking ErrorState.
                */}
              {inlineLoadMoreError ? (
                <p className="text-destructive text-sm" role="alert" data-testid="audit-load-more-error">
                  {inlineLoadMoreError}
                </p>
              ) : null}
            </>
          ) : null}
        </CardContent>
      </Card>

      {canExport ? (
        <Card data-testid="audit-export">
          <CardHeader>
            <CardTitle className="text-base font-semibold">{t.exportTitle}</CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            <form className="grid gap-4" onSubmit={submitExport}>
              <div className="grid gap-1.5">
                <Label htmlFor="audit-export-reason">{t.exportReason}</Label>
                <Textarea
                  id="audit-export-reason"
                  rows={3}
                  required
                  minLength={1}
                  maxLength={500}
                  value={exportReason}
                  onChange={(event) => setExportReason(event.currentTarget.value)}
                />
                <p className="text-muted-foreground text-xs">{t.exportReasonHelp}</p>
              </div>
              <div className="grid gap-1.5">
                <Label htmlFor="audit-export-format">{t.format}</Label>
                <Select
                  value={exportFormat}
                  onValueChange={(value) => setExportFormat(value as 'csv' | 'ndjson')}
                >
                  <SelectTrigger id="audit-export-format" className="w-full">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="csv">CSV</SelectItem>
                    <SelectItem value="ndjson">NDJSON</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div>
                <Button type="submit" disabled={exportBusy || exportReason.trim() === ''}>
                  {t.createExport}
                </Button>
              </div>
            </form>
            {exportNotice ? (
              <Alert variant="destructive">
                <CircleAlert className="size-4" aria-hidden="true" />
                <AlertTitle>{exportNotice}</AlertTitle>
              </Alert>
            ) : null}
          </CardContent>
        </Card>
      ) : null}

      {canVerify ? (
        <Card data-testid="audit-integrity">
          <CardHeader>
            <CardTitle className="text-base font-semibold">{t.verifyTitle}</CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            <form className="grid gap-4 sm:grid-cols-2" onSubmit={submitVerification}>
              <div className="grid gap-1.5">
                <Label htmlFor="audit-stream-key">{t.streamKey}</Label>
                <Input
                  id="audit-stream-key"
                  required
                  maxLength={160}
                  value={streamKey}
                  onChange={(event) => setStreamKey(event.currentTarget.value)}
                />
              </div>
              <div className="grid gap-1.5">
                <Label htmlFor="audit-first-sequence">{t.firstSequence}</Label>
                <Input
                  id="audit-first-sequence"
                  type="number"
                  min={1}
                  value={firstSequence}
                  onChange={(event) => setFirstSequence(event.currentTarget.value)}
                />
              </div>
              <div className="grid gap-1.5">
                <Label htmlFor="audit-last-sequence">{t.lastSequence}</Label>
                <Input
                  id="audit-last-sequence"
                  type="number"
                  min={1}
                  value={lastSequence}
                  onChange={(event) => setLastSequence(event.currentTarget.value)}
                />
              </div>
              <p className="text-muted-foreground text-xs sm:col-span-2">{t.rangeHelp}</p>
              <div className="sm:col-span-2">
                <Button type="submit" disabled={verifyBusy || streamKey.trim() === ''}>
                  {t.verify}
                </Button>
              </div>
            </form>
            {displayedVerifyNotice ? (
              <Alert variant="destructive">
                <CircleAlert className="size-4" aria-hidden="true" />
                <AlertTitle>{displayedVerifyNotice}</AlertTitle>
              </Alert>
            ) : null}
            {verification ? <VerificationResult result={verification} locale={locale} /> : null}
          </CardContent>
        </Card>
      ) : null}
    </div>
  )
}

function SummaryItem({ label, value }: { label: string; value: number }) {
  return (
    <div className="grid gap-0.5 rounded-lg bg-muted/50 p-3">
      <dt className="text-muted-foreground text-xs">{label}</dt>
      <dd className="text-xl font-semibold tabular-nums">{value}</dd>
    </div>
  )
}

function OutcomeIcon({ outcome }: { outcome: string }) {
  if (outcome === 'succeeded')
    return <CheckCircle2 className="size-3.5" aria-hidden="true" />
  if (outcome === 'denied') return <ShieldX className="size-3.5" aria-hidden="true" />
  return <CircleAlert className="size-3.5" aria-hidden="true" />
}

function IntegrityIcon({ status }: { status: string }) {
  if (status === 'verified') return <ShieldCheck className="size-3.5" aria-hidden="true" />
  if (status === 'violated') return <ShieldAlert className="size-3.5" aria-hidden="true" />
  return <ShieldQuestion className="size-3.5" aria-hidden="true" />
}

/*
 * Integrity results surface as a shadcn Alert with a plain textual status and
 * the verified first–last sequence range. The status is deliberately NOT a
 * persistent colored badge; positive states have no dedicated token by design.
 */
function VerificationResult({ result, locale }: { result: AuditIntegrityResult; locale: 'ar' | 'en' }) {
  const t = copy[locale]
  return (
    <Alert data-testid="audit-verification-result">
      <ShieldCheck className="size-4" aria-hidden="true" />
      <AlertTitle>{t.verified}</AlertTitle>
      <AlertDescription className="space-y-1">
        <p>
          <span dir="ltr">{result.integrity_status}</span> · {result.verified_event_count} {t.events}
        </p>
        <p>
          {t.range}: <span dir="ltr">{result.first_sequence}–{result.last_sequence}</span>
        </p>
        <p className="break-all" dir="ltr">
          {result.stream_key}
        </p>
      </AlertDescription>
    </Alert>
  )
}

/*
 * The detail content moved to the full-page AuditEventDetailScreen; it is
 * re-exported here so existing consumers keep importing it from the ledger
 * module.
 */
export { AuditEventDetail } from './AuditEventDetailScreen'
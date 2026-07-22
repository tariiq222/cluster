// @vitest-environment jsdom
import { useCallback, useEffect, useMemo, useState } from 'react'
import { BookOpenText, Send } from 'lucide-react'

import type { Locale } from '../../app/copy'
import { type Session } from '../../api'
import { listWorkflowDefinitions, listWorkflowVersions, type R1Entity } from '../../api/r1'
import {
  Button,
  EmptyState,
  InlineError,
  Page,
  PageHeader,
  Panel,
  PanelGrid,
  SkeletonList,
  StatusBadge,
} from '../../ui'
import { workflowCopy } from './workflow-copy'

type GuideProcedure = {
  definition: R1Entity
  version: R1Entity
}

function valueOf(record: R1Entity, key: string): string | null {
  const value = record[key]
  return typeof value === 'string' && value.trim() ? value : null
}

function readNumber(record: R1Entity, key: string, fallback: number): number {
  const value = record[key]
  if (typeof value === 'number' && Number.isFinite(value)) return value
  if (typeof value === 'string' && /^\d+$/.test(value)) return Number(value)
  return fallback
}

function readDefinitionState(version: R1Entity): string {
  return (
    valueOf(version, 'definition_state') ??
    valueOf(version, 'review_state') ??
    valueOf(version, 'approval_status') ??
    ''
  )
}

function readUsageDescription(version: R1Entity): string | null {
  const value = version['usage_description']
  return typeof value === 'string' && value.trim() ? value : null
}

function isPublished(version: R1Entity): boolean {
  if (readDefinitionState(version) === 'published') return true
  // Compatibility: review_state 'published' or legacy approval_status 'published'.
  return valueOf(version, 'review_state') === 'published'
}

export function ProcedureGuide({
  locale,
  session,
  highlightedProcedureId,
  onOpenProcedure,
}: {
  locale: Locale
  session: Session
  highlightedProcedureId?: string
  onOpenProcedure?: (procedureId: string) => void
}) {
  const copy = workflowCopy[locale]
  const [loading, setLoading] = useState(true)
  const [loadError, setLoadError] = useState(false)
  const [procedures, setProcedures] = useState<GuideProcedure[]>([])

  const load = useCallback(async () => {
    setLoading(true)
    setLoadError(false)
    try {
      const definitions = await listWorkflowDefinitions(session.access_token)
      const collected: GuideProcedure[] = []
      for (const definition of definitions.items ?? []) {
        const definitionId = valueOf(definition, 'id')
        if (!definitionId) continue
        const versions = await listWorkflowVersions(session.access_token, definitionId)
        for (const version of versions.items ?? []) {
          if (!isPublished(version)) continue
          collected.push({ definition, version })
        }
      }
      collected.sort((a, b) => {
        const nameA = valueOf(a.definition, 'name') ?? valueOf(a.definition, 'code') ?? ''
        const nameB = valueOf(b.definition, 'name') ?? valueOf(b.definition, 'code') ?? ''
        if (nameA !== nameB) return nameA.localeCompare(nameB)
        return readNumber(b.version, 'version_number', 0) - readNumber(a.version, 'version_number', 0)
      })
      setProcedures(collected)
    } catch {
      setProcedures([])
      setLoadError(true)
    } finally {
      setLoading(false)
    }
  }, [session.access_token])

  useEffect(() => {
    void load()
  }, [load])

  const sorted = useMemo(() => procedures, [procedures])

  return (
    <Page aria-labelledby="procedure-guide-heading">
      <PageHeader
        id="procedure-guide-heading"
        title={copy.procGuide}
        description={copy.procGuideDescription}
        actions={<Button variant="secondary" onClick={() => void load()}>{copy.refresh}</Button>}
      />
      {loading ? (
        <SkeletonList label={copy.loadingProcedures} />
      ) : loadError ? (
        <InlineError message={copy.error} retryLabel={copy.retry} onRetry={() => void load()} />
      ) : sorted.length === 0 ? (
        <EmptyState icon={<BookOpenText aria-hidden="true" />} title={copy.procGuideEmpty} body={copy.procGuideEmptyBody} />
      ) : (
        <PanelGrid>
          {sorted.map((entry) => {
            const definitionId = valueOf(entry.definition, 'id') ?? ''
            const procedureId = definitionId
            const name = valueOf(entry.definition, 'name') ?? valueOf(entry.definition, 'code') ?? '—'
            const code = valueOf(entry.definition, 'code')
            const versionNumber = readNumber(entry.version, 'version_number', 0)
            const usage = readUsageDescription(entry.version)
            const isHighlighted = highlightedProcedureId !== undefined && highlightedProcedureId === procedureId
            const deepLink = procedureId ? `/procedures/${procedureId}/submit` : null
            return (
              <Panel
                key={procedureId || `${name}-${versionNumber}`}
                id={`procedure-guide-${procedureId}`}
                title={name}
                level={2}
                actions={<StatusBadge>{copy.procPublished}</StatusBadge>}
              >
                <dl className="procedure-guide-meta">
                  <div>
                    <dt>{copy.procCode}</dt>
                    <dd dir="ltr">{code ?? '—'}</dd>
                  </div>
                  <div>
                    <dt>{copy.procVersionNumber}</dt>
                    <dd>{versionNumber}</dd>
                  </div>
                </dl>
                {usage ? <p>{usage}</p> : null}
                <p className="procedure-guide-deeplink-help">{copy.procGuideDeepLinkHelp}</p>
                {deepLink ? (
                  <div className="procedure-guide-actions">
                    <a
                      className="primary-button"
                      href={deepLink}
                      aria-current={isHighlighted ? 'page' : undefined}
                      onClick={(event) => {
                        if (!onOpenProcedure) return
                        event.preventDefault()
                        onOpenProcedure(procedureId)
                      }}
                    >
                      <Send aria-hidden="true" /> {copy.procGuideDeepLink}
                    </a>
                  </div>
                ) : null}
              </Panel>
            )
          })}
        </PanelGrid>
      )}
    </Page>
  )
}

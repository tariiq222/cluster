import { useCallback, useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { Archive, ArrowRight, Link2, Lock, ShieldCheck, Unlock } from 'lucide-react'
import * as generated from '../../api/generated/cluster'
import { useDocument, useDocumentLinks, useDocumentVersions } from '../../api/hooks'
import { ApiError, requestInit, stateFromError, unwrap, type ResourceState } from '../../api/http'
import { useNavigate } from '../../app/navigation-context'
import { useLocale, useSessionToken } from '../../app/session-context'
import { statusLabel } from '../../i18n'
import { PageHeader, PageLayout } from '@/components/page-layout'
import { WorkspaceTabs, type WorkspaceTabItem } from '@/components/workspace-tabs'
import { ResourceBoundary } from '@/components/states'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { DocumentDialogs, type DocumentAction, type DocumentDialog } from './DocumentDialogs'
import { documentsCopy } from './documents-copy'
import { DocumentPreviewTab, type DocumentRecord } from './tabs/DocumentPreviewTab'
import { DocumentVersionsTab, type DocumentVersion } from './tabs/DocumentVersionsTab'
import { DocumentLinksTab, type DocumentLink } from './tabs/DocumentLinksTab'
import { DocumentAccessTab } from './tabs/DocumentAccessTab'

export function DocumentDetailScreen({ documentId }: { documentId: string }) {
  const locale = useLocale()
  const csrfToken = useSessionToken()
  const navigate = useNavigate()
  const t = documentsCopy[locale]
  const queryClient = useQueryClient()

  const [dialog, setDialog] = useState<DocumentDialog | null>(null)
  const [actionError, setActionError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [activeTab, setActiveTab] = useState('preview')

  const documentQuery = useDocument(documentId)
  const versionsQuery = useDocumentVersions(documentId)
  const linksQuery = useDocumentLinks(documentId)
  const document = documentQuery.data as DocumentRecord | undefined
  const versions = ((versionsQuery.data as generated.EntityCollection | undefined)?.items as unknown as DocumentVersion[]) ?? []
  const links = ((linksQuery.data as generated.EntityCollection | undefined)?.items as unknown as DocumentLink[]) ?? []

  const screenState: ResourceState = documentQuery.isError
    ? stateFromError(documentQuery.error)
    : documentQuery.isPending
      ? 'loading'
      : 'ready'

  const reload = useCallback(() => {
    void queryClient.invalidateQueries({ queryKey: ['document', documentId] })
    void queryClient.invalidateQueries({ queryKey: ['document-versions', documentId] })
    void queryClient.invalidateQueries({ queryKey: ['document-links', documentId] })
  }, [queryClient, documentId])

  const runTransition = useCallback(
    async (action: DocumentAction, reason: string) => {
      if (!document) return
      setBusy(true)
      setActionError(null)
      try {
        unwrap<DocumentRecord>(
          await generated.transitionDocument(
            documentId,
            action,
            { reason },
            requestInit(csrfToken, { command: true, idempotency: `document-${action}`, lockVersion: document.lock_version }),
          ),
        )
        setDialog(null)
        reload()
      } catch (error) {
        if (error instanceof ApiError && error.status === 412) {
          setDialog(null)
          reload()
          setActionError(t.stale)
          return
        }
        setActionError(errorMessage(error, t.actionError))
      } finally {
        setBusy(false)
      }
    },
    [csrfToken, document, documentId, reload, t.actionError, t.stale],
  )

  const submitLink = useCallback(
    async (link: { relation: string; module: string; recordType: string; recordId: string }) => {
      if (!document) return
      setBusy(true)
      setActionError(null)
      try {
        await unwrap<generated.Entity>(
          await generated.linkDocument(
            document.id,
            {
              source: {
                source_module: link.module.trim() || 'tasks',
                record_type: link.recordType.trim() || 'task',
                record_id: link.recordId,
              },
              relation_type: link.relation,
            },
            requestInit(csrfToken, { command: true, idempotency: 'document-link', lockVersion: document.lock_version }),
          ),
        )
        setDialog(null)
        reload()
      } catch (error) {
        if (error instanceof ApiError && error.status === 412) {
          setDialog(null)
          reload()
          setActionError(t.stale)
          return
        }
        setActionError(errorMessage(error, t.actionError))
      } finally {
        setBusy(false)
      }
    },
    [csrfToken, document, reload, t.actionError, t.stale],
  )

  const allowed = document?.allowed_actions ?? []
  const can = (action: string) => allowed.some((item) => item === action)
  const canUpload = can('add-version') || can('initiate-upload')

  return (
    <PageLayout>
      <div>
        <Button variant="ghost" size="sm" onClick={() => navigate('/documents')} className="-ms-2">
          <ArrowRight aria-hidden="true" />
          {t.back}
        </Button>
      </div>

      <ResourceBoundary state={screenState} locale={locale} rows={5}>
        {document ? (
          <>
            <PageHeader
              title={document.title ?? document.name ?? document.id}
              meta={<Badge variant="outline">{statusLabel(document.lifecycle_state ?? document.status, locale)}</Badge>}
              actions={
                <div className="flex flex-wrap gap-2">
                  {can('archive') ? (
                    <Button size="sm" variant="outline" disabled={busy} onClick={() => setDialog({ kind: 'transition', action: 'archive' })}>
                      <Archive aria-hidden="true" />
                      {t.actionArchive}
                    </Button>
                  ) : null}
                  {can('unarchive') ? (
                    <Button size="sm" variant="outline" disabled={busy} onClick={() => setDialog({ kind: 'transition', action: 'unarchive' })}>
                      <Unlock aria-hidden="true" />
                      {t.actionUnarchive}
                    </Button>
                  ) : null}
                  {can('place-hold') ? (
                    <Button size="sm" variant="outline" disabled={busy} onClick={() => setDialog({ kind: 'transition', action: 'place-hold' })}>
                      <Lock aria-hidden="true" />
                      {t.actionPlaceHold}
                    </Button>
                  ) : null}
                  {can('release-hold') ? (
                    <Button size="sm" variant="outline" disabled={busy} onClick={() => setDialog({ kind: 'transition', action: 'release-hold' })}>
                      <ShieldCheck aria-hidden="true" />
                      {t.actionReleaseHold}
                    </Button>
                  ) : null}
                  {can('link') ? (
                    <Button size="sm" variant="outline" disabled={busy} onClick={() => setDialog({ kind: 'link' })}>
                      <Link2 aria-hidden="true" />
                      {t.actionLink}
                    </Button>
                  ) : null}
                  {canUpload ? (
                    <Button size="sm" disabled={busy} onClick={() => navigate(`/documents/${documentId}/versions/new`)}>
                      {t.actionUpload}
                    </Button>
                  ) : null}
                </div>
              }
            />

            {actionError ? (
              <p className="text-destructive text-sm" role="alert">{actionError}</p>
            ) : null}

            <WorkspaceTabs
              label={t.pageTitle}
              value={activeTab}
              onValueChange={setActiveTab}
              items={[
                { value: 'preview', label: t.previewTab, content: <DocumentPreviewTab document={document} /> },
                { value: 'versions', label: t.versionsTab, content: <DocumentVersionsTab versions={versions} /> },
                {
                  value: 'links',
                  label: t.linksTab,
                  content: (
                    <DocumentLinksTab
                      links={links}
                      canLink={can('link')}
                      onLink={() => setDialog({ kind: 'link' })}
                    />
                  ),
                },
                {
                  value: 'access',
                  label: t.accessTab,
                  content: (
                    <DocumentAccessTab
                      documentId={document.id}
                      versions={versions}
                      canGrant={can('grant-access') || can('grant-preview') || can('grant-download')}
                    />
                  ),
                },
              ] satisfies WorkspaceTabItem[]}
            />
          </>
        ) : null}
      </ResourceBoundary>

      <DocumentDialogs
        dialog={dialog}
        busy={busy}
        onSubmit={async (payload) => {
          if (payload.type === 'transition') {
            if (!payload.reason?.trim()) {
              setActionError(t.reasonRequired)
              return
            }
            await runTransition(payload.action, payload.reason)
            return
          }
          if (payload.type === 'link') {
            if (!payload.recordId.trim()) {
              setActionError(t.recordIdRequired)
              return
            }
            await submitLink(payload)
          }
        }}
        onClose={() => {
          setDialog(null)
          setActionError(null)
        }}
      />
    </PageLayout>
  )
}

function errorMessage(error: unknown, fallback: string): string {
  if (error instanceof ApiError) {
    return error.problem.detail ?? error.problem.title ?? fallback
  }
  if (error instanceof Error && error.message) return error.message
  return fallback
}

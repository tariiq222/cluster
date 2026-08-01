import { useLocale } from '../../../app/session-context'
import { formatDate, statusLabel } from '../../../i18n'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { documentsCopy } from '../documents-copy'

export interface DocumentRecord {
  id: string
  title?: string
  name?: string
  description?: string
  classification: string
  status: string
  lifecycle_state?: string
  retention_until?: string | null
  restriction_policy_key?: string | null
  owner_organization_unit_id?: string
  lock_version: number
  allowed_actions?: string[]
  created_at: string
  updated_at: string
}

export function DocumentPreviewTab({ document }: { document: DocumentRecord }) {
  const locale = useLocale()
  const t = documentsCopy[locale]
  const title = document.title ?? document.name ?? document.id

  return (
    <div className="space-y-4">
      <dl className="grid gap-x-6 gap-y-3 sm:grid-cols-2">
        <div>
          <dt className="text-muted-foreground text-sm">{t.name}</dt>
          <dd className="mt-1 text-sm font-medium">{title}</dd>
        </div>
        <div>
          <dt className="text-muted-foreground text-sm">{t.classificationLabel}</dt>
          <dd className="mt-1 text-sm font-medium">{classificationLabel(document.classification, t)}</dd>
        </div>
        <div>
          <dt className="text-muted-foreground text-sm">{t.state}</dt>
          <dd className="mt-1">
            <Badge variant="outline">{statusLabel(document.lifecycle_state ?? document.status, locale)}</Badge>
          </dd>
        </div>
        {document.retention_until ? (
          <div>
            <dt className="text-muted-foreground text-sm">{t.retentionUntil}</dt>
            <dd className="mt-1 text-sm font-medium">
              <time dateTime={document.retention_until}>{formatDate(document.retention_until, locale)}</time>
            </dd>
          </div>
        ) : null}
        <div>
          <dt className="text-muted-foreground text-sm">{t.restrictionPolicyLabel}</dt>
          <dd className="mt-1 text-sm font-medium">{document.restriction_policy_key ?? '—'}</dd>
        </div>
        {document.owner_organization_unit_id ? (
          <div>
            <dt className="text-muted-foreground text-sm">{t.ownerLabel}</dt>
            <dd className="mt-1 text-sm font-medium">{document.owner_organization_unit_id}</dd>
          </div>
        ) : null}
        <div>
          <dt className="text-muted-foreground text-sm">{t.createdAt}</dt>
          <dd className="mt-1 text-sm font-medium">
            <time dateTime={document.created_at}>{formatDate(document.created_at, locale)}</time>
          </dd>
        </div>
        <div>
          <dt className="text-muted-foreground text-sm">{t.updatedAt}</dt>
          <dd className="mt-1 text-sm font-medium">
            <time dateTime={document.updated_at}>{formatDate(document.updated_at, locale)}</time>
          </dd>
        </div>
      </dl>

      <div>
        <h3 className="text-sm font-medium">{t.description}</h3>
        <p className="text-muted-foreground mt-1 text-sm">{document.description || t.noDescription}</p>
      </div>

      {document.allowed_actions?.includes('download') ? (
        <Button variant="outline" size="sm" asChild>
          <a href={`/api/v1/documents/${document.id}/download`} target="_blank" rel="noreferrer">
            {t.download}
          </a>
        </Button>
      ) : null}
    </div>
  )
}

function classificationLabel(classification: string, t: (typeof documentsCopy)[keyof typeof documentsCopy]): string {
  switch (classification) {
    case 'public':
      return t.classificationPublic
    case 'internal':
      return t.classificationInternal
    case 'confidential':
      return t.classificationConfidential
    case 'top_secret':
      return t.classificationTopSecret
    default:
      return classification
  }
}

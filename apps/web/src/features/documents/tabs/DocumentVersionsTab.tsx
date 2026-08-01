import { FileText, ShieldAlert, ShieldCheck, ShieldX } from 'lucide-react'
import { useLocale } from '../../../app/session-context'
import { formatDate, statusLabel } from '../../../i18n'
import { EmptyState } from '@/components/states'
import { Badge } from '@/components/ui/badge'
import { documentsCopy } from '../documents-copy'

export interface DocumentVersion {
  id: string
  version_number?: number
  file_name?: string
  status?: string
  availability_status?: string
  created_at?: string
}

export function DocumentVersionsTab({ versions }: { versions: DocumentVersion[] }) {
  const locale = useLocale()
  const t = documentsCopy[locale]

  if (versions.length === 0) {
    return <EmptyState icon={<FileText aria-hidden="true" />} title={t.noVersions} />
  }

  return (
    <ul className="space-y-2">
      {versions.map((version) => (
        <li key={version.id} className="flex flex-wrap items-center gap-2 rounded-lg border p-3 text-sm">
          <FileText aria-hidden="true" className="text-muted-foreground size-4" />
          <span className="font-medium">{version.file_name ?? version.id}</span>
          {version.version_number ? (
            <span className="text-muted-foreground">
              {t.versionNumber}: {version.version_number}
            </span>
          ) : null}
          {version.availability_status ? (
            <ScanBadge status={version.availability_status} />
          ) : null}
          {version.created_at ? (
            <span className="text-muted-foreground ms-auto text-xs">
              <time dateTime={version.created_at}>{formatDate(version.created_at, locale)}</time>
            </span>
          ) : null}
        </li>
      ))}
    </ul>
  )
}

function ScanBadge({ status }: { status: string }) {
  const locale = useLocale()
  const t = documentsCopy[locale]
  switch (status) {
    case 'available':
      return (
        <Badge variant="outline" className="gap-1">
          <ShieldCheck aria-hidden="true" />
          {t.scanAvailable}
        </Badge>
      )
    case 'scanning':
      return (
        <Badge variant="outline" className="gap-1">
          <ShieldAlert aria-hidden="true" />
          {t.scanScanning}
        </Badge>
      )
    case 'rejected':
      return (
        <Badge variant="outline" className="gap-1">
          <ShieldX aria-hidden="true" />
          {t.scanRejected}
        </Badge>
      )
    case 'restricted':
      return (
        <Badge variant="outline" className="gap-1">
          <ShieldX aria-hidden="true" />
          {t.scanRestricted}
        </Badge>
      )
    default:
      return <Badge variant="outline">{statusLabel(status, locale)}</Badge>
  }
}

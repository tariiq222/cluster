import { Link2 } from 'lucide-react'
import { useLocale } from '../../../app/session-context'
import { EmptyState } from '@/components/states'
import { Button } from '@/components/ui/button'
import { documentsCopy } from '../documents-copy'

export interface DocumentLink {
  id: string
  relation_type?: string
  source?: { source_module?: string; record_type?: string; record_id?: string }
  created_at?: string
}

export function DocumentLinksTab({
  links,
  canLink,
  onLink,
}: {
  links: DocumentLink[]
  canLink: boolean
  onLink: () => void
}) {
  const locale = useLocale()
  const t = documentsCopy[locale]

  if (links.length === 0) {
    return (
      <EmptyState
        icon={<Link2 aria-hidden="true" />}
        title={t.noLinks}
        action={
          canLink ? (
            <Button variant="outline" size="sm" onClick={onLink}>
              {t.actionLink}
            </Button>
          ) : null
        }
      />
    )
  }

  return (
    <div className="space-y-2">
      <ul className="space-y-2">
        {links.map((link) => (
          <li key={link.id} className="flex items-center gap-2 rounded-lg border p-3 text-sm">
            <Link2 aria-hidden="true" className="text-muted-foreground size-4" />
            <span className="font-medium">{link.relation_type ?? '—'}</span>
            <span className="text-muted-foreground text-xs" dir="ltr">
              {link.source ? `${link.source.source_module ?? ''}/${link.source.record_type ?? ''}/${link.source.record_id ?? ''}` : link.id}
            </span>
          </li>
        ))}
      </ul>
      {canLink ? (
        <Button variant="outline" size="sm" onClick={onLink}>
          {t.actionLink}
        </Button>
      ) : null}
    </div>
  )
}

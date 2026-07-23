import { useCallback, useEffect, useRef, useState } from 'react'

import { useLocale, useToken } from '../../app/session-context'
import { text } from '../../app/copy'
import { getDashboard, listDashboards, type R1Collection, type R1Entity } from '../../api/r1'
import { ApiError } from '../../api/http'
import { Button, InlineError } from '../../ui'

/**
 * The indicator band on the home screen.
 *
 * There is no per-role dashboard screen: `GET /dashboards` already returns the
 * published definitions this principal is allowed to see, and each dashboard's
 * rows are filtered by the same record-scoped decision. So one home screen
 * composes itself — an employee holds no dashboards and sees only their work,
 * a manager sees their indicators, a cluster officer sees more of them — and the
 * decision stays on the server where it belongs.
 *
 * Renders nothing at all when the principal holds no dashboards, so the band
 * never occupies the screen with an empty state the user cannot act on.
 */

/** How many dashboards the home band renders before it stops asking for more. */
const HOME_DASHBOARD_LIMIT = 4

type DashboardCard = {
  id: string
  title: string
  total: number
}

function cardTitle(definition: R1Entity, fallback: string): string {
  const title = definition.title ?? definition.name ?? definition.code
  return typeof title === 'string' && title.trim() !== '' ? title : fallback
}

function cardTotal(content: R1Collection): number {
  return typeof content.total === 'number' ? content.total : (content.items?.length ?? 0)
}

export function PrincipalDashboards(props: { onOpen: () => void; onOpenDocuments?: () => void; scopeId?: string | null; revision?: number }) {
  const locale = useLocale()
  const token = useToken()
  const copy = text[locale]
  const [cards, setCards] = useState<DashboardCard[]>([])
  const [state, setState] = useState<'loading' | 'ready' | 'denied' | 'error'>('loading')
  const requestRevision = useRef(0)

  const load = useCallback(async (revision: number) => {
    setCards([])
    setState('loading')
    try {
      const definitions = (await listDashboards(token)).items ?? []
      const visible = definitions.filter((item) => typeof item.id === 'string').slice(0, HOME_DASHBOARD_LIMIT)
      // A dashboard the principal may list but not read is not an error worth
      // reporting: it just does not become a card.
      const loaded = await Promise.allSettled(
        visible.map(async (definition): Promise<DashboardCard> => {
          const id = String(definition.id)
          const content = await getDashboard(token, id, props.scopeId ?? undefined)
          return { id, title: cardTitle(definition, id), total: cardTotal(content) }
        }),
      )
      if (revision !== requestRevision.current) return
      const cards = loaded.flatMap((result) => result.status === 'fulfilled' ? [result.value] : [])
      const hasRetryableFailure = loaded.some((result) => result.status === 'rejected' && !isAuthorizationError(result.reason))
      setCards(cards)
      setState(hasRetryableFailure ? 'error' : 'ready')
    } catch (error) {
      if (revision !== requestRevision.current) return
      // A principal with no reporting capability gets 403 here. That is the
      // normal employee case, not a failure worth announcing, and the band is
      // additive — so it simply does not render.
      setCards([])
      setState(isAuthorizationError(error) ? 'denied' : 'error')
    }
  }, [props.scopeId, token])

  useEffect(() => {
    const revision = ++requestRevision.current
    void load(revision)
  }, [load, props.revision])

  if (state === 'loading' || state === 'denied' || (state === 'ready' && cards.length === 0)) return null

  if (state === 'error' && cards.length === 0) {
    return <InlineError message={locale === 'ar' ? 'تعذر تحميل المؤشرات. أعد المحاولة.' : 'We could not load the indicators. Try again.'} retryLabel={locale === 'ar' ? 'إعادة المحاولة' : 'Try again'} onRetry={() => { void load(++requestRevision.current) }} />
  }

  return (
    <section className="dashboard-indicators" aria-labelledby="home-indicators-heading">
      <h2 id="home-indicators-heading">{copy.myIndicators}</h2>
      <div className="dashboard-kpi-grid" role="group" aria-label={copy.myIndicators}>
        {cards.map((card) => (
          <article className="dashboard-kpi" key={card.id}>
            <span>{card.title}</span>
            <strong>{card.total}</strong>
            <small>{copy.indicatorsInScope}</small>
          </article>
        ))}
      </div>
      <Button variant="secondary" onClick={props.onOpen}>
        {copy.openIndicator}
      </Button>
      {state === 'error' ? <InlineError message={locale === 'ar' ? 'تعذر تحميل بعض المؤشرات. أعد المحاولة.' : 'Some indicators could not be loaded. Try again.'} retryLabel={locale === 'ar' ? 'إعادة المحاولة' : 'Try again'} onRetry={() => { void load(++requestRevision.current) }} /> : null}
    </section>
  )
}

function isAuthorizationError(error: unknown): boolean {
  return error instanceof ApiError && (error.status === 401 || error.status === 403)
}

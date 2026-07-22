import { useCallback, useEffect, useState } from 'react'

import { useLocale, useToken } from '../../app/session-context'
import { text } from '../../app/copy'
import { getDashboard, listDashboards, type R1Collection, type R1Entity } from '../../api/r1'
import { Button } from '../../ui'

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

export function PrincipalDashboards({ onOpen }: { onOpen: () => void }) {
  const locale = useLocale()
  const token = useToken()
  const copy = text[locale]
  const [cards, setCards] = useState<DashboardCard[]>([])

  const load = useCallback(async () => {
    try {
      const definitions = (await listDashboards(token)).items ?? []
      const visible = definitions.filter((item) => typeof item.id === 'string').slice(0, HOME_DASHBOARD_LIMIT)
      // A dashboard the principal may list but not read is not an error worth
      // reporting: it just does not become a card.
      const loaded = await Promise.all(
        visible.map(async (definition): Promise<DashboardCard | null> => {
          const id = String(definition.id)
          try {
            const content = await getDashboard(token, id)
            return { id, title: cardTitle(definition, id), total: cardTotal(content) }
          } catch {
            return null
          }
        }),
      )
      setCards(loaded.filter((card): card is DashboardCard => card !== null))
    } catch {
      // A principal with no reporting capability gets 403 here. That is the
      // normal employee case, not a failure worth announcing, and the band is
      // additive — so it simply does not render.
      setCards([])
    }
  }, [token])

  useEffect(() => {
    void load()
  }, [load])

  if (cards.length === 0) return null

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
      <Button variant="secondary" onClick={onOpen}>
        {copy.openIndicator}
      </Button>
    </section>
  )
}

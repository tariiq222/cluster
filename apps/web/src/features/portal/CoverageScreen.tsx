import { Page, Panel, PanelGrid, MetricTile, StatusBadge, DataFreshness } from '../../ui'
import {
  COVERAGE_MODULES,
  COVERAGE_STATS,
  GAP_ITEMS,
  CONTRACT_VERSION,
  GENERATED_AT,
  type Bilingual,
  type GapItem,
} from './coverage-data'

type Locale = 'ar' | 'en'

const pick = (b: Bilingual, locale: Locale) => b[locale]

const RANK_VARIANT: Record<GapItem['rank'], 'danger' | 'warning'> = {
  P0: 'danger',
  P1: 'warning',
}

const copy = {
  ar: {
    eyebrow: 'مراجعة المنتج · ليست شاشة تشغيلية',
    title: 'تغطية عمليات النظام',
    intro: 'خريطة تحقق تربط كل نطاق API بمكانه الصحيح في تجربة المستخدم.',
    heroTitle: 'الجرد مولّد من عقد OpenAPI',
    heroBody: 'ليست كل عملية زرًا؛ تسجيل الدخول وCSRF والتهيئة والفحص الداخلي خدمات مساندة، بينما العمليات البشرية تظهر في رحلات واضحة.',
    moduleTitle: 'النطاقات الموثقة',
    gapTitle: 'الفجوات المؤكدة',
    reviewNote: 'هذه الشاشة للقراءة فقط وتُستخدم لمراجعة التغطية وقياس أولويات الإكمال.',
    documentedOperations: 'عملية موثقة',
    surface: 'النطاق',
    count: 'العدد',
    description: 'الوصف',
    priorityGapsThatKeepTheCoverage: 'الأولويات التي تمنع إغلاق التغطية بشكل كامل.',
    inventoryAccepted: 'الجرد مقبول',
  },
  en: {
    eyebrow: 'Product review · Non-operational screen',
    title: 'System coverage',
    intro: 'A verification map that places each API surface in the right user journey.',
    heroTitle: 'Inventory generated from the OpenAPI contract',
    heroBody: 'Not every operation is a button; login, CSRF, initialization, and internal scans are supporting services, while human workflows appear as clear journeys.',
    moduleTitle: 'Documented surfaces',
    gapTitle: 'Confirmed gaps',
    reviewNote: 'This screen is read-only and is used to review coverage and prioritize completion work.',
    documentedOperations: 'Documented operations',
    surface: 'Surface',
    count: 'Count',
    description: 'Description',
    priorityGapsThatKeepTheCoverage: 'Priority gaps that keep the coverage from being complete.',
    inventoryAccepted: 'Inventory accepted',
  },
} as const

export function CoverageScreen({ locale }: { locale: Locale }) {
  const t = copy[locale]
  const totalOperations = COVERAGE_STATS[0]?.value ?? '0'

  return (
    <Page>
      <section className="ui-page" aria-labelledby="coverage-heading">
        <header className="ui-page-header">
          <div>
            <div className="eyebrow">{t.eyebrow}</div>
            <h1 id="coverage-heading">{t.title}</h1>
            <p>{t.intro}</p>
          </div>
          <div className="ui-page-header-actions">
            <StatusBadge variant="info">
              {t.inventoryAccepted} · {CONTRACT_VERSION}
            </StatusBadge>
          </div>
        </header>

        <DataFreshness
          updatedAt={`v${CONTRACT_VERSION}`}
          period={GENERATED_AT}
          state="fresh"
        />

        <Panel id="coverage-hero" title={t.heroTitle}>
          <p>{t.heroBody}</p>
          <MetricTile
            label={t.documentedOperations}
            value={totalOperations}
            unit={t.documentedOperations}
            period={CONTRACT_VERSION}
            updatedAt={GENERATED_AT}
            source="OpenAPI bundle"
            variant="ready"
          />
        </Panel>

        <PanelGrid>
          {COVERAGE_STATS.map((stat) => (
            <MetricTile
              key={stat.label.en}
              label={pick(stat.label, locale)}
              value={stat.value}
              variant="ready"
            />
          ))}
        </PanelGrid>

        <PanelGrid>
          <Panel id="coverage-modules" title={t.moduleTitle}>
            <p>{t.reviewNote}</p>
            <table>
              <thead>
                <tr>
                  <th>{t.surface}</th>
                  <th>{t.count}</th>
                  <th>{t.description}</th>
                </tr>
              </thead>
              <tbody>
                {COVERAGE_MODULES.map((row) => (
                  <tr key={pick(row.name, locale)}>
                    <td>
                      <strong>{pick(row.name, locale)}</strong>
                    </td>
                    <td>{row.count}</td>
                    <td>{pick(row.label, locale)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </Panel>

          <Panel id="coverage-gaps" title={t.gapTitle}>
            <p>{t.priorityGapsThatKeepTheCoverage}</p>
            {GAP_ITEMS.map((gap) => (
              <article key={`${gap.rank}-${pick(gap.title, locale)}`} className="coverage-gap">
                <div>
                  <StatusBadge variant={RANK_VARIANT[gap.rank]}>
                    {gap.rank}
                  </StatusBadge>
                </div>
                <h3>{pick(gap.title, locale)}</h3>
                <p>{pick(gap.desc, locale)}</p>
              </article>
            ))}
          </Panel>
        </PanelGrid>
      </section>
    </Page>
  )
}

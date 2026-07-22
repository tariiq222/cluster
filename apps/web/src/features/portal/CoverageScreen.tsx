import { TabletSmartphone } from 'lucide-react'

import { Page, StatusBadge } from '../../ui'
import { COVERAGE_MODULES, COVERAGE_STATS, GAP_ITEMS } from './coverage-data'

type Locale = 'ar' | 'en'

const copy = {
  ar: {
    eyebrow: 'مراجعة المنتج · ليست شاشة تشغيلية',
    title: 'تغطية عمليات النظام',
    intro: 'خريطة تحقق تربط كل نطاق API بمكانه الصحيح في تجربة المستخدم.',
    status: 'الجرد مقبول · v1.0.0',
    heroTitle: 'تم تصميم الواجهة حول 112 عملية حية',
    heroBody: 'ليست كل عملية زرًا؛ تسجيل الدخول وCSRF والتهيئة والفحص الداخلي خدمات مساندة، بينما العمليات البشرية تظهر في رحلات واضحة.',
    moduleTitle: 'النطاقات الموثقة',
    gapTitle: 'الفجوات المؤكدة',
    reviewNote: 'هذه الشاشة للقراءة فقط وتُستخدم لمراجعة التغطية وقياس أولويات الإكمال.',
    documentedOperations: 'عملية موثقة',
    surface: 'النطاق',
    count: 'العدد',
    description: 'الوصف',
    priorityGapsThatKeepTheCoverage: 'الأولويات التي تمنع إغلاق التغطية بشكل كامل.',
  },
  en: {
    eyebrow: 'Product review · Non-operational screen',
    title: 'System coverage',
    intro: 'A verification map that places each API surface in the right user journey.',
    status: 'Inventory accepted · v1.0.0',
    heroTitle: 'The UI is built around 112 live operations',
    heroBody: 'Not every operation is a button; login, CSRF, initialization, and internal scans are supporting services, while human workflows appear as clear journeys.',
    moduleTitle: 'Documented surfaces',
    gapTitle: 'Confirmed gaps',
    reviewNote: 'This screen is read-only and is used to review coverage and prioritize completion work.',
    documentedOperations: 'Documented operations',
    surface: 'Surface',
    count: 'Count',
    description: 'Description',
    priorityGapsThatKeepTheCoverage: 'Priority gaps that keep the coverage from being complete.',
  },
} as const

export function CoverageScreen({ locale }: { locale: Locale }) {
  const t = copy[locale]

  return (
    <Page>
      <section className="view active" id="view-coverage" aria-labelledby="coverage-heading">
        <div className="page-head">
          <div>
            <div className="eyebrow">{t.eyebrow}</div>
            <h1 id="coverage-heading">{t.title}</h1>
            <p>{t.intro}</p>
          </div>
          <div className="actions">
            <StatusBadge>{t.status}</StatusBadge>
          </div>
        </div>

        <div className="card coverage-hero">
          <div>
            <h2>{t.heroTitle}</h2>
            <p>{t.heroBody}</p>
          </div>
          <div className="coverage-score">
            <div>
              <strong>112</strong>
              <span>{copy[locale].documentedOperations}</span>
            </div>
          </div>
        </div>

        <div className="stat-grid">
          {COVERAGE_STATS.map((item) => (
            <div className="card stat-card" key={item.label}>
              <div className="stat-icon"><TabletSmartphone aria-hidden="true" /></div>
              <div>
                <div className="stat-value">{item.value}</div>
                <div className="stat-label">{item.label}</div>
              </div>
            </div>
          ))}
        </div>

        <div className="coverage-grid">
          <div className="card">
            <div className="card-head">
              <div>
                <h2>{t.moduleTitle}</h2>
                <p>{t.reviewNote}</p>
              </div>
            </div>
            <div className="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>{copy[locale].surface}</th>
                    <th>{copy[locale].count}</th>
                    <th>{copy[locale].description}</th>
                  </tr>
                </thead>
                <tbody>
                  {COVERAGE_MODULES.map((row) => (
                    <tr key={row.name}>
                      <td><strong>{row.name}</strong></td>
                      <td>{row.count}</td>
                      <td>{row.label}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>

          <div className="card">
            <div className="card-head">
              <div>
                <h2>{t.gapTitle}</h2>
                <p>{copy[locale].priorityGapsThatKeepTheCoverage}</p>
              </div>
            </div>
            <div className="definition-grid">
              {GAP_ITEMS.map((gap) => (
                <article className="definition-card" key={`${gap.rank}-${gap.title}`}>
                  <div className="definition-top">
                    <StatusBadge>{gap.rank}</StatusBadge>
                  </div>
                  <h3>{gap.title}</h3>
                  <p>{gap.desc}</p>
                </article>
              ))}
            </div>
          </div>
        </div>
      </section>
    </Page>
  )
}

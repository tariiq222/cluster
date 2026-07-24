import type { ReactNode } from 'react'
import { Link } from 'lucide-react'

import { capabilitiesForRoute, pathFromRoute, PLATFORM_SETTINGS_SECTIONS, type PlatformSettingsSection } from '../../shell/routes'
import { Button, EmptyState, Page, PageHeader } from '../../ui'
import type { Locale } from '../../app/copy'
import { platformSettingsCopy } from './copy'
import './platform-settings.css'

function canOpenSection(section: PlatformSettingsSection, capabilities: readonly string[] | null): boolean {
  if (capabilities === null) return false
  return capabilitiesForRoute({ name: 'platform-settings', section })?.some(
    (capability) => capabilities.includes(capability),
  ) === true
}

export function PlatformSettingsLayout({
  locale,
  section,
  capabilities,
  navigate,
  children,
}: {
  locale: Locale
  section: PlatformSettingsSection
  capabilities: readonly string[] | null
  navigate: (path: string) => void
  children: ReactNode
}) {
  const copy = platformSettingsCopy[locale]
  const visibleSections = PLATFORM_SETTINGS_SECTIONS.filter((item) => canOpenSection(item, capabilities))

  return (
    <Page className="platform-settings-page" dir={locale === 'ar' ? 'rtl' : 'ltr'}>
      <PageHeader
        id="platform-settings-heading"
        title={copy.title}
        description={copy.description}
        actions={section === 'overview' ? undefined : (
          <Button variant="secondary" onClick={() => navigate(pathFromRoute({ name: 'platform-settings', section: 'overview' }))}>
            {copy.returnToOverview}
          </Button>
        )}
      />
      <div className="platform-settings-layout">
        <nav className="platform-settings-nav" aria-label={copy.navigationLabel}>
          <ul>
            {visibleSections.map((item) => {
              const path = pathFromRoute({ name: 'platform-settings', section: item })
              return (
                <li key={item}>
                  <a
                    href={path}
                    aria-current={item === section ? 'page' : undefined}
                    onClick={(event) => {
                      event.preventDefault()
                      navigate(path)
                    }}
                  >
                    {copy.sections[item]}
                  </a>
                </li>
              )
            })}
          </ul>
        </nav>
        <section
          className="platform-settings-content"
          aria-labelledby={`platform-settings-${section}`}
        >
          <div className="platform-settings-section-heading">
            <h2 id={`platform-settings-${section}`}>{copy.sections[section]}</h2>
          </div>
          {visibleSections.length === 0 ? (
            <EmptyState
              icon={<Link />}
              title={capabilities === null ? copy.loadingCapabilities : copy.unavailableTitle}
              body={capabilities === null ? undefined : copy.unavailableBody}
            />
          ) : null}
          {children}
        </section>
      </div>
    </Page>
  )
}

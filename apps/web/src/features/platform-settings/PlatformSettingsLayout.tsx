import type { ReactNode } from 'react'
import { Link } from 'lucide-react'

import { capabilitiesForRoute, pathFromRoute, PLATFORM_SETTINGS_SECTIONS, type PlatformSettingsSection } from '../../shell/routes'
import { Button, EmptyState, Page, PageHeader } from '../../ui'
import type { Locale } from '../../app/copy'
import { platformSettingsCopy } from './copy'
import './platform-settings.css'

export type PlatformWorkspaceSection = PlatformSettingsSection | 'api-reference'

function canOpenSection(section: PlatformWorkspaceSection, capabilities: readonly string[] | null): boolean {
  if (section === 'api-reference') {
    return capabilities?.includes('authorization.audit.read') === true
  }
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
  section: PlatformWorkspaceSection
  capabilities: readonly string[] | null
  navigate: (path: string) => void
  children: ReactNode
}) {
  const copy = platformSettingsCopy[locale]
  const baseSections = PLATFORM_SETTINGS_SECTIONS.filter((item) => canOpenSection(item, capabilities))
  const showApiReference = canOpenSection('api-reference', capabilities)
  const items: Array<{ key: PlatformWorkspaceSection; path: string; label: string }> = baseSections.map((item) => ({
    key: item,
    path: pathFromRoute({ name: 'platform-settings', section: item }),
    label: copy.sections[item],
  }))
  if (showApiReference) {
    items.push({
      key: 'api-reference',
      path: pathFromRoute({ name: 'api-docs' }),
      label: copy.sections.apiReference,
    })
  }

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
            {items.map((item) => (
              <li key={item.key}>
                <a
                  href={item.path}
                  aria-current={item.key === section ? 'page' : undefined}
                  onClick={(event) => {
                    event.preventDefault()
                    navigate(item.path)
                  }}
                >
                  {item.label}
                </a>
              </li>
            ))}
          </ul>
        </nav>
        <section
          className="platform-settings-content"
          aria-labelledby={`platform-settings-${section}`}
        >
          <div className="platform-settings-section-heading">
            <h2 id={`platform-settings-${section}`}>{copy.sections[section]}</h2>
          </div>
          {items.length === 0 ? (
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
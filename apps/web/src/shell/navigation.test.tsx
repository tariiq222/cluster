// @vitest-environment jsdom
import { describe, expect, it } from 'vitest'

import {
  PRIMARY_NAVIGATION_ENTRIES,
  buildPrimaryNavigationItems,
  buildUserMenuEntries,
  isNavigationEntryVisible,
} from './navigation'
import type { Locale } from '../app/copy'

const DISABLED = { work_management: false, tasks: true } as const
const ENABLED = { work_management: true, tasks: true } as const

const fullCapabilities = [
  'authorization.audit.read',
  'audit.event.read',
  'reporting.dashboard',
  'reporting.list',
  'workflow.decide',
  'workflow.reassign',
  'workflow.escalate',
  'workflow.read',
  'workflow.manage',
  'workflow.author',
  'workflow.approve',
  'work_definition.read',
  'work_definition.list',
  'tasks.read',
  'tasks.list',
  'documents.read',
  'documents.list',
  'organization.facility.read',
  'organization.unit.read',
  'organization.person.read',
  'organization.temporary-assignment.read',
  'organization.import.read',
  'identity.account.read',
  'authorization.role.read',
  'authorization.capability.read',
  'authorization.assignment.read',
  'authorization.policy.read',
  'authorization.decision.read',
  'platform_settings.read',
]

function flat(locale: Locale, capabilities: readonly string[], features: typeof DISABLED | typeof ENABLED) {
  return buildPrimaryNavigationItems({ locale, capabilities, features }).map(
    ({ key, label, path }) => ({ key, label, path }),
  )
}

describe('navigation registry', () => {
  it('returns seven ordered primary destinations for a fully authorized task-only principal', () => {
    const items = buildPrimaryNavigationItems({
      locale: 'ar',
      capabilities: fullCapabilities,
      features: DISABLED,
    })

    expect(items.map(({ key, label, path }) => ({ key, label, path }))).toEqual([
      { key: 'home', label: 'الرئيسية', path: '/' },
      { key: 'tasks', label: 'مهامي', path: '/tasks' },
      { key: 'documents', label: 'المستندات', path: '/documents' },
      { key: 'organization', label: 'المنشآت والموظفون', path: '/admin/organization' },
      { key: 'accounts-permissions', label: 'الحسابات والصلاحيات', path: '/admin/identity/accounts' },
      { key: 'reports-monitoring', label: 'التقارير والمتابعة', path: '/reports' },
      { key: 'platform-management', label: 'إدارة المنصة', path: '/admin/platform' },
    ])
  })

  it('hides an administrative destination when none of its child capabilities is visible', () => {
    const items = buildPrimaryNavigationItems({
      locale: 'ar',
      capabilities: ['tasks.read'],
      features: DISABLED,
    })
    expect(items.map((item) => item.key)).toEqual(['home', 'tasks'])
  })

  it('uses the approved English names and keeps personal access in the user menu', () => {
    expect(buildPrimaryNavigationItems({
      locale: 'en',
      capabilities: fullCapabilities,
      features: DISABLED,
    }).map((item) => item.label)).toEqual([
      'Home',
      'My tasks',
      'Documents',
      'Facilities and employees',
      'Accounts and permissions',
      'Reports and monitoring',
      'Platform management',
    ])

    expect(buildUserMenuEntries('ar')).toContainEqual(expect.objectContaining({
      key: 'access-context',
      label: 'صلاحياتي ونطاق عملي',
      path: '/me/access',
    }))
  })

  it('withholds every gated entry while the principal context is still loading', () => {
    const items = buildPrimaryNavigationItems({
      locale: 'ar',
      capabilities: null,
      features: ENABLED,
    })
    expect(items.map((item) => item.key)).toEqual(['home'])
  })
  it('keeps the seven destinations visible when the work_management feature is disabled and capabilities are granted', () => {
    const items = buildPrimaryNavigationItems({
      locale: 'ar',
      capabilities: fullCapabilities,
      features: DISABLED,
    })
    // The seven primary destinations all stay visible; the work_management
    // feature gate only suppresses approval/work-management screens that
    // already live outside the primary sidebar.
    expect(items.map((item) => item.key)).toEqual([
      'home',
      'tasks',
      'documents',
      'organization',
      'accounts-permissions',
      'reports-monitoring',
      'platform-management',
    ])
  })

  it('keeps every entry wired to a route and a label key so the sidebar never renders empty text', () => {
    expect(PRIMARY_NAVIGATION_ENTRIES.length).toBe(7)
    for (const entry of PRIMARY_NAVIGATION_ENTRIES) {
      expect(entry.labelKey).toBeTruthy()
      expect(entry.route).toBeDefined()
      expect(isNavigationEntryVisible(entry, fullCapabilities, ENABLED)).toBe(true)
    }
  })

  it('offers an employee only home and tasks when none of the admin capabilities is granted', () => {
    expect(flat('ar', ['tasks.read'], DISABLED)).toEqual([
      { key: 'home', label: 'الرئيسية', path: '/' },
      { key: 'tasks', label: 'مهامي', path: '/tasks' },
    ])
  })

  it('keeps the user menu separate from the sidebar with only personal entries', () => {
    const items = buildUserMenuEntries('ar')
    expect(items.map((item) => item.key).sort()).toEqual([
      'access-context',
      'personal-security',
    ])
    expect(items.map((item) => item.path).sort()).toEqual([
      '/me/access',
      '/me/security',
    ])
  })
})

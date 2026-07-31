// @vitest-environment jsdom
import { afterEach, describe, expect, it } from 'vitest'
import { createElement, createRef } from 'react'
import { directionForLocale } from '../../app/copy'
import {
  accountsPermissionsCopy,
  accountsPermissionsTabs,
  pluralizeAssignments,
  pluralizeCapabilities,
  type AnnouncementKey,
} from './copy'
import { AnnouncementRegion, type AnnouncementRegionHandle } from './AnnouncementRegion'
import { act, cleanup, render, screen } from '@testing-library/react'

afterEach(() => cleanup())

function leafPaths(value: unknown, path: string[] = []): string[][] {
  if (typeof value === 'function' || value === null || typeof value !== 'object') return [path]
  return Object.entries(value).flatMap(([key, child]) => leafPaths(child, [...path, key]))
}
function atPath(value: unknown, path: string[]): unknown {
  return path.reduce((current, key) => (current as Record<string, unknown>)[key], value)
}

describe('accounts and permissions copy', () => {
  it('keeps every nested English and Arabic leaf non-empty and mirrored', () => {
    const arPaths = leafPaths(accountsPermissionsCopy.ar).map(String).sort()
    const enPaths = leafPaths(accountsPermissionsCopy.en).map(String).sort()
    expect(arPaths).toEqual(enPaths)
    for (const path of leafPaths(accountsPermissionsCopy.en)) {
      const en = atPath(accountsPermissionsCopy.en, path)
      const ar = atPath(accountsPermissionsCopy.ar, path)
      expect(en).toBeTruthy()
      expect(ar).toBeTruthy()
      if (typeof en === 'function' && typeof ar === 'function') {
        expect(String(en('sample'))).toBeTruthy()
        expect(String(ar('sample'))).toBeTruthy()
      }
    }
    expect(accountsPermissionsTabs).toHaveLength(5)
  })

  it('pluralizes capability and assignment counts using Arabic categories', () => {
    expect(pluralizeCapabilities('en', 1)).toContain('capability')
    expect(pluralizeCapabilities('en', 2)).toContain('capabilities')
    expect(pluralizeAssignments('ar', 0)).toContain('لا توجد')
    expect(pluralizeAssignments('ar', 1)).toContain('إسناد واحد')
    expect(pluralizeAssignments('ar', 2)).toContain('إسنادان')
    expect(pluralizeAssignments('ar', 3)).toContain('إسنادات')
    expect(pluralizeAssignments('ar', 11)).toContain('إسنادًا')
  })

  it('announces localized catalog messages, errors, direction, and polite semantics', () => {
    const ref = createRef<AnnouncementRegionHandle>()
    render(createElement(AnnouncementRegion, { locale: 'ar', ref }))
    const output = screen.getByRole('status')
    expect(output.getAttribute('aria-live')).toBe('polite')
    expect(output.getAttribute('dir')).toBe(directionForLocale('ar'))
    act(() => ref.current?.announce('role.created'))
    expect(output.textContent).toContain('تم إنشاء الدور')
    act(() => ref.current?.announceError('حدث خطأ'))
    expect(output.textContent).toBe('حدث خطأ')
    expect(document.activeElement).toBe(output)
  })

  it('updates the live region for repeated identical announcements', () => {
    const ref = createRef<AnnouncementRegionHandle>()
    render(createElement(AnnouncementRegion, { locale: 'en', ref }))
    const output = screen.getByRole('status')
    act(() => ref.current?.announce('role.created'))
    const first = output.getAttribute('data-announcement-sequence')
    act(() => ref.current?.announce('role.created'))
    expect(output.textContent).toContain('Role created')
    expect(output.getAttribute('data-announcement-sequence')).not.toBe(first)
  })

  it('exposes all required announcements, confirmations, and inspector templates', () => {
    const keys: AnnouncementKey[] = ['role.created', 'role.updated', 'role.cloned', 'role.archived', 'assignment.created', 'assignment.updated', 'assignment.revoked', 'assignment.expired', 'role_capability.revoked']
    for (const key of keys) expect(accountsPermissionsCopy.en.announcements[key]).toBeTruthy()
    expect(accountsPermissionsCopy.en.confirmations.account('A')).toContain('A')
    expect(accountsPermissionsCopy.en.confirmations.role('R')).toContain('R')
    expect(accountsPermissionsCopy.en.confirmations.scope('S')).toContain('S')
    expect(accountsPermissionsCopy.en.inspector.roleApplies('Finance')).toContain('Finance')
    expect(accountsPermissionsCopy.en.inspector.decision('allowed')).toContain('allowed')
  })
})

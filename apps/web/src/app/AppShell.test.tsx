// @vitest-environment jsdom
import { createRef, type ComponentProps } from 'react'
import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { AppShell, type AppShellCopy, type SidebarNavigationItem } from './AppShell'
import { shellCopy } from './copy'

const copy: AppShellCopy = {
  platform: 'بوابة العمل',
  switchLanguage: 'English',
  currentFacility: 'نطاق العمل الحالي',
  notifications: 'الإشعارات',
  profile: 'الملف الشخصي',
  logout: 'تسجيل الخروج',
  rightsReserved: 'جميع الحقوق محفوظة',
  organizationName: 'التجمع الصحي',
  officeName: 'مكتب التحول',
  ownerName: 'مالك المنتج',
  openNavigation: 'فتح القائمة الرئيسية',
  closeNavigation: 'إغلاق القائمة الرئيسية',
  closeNotifications: 'إغلاق الإشعارات',
  navigationTitle: 'التنقل الرئيسي',
  platformUser: 'مستخدم المنصة',
  internalSystem: 'منصة موحدة',
  collapseNavigation: 'طي القائمة الجانبية',
  expandNavigation: 'توسيع القائمة الجانبية',
  scopeSelectorLabel: 'النطاق الحالي',
  scopeSelectorEmpty: 'لا توجد نطاقات',
  scopeChangePending: 'جارٍ تغيير النطاق',
  scopeStale: 'تغير النطاق من جلسة أخرى',
  scopeRetry: 'إعادة التحميل',
}

const navigationItems: SidebarNavigationItem[] = [{
  key: 'home',
  label: 'الرئيسية',
  path: '/',
  icon: null,
  active: true,
  onSelect: vi.fn(),
}]

function renderShell(overrides: Partial<ComponentProps<typeof AppShell>> = {}) {
  return render(
    <AppShell
      locale="ar"
      copy={copy}
      facilityName="منشأة الاختبار"
      navigationItems={navigationItems}
      unreadNotifications={0}
      notificationButtonRef={createRef<HTMLButtonElement>()}
      notificationsOpen={false}
      onLocaleChange={vi.fn()}
      onNotificationsToggle={vi.fn()}
      onLogout={vi.fn()}
      userMenu={[{ key: 'security', label: 'الأمان الشخصي', path: '/me/security', onSelect: vi.fn() }]}
      notificationPanel={<p>لا توجد إشعارات</p>}
      {...overrides}
    >
      <h1>المحتوى</h1>
    </AppShell>,
  )
}

beforeEach(() => {
  window.localStorage.clear()
  Object.defineProperty(window, 'matchMedia', {
    configurable: true,
    value: vi.fn(() => ({
      matches: false,
      media: '',
      onchange: null,
      addEventListener: vi.fn(),
      removeEventListener: vi.fn(),
      addListener: vi.fn(),
      removeListener: vi.fn(),
      dispatchEvent: vi.fn(),
    })),
  })
  window.requestAnimationFrame = (callback) => {
    callback(0)
    return 1
  }
})

afterEach(() => {
  cleanup()
  vi.clearAllMocks()
})

describe('AppShell', () => {
  it('receives complete localized scope feedback from the application copy adapter', () => {
    expect(shellCopy('ar')).toMatchObject({
      scopeSelectorLabel: 'اختيار نطاق العمل',
      scopeSelectorEmpty: 'لا توجد نطاقات عمل متاحة',
      scopeChangePending: 'جارٍ تغيير نطاق العمل…',
      scopeStale: 'تغيّر نطاق العمل أو تعذر تحديثه.',
      scopeRetry: 'إعادة تحميل النطاق',
    })
    expect(shellCopy('en')).toMatchObject({
      scopeSelectorLabel: 'Select work scope',
      scopeSelectorEmpty: 'No work scopes are available',
      scopeChangePending: 'Changing work scope…',
      scopeStale: 'The work scope changed or could not be refreshed.',
      scopeRetry: 'Reload scope',
    })
  })

  it('renders flat navigation, current-page semantics, collapse state, and the separate user menu', () => {
    renderShell()

    expect(screen.getByRole('link', { name: 'الرئيسية' }).getAttribute('aria-current')).toBe('page')
    expect(screen.queryByRole('button', { name: 'مساحة عملي' })).toBeNull()
    expect(screen.queryByRole('button', { expanded: true })).toBeNull()

    fireEvent.click(screen.getByRole('button', { name: 'طي القائمة الجانبية' }))
    expect(document.querySelector('.app-shell')?.getAttribute('data-sidebar-collapsed')).toBe('true')
    expect(window.localStorage.getItem('cluster.sidebar-collapsed')).toBe('true')

    fireEvent.click(screen.getByRole('button', { name: 'مستخدم المنصة' }))
    expect(screen.getByRole('menuitem', { name: 'الأمان الشخصي' })).toBeTruthy()
    expect(screen.getByRole('menuitem', { name: 'English' })).toBeTruthy()
    expect(screen.getByRole('menuitem', { name: 'تسجيل الخروج' })).toBeTruthy()
  })

  it('keeps scope transition and stale recovery visible after options are cleared', () => {
    const retry = vi.fn()
    const view = renderShell({
      scopeSelector: {
        current: null,
        options: [],
        pending: true,
        disabled: true,
        onSelect: vi.fn(),
      },
    })

    expect(screen.getByRole('status').textContent).toContain('جارٍ تغيير النطاق')
    expect((screen.getByLabelText('النطاق الحالي') as HTMLButtonElement).disabled).toBe(true)

    view.rerender(
      <AppShell
        locale="ar"
        copy={copy}
        facilityName="منشأة الاختبار"
        navigationItems={navigationItems}
        unreadNotifications={0}
        notificationButtonRef={createRef<HTMLButtonElement>()}
        notificationsOpen={false}
        onLocaleChange={vi.fn()}
        onNotificationsToggle={vi.fn()}
        onLogout={vi.fn()}
        notificationPanel={null}
        scopeSelector={{ current: null, options: [], stale: true, disabled: true, onSelect: vi.fn(), onRetry: retry }}
      >
        <h1>المحتوى</h1>
      </AppShell>,
    )

    expect(screen.getByRole('alert').textContent).toContain('تغير النطاق من جلسة أخرى')
    fireEvent.click(screen.getByRole('button', { name: 'إعادة التحميل' }))
    expect(retry).toHaveBeenCalledOnce()
  })

  it('closes the mobile drawer with Escape and restores focus to its trigger', () => {
    renderShell()
    const trigger = screen.getByRole('button', { name: 'فتح القائمة الرئيسية' })
    fireEvent.click(trigger)
    expect(screen.getByRole('dialog', { name: 'التنقل الرئيسي' })).toBeTruthy()

    fireEvent.keyDown(document, { key: 'Escape' })

    expect(screen.queryByRole('dialog', { name: 'التنقل الرئيسي' })).toBeNull()
    expect(document.activeElement).toBe(trigger)
  })
})

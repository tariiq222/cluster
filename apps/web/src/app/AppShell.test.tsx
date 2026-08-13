// @vitest-environment jsdom
import { describe, expect, it } from 'vitest'
import {
  fireEvent,
  render,
  screen,
  waitFor,
  within,
} from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { AppShell } from './AppShell'
import { PrincipalContextTestProvider } from './principal-context'
import { SessionProvider } from './session-context'
import { ThemeProvider } from '@/components/theme-provider'
import { commandShortcut } from '@/lib/keyboard-shortcut'

const session = {
  csrfToken: 'test-csrf',
  userId: 'test-user',
  expiresAt: '2026-12-31T00:00:00Z',
  restricted: false,
}

function shell(
  capabilities: string[],
  features: { tasks: boolean },
  initialEntries: string[] = ['/'],
  effectiveScope: {
    scopeType: string
    scopeId: string
    label: string
  } | null = null,
) {
  return render(
    <MemoryRouter initialEntries={initialEntries}>
      <ThemeProvider>
        <SessionProvider session={session} locale="ar" setLocale={() => {}}>
          <PrincipalContextTestProvider
            capabilities={capabilities}
            features={features}
            effectiveScope={effectiveScope}
          >
            <AppShell onLogout={() => {}} />
          </PrincipalContextTestProvider>
        </SessionProvider>
      </ThemeProvider>
    </MemoryRouter>,
  )
}

describe('app shell navigation', () => {
  it('never exposes retired work-management destinations', () => {
    const { container } = shell(
      ['work_record.list', 'workflow.read'],
      { tasks: true },
    )
    // Absent, not disabled, and with no explanatory text — the API answers 404
    // without disclosing existence, and the UI must not undo that.
    expect(container.textContent).not.toContain('سجلات العمل')
    expect(container.textContent).not.toContain('صندوق الموافقات')
    expect(container.textContent).not.toContain('غير متاح')
    expect(container.querySelector('[aria-disabled="true"]')).toBeNull()
  })

  it('shows the organization entry to a unit-only administrator', () => {
    shell(['organization.unit.read'], { tasks: false })

    expect(
      screen.getByRole('link', { name: 'المنظمة', exact: true }),
    ).toHaveAttribute('href', '/organization')
  })

  it('hides the organization entry from an unrelated principal', () => {
    shell(['tasks.list'], { tasks: true })

    expect(
      screen.queryByRole('link', { name: 'المنظمة', exact: true }),
    ).toBeNull()
  })
})

describe('app shell brand lockup', () => {
  it('renders the brand title and the bilingual operations-workspace subtitle', () => {
    shell([], { tasks: false })
    expect(screen.getByText('منصة التجمع الصحي')).toBeTruthy()
    expect(screen.getByText('مساحة العمليات')).toBeTruthy()
  })

  it('keeps the home link as the brand destination', () => {
    const { container } = shell([], { tasks: false })
    const brand = container.querySelector('a[href="/"]')
    expect(brand).not.toBeNull()
    expect(brand?.textContent).toContain('منصة التجمع الصحي')
  })
})

describe('app shell header', () => {
  it('labels the header as a control bar', () => {
    shell([], { tasks: false })
    expect(screen.getByLabelText('شريط الأدوات')).toBeTruthy()
  })

  it('derives the current destination from the nav parent on nested routes', () => {
    shell(
      ['organization.cluster.read'],
      { tasks: false },
      ['/organization/import'],
    )
    const header = screen.getByLabelText('شريط الأدوات')
    expect(within(header).getByText('المنظمة والوصول')).toBeTruthy()
    expect(within(header).getByText('المنظمة')).toBeTruthy()
  })

  it('keeps the current destination and effective scope in the responsive header', () => {
    shell(
      ['organization.cluster.read'],
      { tasks: false },
      ['/organization'],
      {
        scopeType: 'facility',
        scopeId: 'facility-1',
        label: 'منشأة الاختبار',
      },
    )
    const header = screen.getByLabelText('شريط الأدوات')
    expect(within(header).getByText('المنظمة')).toBeTruthy()
    expect(within(header).getByText('النطاق: منشأة الاختبار')).toBeTruthy()
    // The desktop effective-scope badge was removed from the control bar;
    // the responsive scope text above is the only header scope display.
    expect(header.querySelector('[data-slot="badge"]')).toBeNull()
  })

  it('uses the localized account label for the /me destination outside the nav', () => {
    shell([], { tasks: false }, ['/me'])
    expect(
      within(screen.getByLabelText('شريط الأدوات')).getByText('حسابي'),
    ).toBeTruthy()
  })

  it('uses the localized notifications label for the /notifications destination outside the nav', () => {
    shell([], { tasks: false }, ['/notifications'])
    expect(
      within(screen.getByLabelText('شريط الأدوات')).getByText('الإشعارات'),
    ).toBeTruthy()
  })

  it('uses the localized search label for the /search destination outside the nav', () => {
    shell([], { tasks: false }, ['/search'])
    expect(
      within(screen.getByLabelText('شريط الأدوات')).getAllByText('بحث').length,
    ).toBe(2)
  })

  it('links directly to notifications from the header', () => {
    shell([], { tasks: false })
    const links = screen.getAllByRole('link', { name: 'الإشعارات' })
    expect(links.length).toBeGreaterThanOrEqual(1)
    for (const link of links)
      expect(link).toHaveAttribute('href', '/notifications')
  })

  it('localizes the trigger and rail accessible names and titles', () => {
    shell([], { tasks: false })
    // The rail carries the same accessible name; the header trigger is the
    // one with the data-sidebar="trigger" attribute.
    const controls = screen.getAllByRole('button', {
      name: 'تبديل الشريط الجانبي',
    })
    const trigger = controls.find(
      (button) => button.getAttribute('data-sidebar') === 'trigger',
    )
    const rail = controls.find(
      (button) => button.getAttribute('data-sidebar') === 'rail',
    )
    expect(trigger).toBeTruthy()
    expect(trigger).toHaveAttribute('aria-label', 'تبديل الشريط الجانبي')
    expect(trigger).toHaveAttribute('title', 'تبديل الشريط الجانبي')
    // The trigger is linked to the navigation landmark and exposes the
    // controlled state; on the jsdom desktop viewport it starts expanded.
    expect(trigger).toHaveAttribute('aria-controls', 'app-sidebar-navigation')
    expect(trigger).toHaveAttribute('aria-expanded', 'true')
    expect(rail).toBeTruthy()
    expect(rail).toHaveAttribute('aria-label', 'تبديل الشريط الجانبي')
    expect(rail).toHaveAttribute('title', 'تبديل الشريط الجانبي')
  })

  it('offers the command search affordance in both the desktop and icon-only forms', () => {
    shell([], { tasks: false })
    // Desktop: outline button labelled with the localized search word; mobile:
    // icon-only button carrying the same accessible label.
    expect(
      screen.getAllByRole('button', { name: /بحث/ }).length,
    ).toBeGreaterThanOrEqual(2)
  })

  it('uses the capability-filtered sidebar destinations in command search', () => {
    shell([], { tasks: false })
    fireEvent.keyDown(document, { key: 'k', metaKey: true })
    const dialog = screen.getByRole('dialog')
    expect(within(dialog).getByText('الرئيسية')).toBeTruthy()
    expect(within(dialog).queryByText('المهام')).toBeNull()
    expect(within(dialog).queryByText('المستندات')).toBeNull()
    expect(within(dialog).queryByText('المنظمة')).toBeNull()
  })

  it('hides the search shortcut from the accessible name', () => {
    const { container } = shell([], { tasks: false })
    const kbd = container.querySelector('kbd')
    expect(kbd).not.toBeNull()
    expect(kbd).toHaveAttribute('aria-hidden', 'true')
    expect(kbd).toHaveAttribute('dir', 'ltr')
    expect(kbd).toHaveTextContent(commandShortcut(navigator))
    // Both search affordances keep the localized label; no button exposes the shortcut.
    expect(
      screen.getAllByRole('button', { name: 'بحث', exact: true }).length,
    ).toBeGreaterThanOrEqual(2)
    expect(
      screen.queryAllByRole('button', { name: /(?:⌘|Ctrl\+)K/ }),
    ).toHaveLength(0)
  })

  /*
   * ACC-04-POLISH: the kbd shortcut affordance used `text-muted-foreground`
   * on `bg-muted`, which measured 4.34:1 against the muted surface in
   * light mode. The polish spec raises it to the `text-foreground`
   * token so the contrast against the muted surface is >=4.5:1 in both
   * light and dark mode. The regression test pins the class swap.
   */
  it('uses the foreground token for the kbd shortcut so contrast clears 4.5:1', () => {
    const { container } = shell([], { tasks: false })
    const kbd = container.querySelector('kbd')
    expect(kbd).not.toBeNull()
    expect(kbd!.className).toMatch(/\btext-foreground\b/)
    expect(kbd!.className).not.toMatch(/\btext-muted-foreground\b/)
    expect(kbd!.className).toMatch(/\bbg-muted\b/)
  })

  it('keeps the desktop language control on the documented text scale', () => {
    shell([], { tasks: false })
    expect(screen.getByRole('button', { name: 'English' })).toHaveClass(
      'text-sm',
    )
  })

  it('keeps every interactive account-menu row at least 44px tall', () => {
    shell([], { tasks: false })
    fireEvent.pointerDown(screen.getByRole('button', { name: 'الحساب' }), {
      button: 0,
      ctrlKey: false,
    })

    const rows = [
      ...screen.getAllByRole('menuitem'),
      ...screen.getAllByRole('menuitemradio'),
    ]
    expect(rows.length).toBeGreaterThan(0)
    for (const row of rows) expect(row).toHaveClass('min-h-11')
  })

  it('opens route-specific help with the effective scope', () => {
    shell(
      ['tasks.list'],
      { tasks: true },
      ['/tasks/task-1'],
      {
        scopeType: 'facility',
        scopeId: 'facility-1',
        label: 'منشأة الاختبار',
      },
    )
    fireEvent.click(screen.getByRole('button', { name: 'المساعدة' }))
    const dialog = screen.getByRole('dialog', { name: 'مساعدة المهام' })
    expect(within(dialog).getByText('منشأة الاختبار')).toBeTruthy()
    expect(within(dialog).getByText('الدعم الفني')).toBeTruthy()
    expect(dialog).toHaveTextContent('اشرح الإجراء الذي كنت تنفذه')
    expect(dialog).not.toHaveTextContent('معرّف الارتباط')
  })

  it('keeps help open after closing the mobile sidebar', async () => {
    const desktopWidth = window.innerWidth
    Object.defineProperty(window, 'innerWidth', {
      configurable: true,
      value: 390,
    })

    const view = shell([], { tasks: false })

    try {
      const sidebarTrigger = await screen.findByRole('button', {
        name: 'تبديل الشريط الجانبي',
      })
      fireEvent.click(sidebarTrigger)
      fireEvent.click(await screen.findByRole('button', { name: 'المساعدة' }))

      expect(
        await screen.findByRole('dialog', { name: 'مساعدة مساحة العمليات' }),
      ).toBeVisible()
      await waitFor(() =>
        expect(sidebarTrigger).toHaveAttribute('aria-expanded', 'false'),
      )
    } finally {
      view.unmount()
      Object.defineProperty(window, 'innerWidth', {
        configurable: true,
        value: desktopWidth,
      })
    }
  })

  it('uses notification-specific help on the notifications route', () => {
    shell(
      [],
      { tasks: false },
      ['/notifications'],
    )
    fireEvent.click(screen.getByRole('button', { name: 'المساعدة' }))

    expect(
      screen.getByRole('dialog', { name: 'مساعدة الإشعارات' }),
    ).toBeVisible()
  })

  it('marks the header notifications link as current only on the notifications route', () => {
    const { container } = shell([], { tasks: false }, [
      '/notifications',
    ])
    expect(container.querySelector('a[href="/notifications"]')).toHaveAttribute(
      'aria-current',
      'page',
    )
    const { container: other } = shell([], { tasks: false })
    expect(other.querySelector('a[href="/notifications"]')).not.toHaveAttribute(
      'aria-current',
    )
  })
})

describe('app shell header height parity', () => {
  /* SHELL-01-HEADER-HEIGHT: the desktop sidebar brand header had no fixed
   * height contract; the generated SidebarHeader contributed p-2 around the
   * h-12 brand control (~64px), so its bottom border sat ~8px below the
   * main h-14 control-bar border. Both headers must share the same fixed
   * 56px height and own the border, and the sidebar header must keep an
   * explicit vertical centering/padding contract so the h-12 brand control
   * stays centered with 4px top/bottom padding (px-2 + py-1). The generated
   * sidebar primitive stays untouched. */
  it('locks the main control bar and the sidebar brand header to h-14 + shrink-0 and centers the brand control', () => {
    const { container } = shell([], { tasks: false })
    const controlBar = screen.getByLabelText('شريط الأدوات')
    expect(controlBar.className).toContain('h-14')
    expect(controlBar.className).toContain('shrink-0')

    const sidebarHeader = container.querySelector('[data-sidebar="header"]')
    expect(sidebarHeader).not.toBeNull()
    expect(sidebarHeader!.className).toContain('h-14')
    expect(sidebarHeader!.className).toContain('shrink-0')
    expect(sidebarHeader!.className).toContain('justify-center')
    // Override the generated p-2 so the h-12 brand control centers inside 56px.
    expect(sidebarHeader!.className).toContain('px-2')
    expect(sidebarHeader!.className).toContain('py-1')
    // The border contract is preserved so the bottom borders align exactly.
    expect(sidebarHeader!.className).toContain('border-b')
    expect(sidebarHeader!.className).toContain('border-sidebar-border')
  })
})

describe('app shell sidebar navigation', () => {
  it('marks the active navigation link with data-active and aria-current only', () => {
    const { container } = shell(
      ['documents.list'],
      { tasks: false },
      ['/documents'],
    )
    const activeLink = container.querySelector(
      'a[href="/documents"][aria-current="page"]',
    )
    const inactiveHomeLink = container.querySelector(
      'a[href="/"][data-size="default"]',
    )
    expect(activeLink).toHaveAttribute('data-active', 'true')
    // The generated styles match `data-active` on presence, so inactive
    // controls must omit the attribute entirely — never data-active="false".
    expect(inactiveHomeLink).not.toHaveAttribute('data-active')
    expect(inactiveHomeLink).not.toHaveAttribute('aria-current')
  })

  it('omits data-active from every inactive sidebar link', () => {
    const { container } = shell(
      ['documents.list'],
      { tasks: false },
      ['/documents'],
    )
    const sidebarLinks = container.querySelectorAll(
      'a[data-sidebar="menu-button"]',
    )
    expect(sidebarLinks.length).toBeGreaterThanOrEqual(3)
    for (const link of sidebarLinks) {
      if (link.getAttribute('href') === '/documents') {
        expect(link).toHaveAttribute('data-active', 'true')
      } else {
        expect(link).not.toHaveAttribute('data-active')
      }
    }
  })

  it('omits data-active from the help trigger as a non-route action', () => {
    shell([], { tasks: false })
    const helpButton = screen.getByRole('button', { name: 'المساعدة' })
    // The generated SidebarMenuButton styles match `data-active` on presence,
    // so the help action must omit the attribute entirely — never
    // data-active="false" — and it is never an active destination.
    expect(helpButton).not.toHaveAttribute('data-active')
    expect(helpButton).not.toHaveAttribute('aria-current')
  })

  it('never marks the brand link as active on any route', () => {
    const { container } = shell([], { tasks: false }, [
      '/',
    ])
    const brandLink = container.querySelector('a[href="/"][data-size="lg"]')
    expect(brandLink).not.toBeNull()
    expect(brandLink).not.toHaveAttribute('data-active')
    expect(brandLink).not.toHaveAttribute('aria-current')
  })

  it('exposes navigation groups as headings', () => {
    shell([], { tasks: false })
    expect(
      screen.getByRole('heading', { level: 2, name: 'نظرة عامة' }),
    ).toBeTruthy()
  })

  it('marks the footer account destination as current on the account route', () => {
    const { container } = shell([], { tasks: false }, [
      '/me',
    ])
    const footerLink = container.querySelector(
      'a[href="/me"][data-sidebar="menu-button"]',
    )
    expect(footerLink).toHaveAttribute('aria-current', 'page')
    expect(footerLink).toHaveAttribute('data-active', 'true')
  })

  it('leaves the footer account destination unmarked outside the account route', () => {
    const { container } = shell([], { tasks: false })
    const footerLink = container.querySelector(
      'a[href="/me"][data-sidebar="menu-button"]',
    )
    expect(footerLink).not.toHaveAttribute('aria-current')
    expect(footerLink).not.toHaveAttribute('data-active')
  })

  it('renders a mobile-only close control labelled with the localized copy', () => {
    shell([], { tasks: false })
    const close = screen.getByRole('button', { name: 'إغلاق القائمة' })
    expect(close).toBeTruthy()
    // 44x44 target below md, hidden on desktop and icon-collapse modes.
    expect(close.className).toContain('size-11')
    expect(close.className).toContain('md:hidden')
    expect(close.className).toContain('focus-visible:ring-foreground!')
    // The close control targets the same navigation landmark as the trigger;
    // it closes the mobile sheet, so it must not claim aria-expanded itself.
    expect(close).toHaveAttribute('aria-controls', 'app-sidebar-navigation')
    expect(close).not.toHaveAttribute('aria-expanded')
  })

  it('exposes the navigation landmark with a stable id the trigger controls', () => {
    shell([], { tasks: false })
    const nav = document.getElementById('app-sidebar-navigation')
    expect(nav).not.toBeNull()
    expect(nav).toHaveAttribute('role', 'navigation')
    expect(nav).toHaveAttribute('aria-label', 'القائمة')
    const trigger = screen
      .getAllByRole('button', { name: 'تبديل الشريط الجانبي' })
      .find((button) => button.getAttribute('data-sidebar') === 'trigger')
    expect(trigger).toHaveAttribute('aria-controls', 'app-sidebar-navigation')
  })

  it('shows the account label first in the footer when no scope is supplied', () => {
    const { container } = shell([], { tasks: false })
    const footerLinks = container.querySelectorAll('a[href="/me"]')
    expect(footerLinks.length).toBeGreaterThanOrEqual(1)
    // The test principal carries no effective scope: the footer falls back to
    // the plain account label — no scope caption masquerading as the name.
    expect(footerLinks[0].textContent).toBe('حسابي')
  })
})

describe('app shell skip link', () => {
  it('targets the main content and keeps its focus ring behavior', () => {
    shell([], { tasks: false })
    const skip = screen.getByRole('link', { name: 'تخطَّ إلى المحتوى' })
    expect(skip).toHaveAttribute('href', '#main-content')
    expect(skip).toHaveClass('focus:not-sr-only')
    expect(skip).toHaveClass('focus:ring-2')
  })

  it('uses the high-contrast foreground token for its focus ring', () => {
    shell([], { tasks: false })
    const skip = screen.getByRole('link', { name: 'تخطَّ إلى المحتوى' })
    expect(skip).toHaveClass('focus:ring-foreground!')
    expect(skip.className).not.toContain('ring-ring')
  })
})

// @vitest-environment jsdom
import { cleanup, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'

import { AccessScopesScreen } from './AccessScopesScreen'

function renderScreen(locale: 'ar' | 'en') {
  return render(<AccessScopesScreen locale={locale} />)
}

describe('AccessScopesScreen', () => {
  afterEach(cleanup)

  it('redirects the legacy scope surface to policies and scopes without rendering a passive table', () => {
    renderScreen('en')

    expect(screen.getByText('Access scopes are now managed in the Policies & Scopes tab.')).toBeTruthy()
    expect(screen.queryByRole('table')).toBeNull()
  })

  it('localizes the retirement redirect', () => {
    renderScreen('ar')

    expect(screen.getByText('تُدار نطاقات الصلاحيات الآن في تبويب السياسات والنطاقات.')).toBeTruthy()
  })
})

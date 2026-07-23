// @vitest-environment jsdom
import { cleanup, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'
import { BusinessCalendarsScreen } from './BusinessCalendarsScreen'
afterEach(cleanup)
describe('BusinessCalendarsScreen', () => {
  it('shows source inheritance and hides official-holiday override without permission', () => {
    render(<BusinessCalendarsScreen locale="ar" allowedActions={[]} />)
    expect(screen.getAllByText('مصدر: المنصة')).toHaveLength(2)
    expect(screen.queryByRole('button', { name: 'طلب العمل أثناء عطلة رسمية' })).toBeNull()
  })
})

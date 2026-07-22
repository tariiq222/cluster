// @vitest-environment jsdom
import { afterEach, describe, expect, it } from 'vitest'
import { cleanup, render, screen } from '@testing-library/react'

import { NewProcedureRequest } from './NewProcedureRequest'

describe('NewProcedureRequest screen', () => {
  afterEach(() => cleanup())

  it('renders the Arabic heading and required request sections', () => {
    render(<NewProcedureRequest locale="ar" />)
    expect(screen.getByRole('heading', { name: 'طلب إجراء جديد' })).toBeTruthy()
    expect(screen.getAllByText('1. تعريف الإجراء').length).toBeGreaterThan(0)
    expect(screen.getAllByText('2. البيانات المطلوب جمعها').length).toBeGreaterThan(0)
    expect(screen.getByRole('button', { name: 'إرسال إلى مكتب إدارة العمليات' })).toBeTruthy()
  })
})

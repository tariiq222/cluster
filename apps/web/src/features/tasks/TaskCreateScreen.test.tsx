// @vitest-environment jsdom
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { SessionProvider } from '../../app/session-context'
import { PrincipalContextTestProvider } from '../../app/principal-context'
import { TaskCreateScreen } from './TaskCreateScreen'
import { ApiError } from '../../api/http'

/*
 * Coverage matrix (FORM-MIGRATE-TD-02 §1):
 *   • form is the shared `TwoRegionFormLayout` (no nested form, no legacy
 *     `max-w-2xl rounded-lg border p-4` narrow island);
 *   • test ids `task-create-form`, `task-create-main`, `task-create-review`
 *     exist and identify the canonical regions;
 *   • both select triggers fill their column (`w-full`);
 *   • the review surface is a semantic `<dl>` with one row per form field
 *     and the rows are fed by watched values (typing in the title updates
 *     the title row);
 *   • mutation payload is preserved (priority/classification casts,
 *     optional description, optional due_at, assignee_user_id from
 *     trimmed value, undefined when blank);
 *   • cancel navigates back to `/tasks` and never invokes the mutation;
 *   • 403 / 409 / generic server errors are surfaced through the
 *     `title` field error message;
 *   • required-title validation message is rendered when the user
 *     submits an empty title.
 *
 * `useTaskMutations` is mocked here so the screen can be exercised
 * without touching the real network — same pattern as the task detail
 * test.
 */

const session = {
  csrfToken: 'csrf',
  userId: '01980f50-5f0d-7000-8000-000000000001',
  expiresAt: '2027-01-01T00:00:00Z',
  restricted: false,
}

const navigateSpy = vi.fn()
vi.mock('react-router-dom', async (importOriginal) => {
  const actual = await importOriginal<typeof import('react-router-dom')>()
  return {
    ...actual,
    MemoryRouter: actual.MemoryRouter,
    useNavigate: () => navigateSpy,
  }
})

const createMutateAsync = vi.fn()
const createMutation = {
  mutateAsync: createMutateAsync,
  isPending: false,
  isError: false,
  isSuccess: false,
  data: undefined,
  error: null,
  reset: () => {},
}

vi.mock('../../api/hooks', () => ({
  useTaskMutations: () => ({
    create: createMutation,
    update: { isPending: false, mutateAsync: vi.fn() },
    transition: { isPending: false, mutateAsync: vi.fn() },
    addComment: { isPending: false, mutateAsync: vi.fn() },
    addParticipant: { isPending: false, mutateAsync: vi.fn() },
  }),
}))

function mountPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <MemoryRouter initialEntries={['/tasks/new']}>
      <SessionProvider session={session} locale="ar" setLocale={() => {}}>
        <PrincipalContextTestProvider
          capabilities={['tasks.create']}
          features={{ work_management: false, tasks: true }}
        >
          <QueryClientProvider client={client}>
            <TaskCreateScreen />
          </QueryClientProvider>
        </PrincipalContextTestProvider>
      </SessionProvider>
    </MemoryRouter>,
  )
}

beforeEach(() => {
  navigateSpy.mockReset()
  createMutateAsync.mockReset()
  createMutateAsync.mockResolvedValue({ id: 't-new-1' })
})

describe('TaskCreateScreen — shared form primitives', () => {
  it('renders the shared form, main, and review regions with the canonical test ids', () => {
    mountPage()
    const form = screen.getByTestId('task-create-form')
    expect(form).toBeInTheDocument()
    expect(form.tagName).toBe('FORM')
    // TwoRegionFormLayout contract: no narrow max-w-* island, two-region grid.
    expect(form.className).not.toMatch(/\bmax-w-/)
    expect(form.className).toMatch(/lg:grid-cols-\[2fr_1fr\]/)
    const main = screen.getByTestId('task-create-main')
    const review = screen.getByTestId('task-create-review')
    expect(main).toBeInTheDocument()
    expect(review).toBeInTheDocument()
    expect(main.tagName).toBe('DIV')
    expect(review.tagName).toBe('ASIDE')
    // Main is the first DOM child of the form so it stays first in tab/
    // screen-reader order.
    expect(form.firstElementChild).toBe(main)
  })

  it('drops the legacy max-w-2xl rounded-lg border p-4 narrow wrapper', () => {
    mountPage()
    // The legacy narrow wrapper must not exist anywhere inside the form.
    const form = screen.getByTestId('task-create-form')
    expect(form.querySelector('.max-w-2xl.rounded-lg.border.p-4')).toBeNull()
    // And nothing inside the screen as a whole renders that legacy class
    // combination on a non-allowed surface.
    expect(
      document.querySelector('main .max-w-2xl.rounded-lg.border.p-4'),
    ).toBeNull()
  })

  it('renders both select triggers as full-width within their column', () => {
    mountPage()
    const priorityTrigger = document.getElementById('task-create-priority') as HTMLButtonElement | null
    const classificationTrigger = document.getElementById(
      'task-create-classification',
    ) as HTMLButtonElement | null
    expect(priorityTrigger).not.toBeNull()
    expect(classificationTrigger).not.toBeNull()
    expect(priorityTrigger!.className).toMatch(/\bw-full\b/)
    expect(classificationTrigger!.className).toMatch(/\bw-full\b/)
  })

  it('surfaces two semantic FormSection headings (essentials + planning/access) inside the main region', () => {
    mountPage()
    const main = screen.getByTestId('task-create-main')
    const essentialsHeading = screen.getByRole('heading', {
      level: 2,
      name: 'معلومات أساسية',
    })
    const planningHeading = screen.getByRole('heading', {
      level: 2,
      name: 'التخطيط والصلاحيات',
    })
    expect(essentialsHeading).toBeInTheDocument()
    expect(planningHeading).toBeInTheDocument()
    expect(main.contains(essentialsHeading)).toBe(true)
    expect(main.contains(planningHeading)).toBe(true)
  })

  it('uses distinct bilingual group headings that are not the adjacent field labels', () => {
    mountPage()
    const essentialsHeading = screen.getByRole('heading', {
      level: 2,
      name: 'معلومات أساسية',
    })
    const titleLabel = document.querySelector('label[for="task-create-title"]')
    expect(titleLabel?.textContent).toBe('العنوان')
    expect(essentialsHeading.textContent).not.toBe(titleLabel?.textContent)

    const planningHeading = screen.getByRole('heading', {
      level: 2,
      name: 'التخطيط والصلاحيات',
    })
    const priorityLabel = document.querySelector('label[for="task-create-priority"]')
    expect(priorityLabel?.textContent).toBe('الأولوية')
    expect(planningHeading.textContent).not.toBe(priorityLabel?.textContent)
  })
})

describe('TaskCreateScreen — review surface (live watched values)', () => {
  it('renders the review summary as a semantic <dl> with one row per form field', () => {
    mountPage()
    const review = screen.getByTestId('task-create-review')
    const dl = review.querySelector('dl')
    expect(dl).not.toBeNull()
    // 6 fields → 6 dt/dd pairs: title, description, priority,
    // classification, assignee, due at.
    expect(dl!.querySelectorAll('dt')).toHaveLength(6)
    expect(dl!.querySelectorAll('dd')).toHaveLength(6)
  })

  it('reflects typed values in the review <dl> (live watched values, no submit needed)', () => {
    mountPage()
    fireEvent.change(screen.getByLabelText('العنوان'), {
      target: { value: 'إطار التحول الرقمي' },
    })
    fireEvent.change(screen.getByLabelText('الوصف'), {
      target: { value: 'وصف اختياري' },
    })
    fireEvent.change(screen.getByLabelText('تاريخ الاستحقاق'), {
      target: { value: '2027-01-15T09:30' },
    })
    const review = screen.getByTestId('task-create-review')
    // Title row is updated live with isolate bdi wrapping the free-form
    // text — same contract as DocumentCreate's review.
    const titleDd = Array.from(review.querySelectorAll('dd')).find(
      (dd) => dd.previousElementSibling?.textContent === 'العنوان',
    )
    expect(titleDd).toBeDefined()
    const titleBdi = titleDd!.querySelector('bdi[dir="auto"]')
    expect(titleBdi).not.toBeNull()
    expect(titleBdi!.textContent).toBe('إطار التحول الرقمي')
    // Description row carries the typed description.
    expect(review.textContent).toContain('وصف اختياري')
    // Due-at row reflects the typed local datetime.
    const dueAtDd = Array.from(review.querySelectorAll('dd')).find(
      (dd) => dd.previousElementSibling?.textContent === 'تاريخ الاستحقاق',
    )
    expect(dueAtDd).toBeDefined()
    expect(dueAtDd!.textContent).toContain('2027-01-15T09:30')
  })

  it('falls back to the localized placeholder when a text field is empty', () => {
    mountPage()
    const review = screen.getByTestId('task-create-review')
    // Title left empty → fallback sentinel.
    const titleDd = Array.from(review.querySelectorAll('dd')).find(
      (dd) => dd.previousElementSibling?.textContent === 'العنوان',
    )
    expect(titleDd).toBeDefined()
    expect(titleDd!.querySelector('.text-muted-foreground')).not.toBeNull()
    // Description left empty → localized "no description" fallback.
    const descriptionDd = Array.from(review.querySelectorAll('dd')).find(
      (dd) => dd.previousElementSibling?.textContent === 'الوصف',
    )
    expect(descriptionDd).toBeDefined()
    expect(descriptionDd!.textContent).toContain('لا يوجد وصف')
    // Due-at left empty → "no due date" fallback.
    const dueAtDd = Array.from(review.querySelectorAll('dd')).find(
      (dd) => dd.previousElementSibling?.textContent === 'تاريخ الاستحقاق',
    )
    expect(dueAtDd).toBeDefined()
    expect(dueAtDd!.textContent).toContain('بدون تاريخ استحقاق')
  })

  it('shows the localized priority/classification labels (not raw enum values)', () => {
    mountPage()
    const review = screen.getByTestId('task-create-review')
    // Default priority = 'normal' → "عادية" (the localized human label).
    const priorityDd = Array.from(review.querySelectorAll('dd')).find(
      (dd) => dd.previousElementSibling?.textContent === 'الأولوية',
    )
    expect(priorityDd).toBeDefined()
    expect(priorityDd!.textContent).toContain('عادية')
    // Default classification = 'internal' → "داخلي".
    const classificationDd = Array.from(review.querySelectorAll('dd')).find(
      (dd) => dd.previousElementSibling?.textContent === 'التصنيف',
    )
    expect(classificationDd).toBeDefined()
    expect(classificationDd!.textContent).toContain('داخلي')
    // The raw enum strings must never leak into the review.
    expect(review.textContent).not.toMatch(/\bnormal\b/)
    expect(review.textContent).not.toMatch(/\binternal\b/)
  })
})

describe('TaskCreateScreen — submit, validation, and navigation', () => {
  it('submits the canonical payload and navigates to the new task', async () => {
    mountPage()
    fireEvent.change(screen.getByLabelText('العنوان'), {
      target: { value: 'مهمة جديدة' },
    })
    fireEvent.change(screen.getByLabelText('الوصف'), {
      target: { value: 'وصف اختياري' },
    })
    fireEvent.change(screen.getByLabelText('المسند إليه'), {
      target: { value: '  user-2  ' },
    })
    fireEvent.change(screen.getByLabelText('تاريخ الاستحقاق'), {
      target: { value: '2027-02-01T10:00' },
    })
    fireEvent.click(screen.getByRole('button', { name: 'أنشئ المهمة' }))

    await waitFor(() => expect(createMutateAsync).toHaveBeenCalledTimes(1))
    const [input] = createMutateAsync.mock.calls[0] as [
      {
        title: string
        description?: string
        priority: string
        classification: string
        assignee_user_id?: string
        due_at?: string
      },
    ]
    expect(input.title).toBe('مهمة جديدة')
    expect(input.description).toBe('وصف اختياري')
    expect(input.priority).toBe('normal')
    expect(input.classification).toBe('internal')
    // Assignee is trimmed; an explicit value is passed (not undefined).
    expect(input.assignee_user_id).toBe('user-2')
    // due_at is converted from the datetime-local string to ISO 8601
    // using the host timezone — derive the expected value dynamically so
    // the assertion stays timezone-agnostic across CI and local runs.
    expect(input.due_at).toBe(new Date('2027-02-01T10:00').toISOString())

    await waitFor(() => expect(navigateSpy).toHaveBeenCalledWith('/tasks/t-new-1'))
  })

  it('omits description and due_at from the payload when blank, and clears assignee when blank', async () => {
    mountPage()
    fireEvent.change(screen.getByLabelText('العنوان'), {
      target: { value: 'بدون تفاصيل' },
    })
    fireEvent.change(screen.getByLabelText('المسند إليه'), {
      target: { value: '   ' },
    })
    fireEvent.click(screen.getByRole('button', { name: 'أنشئ المهمة' }))

    await waitFor(() => expect(createMutateAsync).toHaveBeenCalledTimes(1))
    const [input] = createMutateAsync.mock.calls[0] as [
      {
        title: string
        description?: string
        due_at?: string
        assignee_user_id?: string
      },
    ]
    expect(input.title).toBe('بدون تفاصيل')
    expect(input.description).toBeUndefined()
    expect(input.due_at).toBeUndefined()
    // An all-whitespace assignee is treated as not provided.
    expect(input.assignee_user_id).toBeUndefined()
  })

  it('surfaces the localized required-title error and never invokes the mutation on empty submit', async () => {
    mountPage()
    fireEvent.click(screen.getByRole('button', { name: 'أنشئ المهمة' }))
    await waitFor(() =>
      expect(screen.getByText('العنوان مطلوب.')).toBeInTheDocument(),
    )
    expect(createMutateAsync).not.toHaveBeenCalled()
  })

  it('maps a 403 server error to the forbidden title error', async () => {
    createMutateAsync.mockRejectedValueOnce(
      new ApiError(403, {
        type: 'about:blank',
        title: 'Forbidden',
        status: 403,
      }),
    )
    mountPage()
    fireEvent.change(screen.getByLabelText('العنوان'), {
      target: { value: 'مهمة محظورة' },
    })
    fireEvent.click(screen.getByRole('button', { name: 'أنشئ المهمة' }))
    await waitFor(() =>
      expect(
        screen.getByText('غير مصرح لك بإنشاء المهام.'),
      ).toBeInTheDocument(),
    )
    expect(navigateSpy).not.toHaveBeenCalled()
  })

  it('maps a 409 server error to the conflict title error', async () => {
    createMutateAsync.mockRejectedValueOnce(
      new ApiError(409, {
        type: 'about:blank',
        title: 'Conflict',
        status: 409,
      }),
    )
    mountPage()
    fireEvent.change(screen.getByLabelText('العنوان'), {
      target: { value: 'مهمة متعارضة' },
    })
    fireEvent.click(screen.getByRole('button', { name: 'أنشئ المهمة' }))
    await waitFor(() =>
      expect(
        screen.getByText('تعارض في إنشاء المهمة، يرجى إعادة المحاولة.'),
      ).toBeInTheDocument(),
    )
    expect(navigateSpy).not.toHaveBeenCalled()
  })

  it('maps a generic server error to the generic submit error', async () => {
    createMutateAsync.mockRejectedValueOnce(
      new ApiError(500, {
        type: 'about:blank',
        title: 'Server Error',
        status: 500,
      }),
    )
    mountPage()
    fireEvent.change(screen.getByLabelText('العنوان'), {
      target: { value: 'مهمة فشلت' },
    })
    fireEvent.click(screen.getByRole('button', { name: 'أنشئ المهمة' }))
    await waitFor(() =>
      expect(
        screen.getByText('تعذر إنشاء المهمة. يرجى إعادة المحاولة.'),
      ).toBeInTheDocument(),
    )
    expect(navigateSpy).not.toHaveBeenCalled()
  })

  it('cancel navigates back to /tasks without invoking the mutation', () => {
    mountPage()
    fireEvent.click(screen.getByRole('button', { name: 'إلغاء' }))
    expect(navigateSpy).toHaveBeenCalledWith('/tasks')
    expect(createMutateAsync).not.toHaveBeenCalled()
  })
})

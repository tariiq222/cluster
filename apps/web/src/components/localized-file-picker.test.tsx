// @vitest-environment jsdom
import { describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen } from '@testing-library/react'
import { LocalizedFilePicker } from './localized-file-picker'

/*
 * These tests pin the accessible, localized contract of the file picker
 * primitive that DESIGN-RULES §2.7 makes binding for routed forms.
 * They verify the localized visible button, the sr-only native input
 * that drives it, the described-by / invalid / live-region wiring, the
 * same-file replacement contract, and the absence of any visible native
 * English browser control.
 *
 * No product copy is asserted — only structural, accessibility, and
 * behavior contracts.
 */

function makeFile(name: string, size: number, type = 'application/pdf'): File {
  // The File constructor derives `size` from the content; for tests we
  // want deterministic sizes without allocating MiB of memory, so we
  // override the read-only `size` descriptor after construction.
  const file = new File(['x'], name, { type })
  Object.defineProperty(file, 'size', {
    configurable: true,
    value: size,
  })
  return file
}

function renderPicker(props: Partial<React.ComponentProps<typeof LocalizedFilePicker>> = {}) {
  const onChange = props.onChange ?? vi.fn()
  const utils = render(
    <LocalizedFilePicker
      inputId="doc-file"
      label="file label"
      chooseLabel="choose file"
      replaceLabel="replace file"
      helpText="max size 1 GiB"
      chosenLabel="chosen file"
      file={null}
      onChange={onChange}
      {...props}
    />,
  )
  return { onChange, ...utils }
}

describe('LocalizedFilePicker', () => {
  it('renders a visible keyboard-operable Button (type=button) with the choose label when no file is set', () => {
    renderPicker()
    const button = screen.getByRole('button', { name: 'choose file' })
    expect(button.tagName).toBe('BUTTON')
    expect(button).toHaveAttribute('type', 'button')
  })

  it('switches the button label to the replace label when a file is present', () => {
    const file = makeFile('doc.pdf', 2048)
    renderPicker({ file })
    expect(
      screen.getByRole('button', { name: /replace file/ }),
    ).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'choose file' })).toBeNull()
  })

  it('keeps the native <input type="file"> in the DOM with sr-only, bound to the label, and forwards accept', () => {
    renderPicker({ accept: 'application/pdf' })
    const input = screen.getByTestId('doc-file-input') as HTMLInputElement
    expect(input.tagName).toBe('INPUT')
    expect(input).toHaveAttribute('type', 'file')
    expect(input).toHaveClass('sr-only')
    expect(input).toHaveAttribute('id', 'doc-file')
    expect(input).toHaveAttribute('accept', 'application/pdf')
    const label = screen.getByTestId('doc-file-label')
    expect(label.tagName).toBe('LABEL')
    expect(label).toHaveAttribute('for', 'doc-file')
  })

  it('opens the native picker when the button is clicked', () => {
    renderPicker()
    const button = screen.getByRole('button', { name: 'choose file' })
    const input = screen.getByTestId('doc-file-input') as HTMLInputElement
    const clickSpy = vi.spyOn(input, 'click')
    fireEvent.click(button)
    expect(clickSpy).toHaveBeenCalledTimes(1)
  })

  it('calls onChange with the chosen file when the native input fires change', () => {
    const onChange = vi.fn()
    renderPicker({ onChange })
    const input = screen.getByTestId('doc-file-input') as HTMLInputElement
    const file = makeFile('doc.pdf', 2048)
    fireEvent.change(input, { target: { files: [file] } })
    expect(onChange).toHaveBeenCalledTimes(1)
    expect(onChange.mock.calls[0][0]).toBe(file)
  })

  it('displays the chosen filename in <bdi dir="auto"> and the size via the generic formatter', () => {
    const file = makeFile('سند-استلام.pdf', 5 * 1024 * 1024)
    renderPicker({ file })
    const summary = screen.getByTestId('doc-file-summary')
    const bdi = summary.querySelector('bdi')
    expect(bdi).not.toBeNull()
    expect(bdi).toHaveAttribute('dir', 'auto')
    expect(bdi).toHaveTextContent('سند-استلام.pdf')
    expect(bdi).toHaveClass('break-all')
    // 5 MiB is exactly 5.0 MB.
    expect(summary).toHaveTextContent('5.0 MB')
  })

  it('omits the visible summary when no file is chosen', () => {
    renderPicker()
    expect(screen.queryByTestId('doc-file-summary')).toBeNull()
  })

  it('announces the chosen file via a polite live region using the chosenLabel prefix', () => {
    const file = makeFile('doc.pdf', 1024)
    renderPicker({ file })
    const status = screen.getByTestId('doc-file-status')
    expect(status).toHaveAttribute('role', 'status')
    expect(status).toHaveAttribute('aria-live', 'polite')
    expect(status).toHaveClass('sr-only')
    expect(status).toHaveTextContent('chosen file: doc.pdf')
    // 1024 bytes -> 1.0 KB under the generic 1024-based formatter.
    expect(status).toHaveTextContent('1.0 KB')
  })

  it('clears the polite status announcement when no file is chosen', () => {
    renderPicker()
    const status = screen.getByTestId('doc-file-status')
    expect(status.textContent).toBe('')
  })

  it('links the input to help and status via aria-describedby; appends error id when error is present', () => {
    const { rerender } = render(
      <LocalizedFilePicker
        inputId="doc-file"
        label="file"
        chooseLabel="choose"
        replaceLabel="replace"
        helpText="max 1 GiB"
        chosenLabel="chosen"
        file={null}
        onChange={() => {}}
      />,
    )
    let input = screen.getByTestId('doc-file-input') as HTMLInputElement
    expect(input).toHaveAttribute(
      'aria-describedby',
      'doc-file-help doc-file-status',
    )
    let button = screen.getByRole('button', { name: 'choose' })
    expect(button).toHaveAttribute(
      'aria-describedby',
      'doc-file-help doc-file-status',
    )

    rerender(
      <LocalizedFilePicker
        inputId="doc-file"
        label="file"
        chooseLabel="choose"
        replaceLabel="replace"
        helpText="max 1 GiB"
        chosenLabel="chosen"
        file={null}
        error="too large"
        onChange={() => {}}
      />,
    )
    input = screen.getByTestId('doc-file-input') as HTMLInputElement
    expect(input).toHaveAttribute(
      'aria-describedby',
      'doc-file-help doc-file-status doc-file-error',
    )
    button = screen.getByRole('button', { name: 'choose' })
    expect(button).toHaveAttribute(
      'aria-describedby',
      'doc-file-help doc-file-status doc-file-error',
    )
  })

  it('sets aria-invalid when an error is present and clears it when not', () => {
    const { rerender } = render(
      <LocalizedFilePicker
        inputId="doc-file"
        label="file"
        chooseLabel="choose"
        replaceLabel="replace"
        helpText="h"
        chosenLabel="c"
        file={null}
        error="too large"
        onChange={() => {}}
      />,
    )
    let input = screen.getByTestId('doc-file-input') as HTMLInputElement
    expect(input).toHaveAttribute('aria-invalid', 'true')

    rerender(
      <LocalizedFilePicker
        inputId="doc-file"
        label="file"
        chooseLabel="choose"
        replaceLabel="replace"
        helpText="h"
        chosenLabel="c"
        file={null}
        onChange={() => {}}
      />,
    )
    input = screen.getByTestId('doc-file-input') as HTMLInputElement
    expect(input).not.toHaveAttribute('aria-invalid')
  })

  it('renders the error message with role=alert when present and omits it when not', () => {
    const { rerender } = render(
      <LocalizedFilePicker
        inputId="doc-file"
        label="file"
        chooseLabel="choose"
        replaceLabel="replace"
        helpText="h"
        chosenLabel="c"
        file={null}
        error="too large"
        onChange={() => {}}
      />,
    )
    const error = screen.getByTestId('doc-file-error')
    expect(error).toHaveAttribute('role', 'alert')
    expect(error).toHaveTextContent('too large')
    expect(error).toHaveAttribute('id', 'doc-file-error')
    expect(error).toHaveClass('text-destructive')

    rerender(
      <LocalizedFilePicker
        inputId="doc-file"
        label="file"
        chooseLabel="choose"
        replaceLabel="replace"
        helpText="h"
        chosenLabel="c"
        file={null}
        onChange={() => {}}
      />,
    )
    expect(screen.queryByTestId('doc-file-error')).toBeNull()
  })

  it('disables both the button and the native input when disabled is true', () => {
    renderPicker({ disabled: true })
    const button = screen.getByRole('button', { name: 'choose file' })
    const input = screen.getByTestId('doc-file-input') as HTMLInputElement
    expect(button).toBeDisabled()
    expect(input).toBeDisabled()
  })

  it('does not expose a visible native English browser control — the only visible control is the localized Button', () => {
    renderPicker({ file: makeFile('doc.pdf', 1024) })
    // The native input is sr-only — never visible to sighted users.
    const input = screen.getByTestId('doc-file-input') as HTMLInputElement
    expect(input).toHaveClass('sr-only')
    // The visible help paragraph is localized (caller-supplied) and
    // does not contain any of the default English browser strings.
    const help = screen.getByTestId('doc-file-help')
    expect(help.textContent).not.toMatch(/choose file/i)
    expect(help.textContent).not.toMatch(/browse/i)
    expect(help.textContent).not.toMatch(/no file chosen/i)
    // The only <button> the picker renders is the localized Button.
    expect(screen.getAllByRole('button')).toHaveLength(1)
  })

  it('resets the native input value before click when a file is chosen so the same file can be replaced', () => {
    const file = makeFile('doc.pdf', 2048)
    renderPicker({ file })
    const button = screen.getByRole('button', { name: /replace file/ })
    const input = screen.getByTestId('doc-file-input') as HTMLInputElement
    // Replace the value getter/setter on this instance so we can spy on writes.
    const captured = { value: 'doc.pdf' }
    Object.defineProperty(input, 'value', {
      configurable: true,
      get: () => captured.value,
      set: (v: string) => {
        captured.value = v
      },
    })
    let valueAtClick: string | null = null
    input.click = vi.fn(() => {
      valueAtClick = input.value
    })
    fireEvent.click(button)
    // The input value was reset to '' before click was invoked so the
    // native change event will fire even if the user re-selects the
    // same file path.
    expect(captured.value).toBe('')
    expect(valueAtClick).toBe('')
  })

  it('does not reset the input value when no file is chosen', () => {
    renderPicker()
    const button = screen.getByRole('button', { name: 'choose file' })
    const input = screen.getByTestId('doc-file-input') as HTMLInputElement
    let valueSet: string | null = null
    Object.defineProperty(input, 'value', {
      configurable: true,
      get: () => '',
      set: (v: string) => {
        valueSet = v
      },
    })
    input.click = vi.fn()
    fireEvent.click(button)
    // No reset when no file is selected — preserves the no-op contract.
    expect(valueSet).toBeNull()
    expect(input.click).toHaveBeenCalledTimes(1)
  })

  it('uses testIdPrefix when provided and emits stable per-picker test ids', () => {
    renderPicker({ testIdPrefix: 'doc-create-file' })
    expect(screen.getByTestId('doc-create-file-button')).toBeInTheDocument()
    expect(screen.getByTestId('doc-create-file-input')).toBeInTheDocument()
    expect(screen.getByTestId('doc-create-file-help')).toBeInTheDocument()
    expect(screen.getByTestId('doc-create-file-status')).toBeInTheDocument()
    expect(screen.getByTestId('doc-create-file-label')).toBeInTheDocument()
  })
})

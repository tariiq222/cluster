import { useRef } from 'react'
import type { ChangeEvent, ReactNode } from 'react'
import { FileUp } from 'lucide-react'
import { Button } from '@/components/ui/button'

/*
 * Localized, accessible file picker.
 *
 * The native browser file control is not localized and not stylable; we
 * render a real `<Button type="button">` as the visible affordance and
 * keep the native `<input type="file">` in the DOM with `sr-only` so
 * assistive tech, tests, and the actual file selection still work.
 *
 * Accessibility wiring:
 *   • visible `<label htmlFor=inputId>` names the control;
 *   • the button declares `aria-controls=inputId` so screen readers
 *     know which element it drives;
 *   • `aria-describedby` always includes the help and status ids; the
 *     error id joins the chain only when an error is present, so the
 *     active validation message is announced;
 *   • `aria-invalid` is set on the input when an error is present;
 *   • the status paragraph uses `role="status" aria-live="polite"` so
 *     announcing the chosen file does not interrupt the current read.
 *
 * Same-file replacement: the native input suppresses the `change` event
 * when the user selects the same path twice in a row, so the button
 * resets `input.value = ''` before triggering click whenever a file is
 * already chosen. That guarantees the onChange handler re-fires.
 */

export interface LocalizedFilePickerProps {
  /** DOM id for the native input; also the base for help/status/error ids. */
  inputId: string
  /** Visible label rendered as `<label htmlFor={inputId}>`. */
  label: ReactNode
  /** Button label when no file is chosen. */
  chooseLabel: string
  /** Button label when a file is already chosen. */
  replaceLabel: string
  /** Helper copy rendered under the button (always visible). */
  helpText: ReactNode
  /** sr-only prefix announcing the chosen file in the live region. */
  chosenLabel: string
  /** Currently chosen file, or null. Drives the button label and summary. */
  file: File | null
  /** Disables both the button and the native input. */
  disabled?: boolean
  /** When present, surfaces the validation error and sets aria-invalid. */
  error?: ReactNode
  /** Optional native `accept` attribute forwarded to the input. */
  accept?: string
  /** Called with the first selected file (or null when cleared). */
  onChange: (file: File | null) => void
  /**
   * Optional override for the `data-testid` prefix. When omitted, the
   * picker falls back to `inputId` so the test ids are still unique
   * per picker instance.
   */
  testIdPrefix?: string
}

export function LocalizedFilePicker({
  inputId,
  label,
  chooseLabel,
  replaceLabel,
  helpText,
  chosenLabel,
  file,
  disabled = false,
  error,
  accept,
  onChange,
  testIdPrefix,
}: LocalizedFilePickerProps) {
  const inputRef = useRef<HTMLInputElement | null>(null)

  const helpId = `${inputId}-help`
  const statusId = `${inputId}-status`
  const errorId = `${inputId}-error`
  const describedBy = error
    ? `${helpId} ${statusId} ${errorId}`
    : `${helpId} ${statusId}`

  const testIdRoot = testIdPrefix ?? inputId
  const testId = (suffix: string): string => `${testIdRoot}-${suffix}`

  const handleButtonClick = () => {
    const input = inputRef.current
    if (!input) return
    // Reset before click so re-selecting the same file still fires onChange.
    if (file) {
      input.value = ''
    }
    input.click()
  }

  const handleChange = (event: ChangeEvent<HTMLInputElement>) => {
    const next = event.target.files?.[0] ?? null
    onChange(next)
  }

  return (
    <div className="grid gap-2">
      <label
        htmlFor={inputId}
        className="text-sm font-medium"
        data-testid={testId('label')}
      >
        {label}
      </label>
      <div className="flex flex-wrap items-center gap-2">
        <Button
          type="button"
          variant="outline"
          onClick={handleButtonClick}
          disabled={disabled}
          aria-controls={inputId}
          aria-describedby={describedBy}
          data-testid={testId('button')}
        >
          <FileUp aria-hidden="true" />
          {file ? replaceLabel : chooseLabel}
        </Button>
        <input
          ref={inputRef}
          id={inputId}
          type="file"
          accept={accept}
          disabled={disabled}
          onChange={handleChange}
          aria-describedby={describedBy}
          aria-invalid={error ? 'true' : undefined}
          className="sr-only"
          data-testid={testId('input')}
        />
        {file ? (
          <p
            className="text-muted-foreground min-w-0 text-sm"
            data-testid={testId('summary')}
          >
            <span className="sr-only">{chosenLabel}: </span>
            <bdi dir="auto" className="break-all">
              {file.name}
            </bdi>
            {' · '}
            {formatBytes(file.size)}
          </p>
        ) : null}
      </div>
      <p
        id={helpId}
        className="text-muted-foreground text-xs"
        data-testid={testId('help')}
      >
        {helpText}
      </p>
      <p
        id={statusId}
        className="sr-only"
        role="status"
        aria-live="polite"
        data-testid={testId('status')}
      >
        {file
          ? `${chosenLabel}: ${file.name} (${formatBytes(file.size)})`
          : ''}
      </p>
      {error ? (
        <p
          id={errorId}
          role="alert"
          className="text-destructive text-xs"
          data-testid={testId('error')}
        >
          {error}
        </p>
      ) : null}
    </div>
  )
}

/**
 * Generic byte formatter used for the file summary and the polite
 * status announcement. Uses 1024-based units and trims to one decimal
 * place under 100 so the output stays compact in both compact (KB) and
 * large (GB/TB) regimes.
 */
function formatBytes(value: number): string {
  if (value < 1024) return `${value} B`
  const units = ['KB', 'MB', 'GB', 'TB']
  let current = value / 1024
  let unitIndex = 0
  while (current >= 1024 && unitIndex < units.length - 1) {
    current /= 1024
    unitIndex += 1
  }
  return `${current.toFixed(current >= 100 ? 0 : 1)} ${units[unitIndex]}`
}

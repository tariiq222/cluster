import type { ReactNode } from 'react'
import { cx } from './cx'

/**
 * Unified form field wrapper: label, control, and an error slot wired with
 * aria attributes. Pass the control as children with the matching `id`.
 */
export function Field({
  id,
  label,
  required = false,
  error,
  help,
  className,
  children,
}: {
  id: string
  label: ReactNode
  required?: boolean
  error?: ReactNode
  help?: ReactNode
  className?: string
  children: ReactNode
}) {
  const errorId = `${id}-error`
  const helpId = `${id}-help`
  return (
    <div className={cx('field', className)}>
      <label htmlFor={id}>
        {label}
        {required ? <span aria-hidden="true"> *</span> : null}
      </label>
      {children}
      {help ? <p id={helpId} className="field-help">{help}</p> : null}
      {error ? (
        <p id={errorId} className="field-error" role="alert">{error}</p>
      ) : null}
    </div>
  )
}

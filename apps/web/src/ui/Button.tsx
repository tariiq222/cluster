import type { ButtonHTMLAttributes } from 'react'
import { cx } from './cx'

export type ButtonVariant = 'primary' | 'secondary' | 'quiet'

/**
 * Unified button. Renders the shared `*-button` classes; keep using this
 * instead of scattering raw class names across screens.
 */
export function Button({
  variant = 'primary',
  className,
  type = 'button',
  ...rest
}: ButtonHTMLAttributes<HTMLButtonElement> & { variant?: ButtonVariant }) {
  return <button type={type} className={cx(`${variant}-button`, className)} {...rest} />
}

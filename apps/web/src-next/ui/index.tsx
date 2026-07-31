import type { ButtonHTMLAttributes, ReactNode, HTMLAttributes } from 'react'
import { useLocale } from '../app/session-context'
import { shellCopy } from '../i18n'

/* ---- Button ---- */

type ButtonVariant = 'primary' | 'secondary' | 'quiet' | 'danger'

export function Button({
  variant = 'primary',
  className = '',
  children,
  ...rest
}: ButtonHTMLAttributes<HTMLButtonElement> & { variant?: ButtonVariant }) {
  return (
    <button className={`button button--${variant} ${className}`} {...rest}>
      {children}
    </button>
  )
}

/* ---- Field ---- */

export function Field({
  id,
  label,
  required = false,
  error,
  help,
  className = '',
  children,
}: {
  id: string
  label: string
  required?: boolean
  error?: string | null
  help?: string
  className?: string
  children: ReactNode
}) {
  return (
    <div className={`field ${className}`}>
      <label className="field__label" htmlFor={id}>
        {label}
        {required && <span className="field__required" aria-hidden="true">*</span>}
      </label>
      {children}
      {help && <p className="field__help">{help}</p>}
      {error && (
        <p className="field__error" role="alert">
          {error}
        </p>
      )}
    </div>
  )
}

/* ---- Select (native, accessible) ---- */

export interface SelectOption {
  value: string
  label: string
}

export function Select({
  id,
  value,
  onChange,
  options,
  placeholder,
  disabled = false,
  ariaLabel,
  className = '',
}: {
  id: string
  value: string
  onChange: (value: string) => void
  options: SelectOption[]
  placeholder?: string
  disabled?: boolean
  ariaLabel?: string
  className?: string
}) {
  return (
    <select
      id={id}
      className={`field__control ${className}`}
      value={value}
      disabled={disabled}
      aria-label={ariaLabel}
      onChange={(event) => onChange(event.currentTarget.value)}
    >
      {placeholder !== undefined && <option value="">{placeholder}</option>}
      {options.map((option) => (
        <option key={option.value} value={option.value}>
          {option.label}
        </option>
      ))}
    </select>
  )
}

/* ---- Panels & layout ---- */

export function Page({ className = '', children, ...rest }: HTMLAttributes<HTMLElement>) {
  return (
    <section className={`ui-page ${className}`} {...rest}>
      {children}
    </section>
  )
}

export function PageHeader({
  id,
  title,
  description,
  actions,
}: {
  id: string
  title: string
  description?: string
  actions?: ReactNode
}) {
  return (
    <header className="page-header">
      <h1 className="page-header__title" id={id}>
        {title}
      </h1>
      {description && <p className="page-header__description">{description}</p>}
      {actions && <div className="page-header__actions">{actions}</div>}
    </header>
  )
}

export function Panel({
  id,
  title,
  level = 2,
  actions,
  className = '',
  children,
}: {
  id: string
  title: string
  level?: 2 | 3
  actions?: ReactNode
  className?: string
  children: ReactNode
}) {
  const Heading = level === 2 ? 'h2' : 'h3'
  return (
    <section className={`panel ${className}`} aria-labelledby={id}>
      <Heading className="panel__heading" id={id}>
        {title}
      </Heading>
      {actions && <div className="panel__actions">{actions}</div>}
      {children}
    </section>
  )
}

export function PanelGrid({ className = '', children }: { className?: string; children: ReactNode }) {
  return <div className={`panel-grid ${className}`}>{children}</div>
}

/* ---- States ---- */

export function EmptyState({
  icon,
  title,
  body,
  action,
}: {
  icon?: ReactNode
  title: string
  body?: string
  action?: ReactNode
}) {
  return (
    <div className="empty-state">
      {icon && <div className="empty-state__icon" aria-hidden="true">{icon}</div>}
      <p className="empty-state__title">{title}</p>
      {body && <p className="empty-state__body">{body}</p>}
      {action}
    </div>
  )
}

export function InlineError({ message, retryLabel, onRetry }: { message: string; retryLabel?: string; onRetry?: () => void }) {
  return (
    <div className="error-summary" role="alert">
      <p className="status-message status-message--error">{message}</p>
      {onRetry && retryLabel && (
        <Button variant="secondary" onClick={onRetry}>
          {retryLabel}
        </Button>
      )}
    </div>
  )
}

export function SkeletonList({ rows = 3 }: { rows?: number }) {
  return (
    <div className="skeleton-list" role="status">
      {Array.from({ length: rows }, (_, index) => (
        <div key={index} className="skeleton-list__row" />
      ))}
    </div>
  )
}

export function StateGate({
  state,
  locale,
  onRetry,
  children,
}: {
  state: 'loading' | 'ready' | 'empty' | 'forbidden' | 'denied' | 'not-found' | 'conflict' | 'stale' | 'error'
  locale: 'ar' | 'en'
  onRetry?: () => void
  children: ReactNode
}) {
  const copy = shellCopy[locale]
  if (state === 'loading') return <SkeletonList />
  if (state === 'ready') return <>{children}</>
  if (state === 'forbidden' || state === 'denied') return <EmptyState title={copy.denied} />
  if (state === 'not-found') return <EmptyState title={copy.notFound} />
  return (
    <EmptyState
      title={state === 'empty' ? copy.empty : copy.error}
      action={onRetry && (state === 'error' || state === 'stale' || state === 'conflict') ? (
        <Button variant="secondary" onClick={onRetry}>{copy.retry}</Button>
      ) : undefined}
    />
  )
}

/* ---- Badge ---- */

export function StatusBadge({
  variant = 'neutral',
  children,
}: {
  variant?: 'neutral' | 'success' | 'warning' | 'danger' | 'info'
  children: ReactNode
}) {
  return <span className={`status-badge status-badge--${variant}`}>{children}</span>
}

/* ---- Drawer ---- */

export function Drawer({
  open,
  onClose,
  title,
  children,
}: {
  open: boolean
  onClose: () => void
  title: string
  children: ReactNode
}) {
  const locale = useLocale()
  const closeLabel = shellCopy[locale].close
  if (!open) return null
  return (
    <div className="drawer-overlay" onClick={onClose}>
      <div
        className="drawer"
        role="dialog"
        aria-modal="true"
        aria-label={title}
        onClick={(event) => event.stopPropagation()}
      >
        <div className="drawer__header">
          <h2 className="drawer__title">{title}</h2>
          <button type="button" className="button button--quiet" onClick={onClose} aria-label={closeLabel}>
            ✕
          </button>
        </div>
        <div className="drawer__body">{children}</div>
      </div>
    </div>
  )
}

/* ---- Tabs ---- */

export interface Tab {
  key: string
  label: string
  active: boolean
  onClick: () => void
}

export function Tabs({ tabs, label }: { tabs: Tab[]; label: string }) {
  return (
    <nav className="tabs" aria-label={label}>
      {tabs.map((tab) => (
        <button
          key={tab.key}
          type="button"
          className={`tabs__tab${tab.active ? ' tabs__tab--active' : ''}`}
          aria-current={tab.active ? 'page' : undefined}
          onClick={tab.onClick}
        >
          {tab.label}
        </button>
      ))}
    </nav>
  )
}

export function useLocaleCopy() {
  return useLocale()
}

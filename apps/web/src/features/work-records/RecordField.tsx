import type { ChangeEvent, ReactNode } from 'react'
import type { FieldAccessState } from '../../api/r1'

const screenCopy = {
  ar: {
    label: '—',
    valueCannotBeDisplayed: 'قيمة غير قابلة للعرض',
    readOnly: 'للقراءة فقط',
    valueMasked: 'القيمة محجوبة',
  },
  en: {
    label: '—',
    valueCannotBeDisplayed: 'Value cannot be displayed',
    readOnly: 'Read only',
    valueMasked: 'Value masked',
  },
} as const


export type RecordFieldProps = {
  name: string
  label?: string
  value: unknown
  access: FieldAccessState
  locale?: 'ar' | 'en'
  onChange?: (value: string) => void
}

export function formatRecordValue(value: unknown, locale: 'ar' | 'en' = 'ar'): string {
  if (value === null || value === undefined || value === '') return screenCopy[locale].label
  if (typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean') return String(value)
  try {
    return JSON.stringify(value)
  } catch {
    return screenCopy[locale].valueCannotBeDisplayed
  }
}

export function RecordField({ name, label = name, value, access, locale = 'ar', onChange }: RecordFieldProps): ReactNode {
  if (access === 'hidden') return null

  const isMasked = access === 'masked'
  const isEditable = access === 'editable' && onChange !== undefined
  const inputId = `record-field-${name.replace(/[^a-zA-Z0-9_-]/g, '-')}`

  function handleChange(event: ChangeEvent<HTMLInputElement>) {
    onChange?.(event.target.value)
  }

  return (
    <div className="field record-field" data-field={name} data-access={access}>
      <label htmlFor={isEditable ? inputId : undefined}>{label}</label>
      {isEditable ? (
        <input id={inputId} name={name} value={formatRecordValue(value, locale)} onChange={handleChange} />
      ) : (
        <output aria-label={label} aria-readonly="true">
          {isMasked ? '***' : formatRecordValue(value, locale)}
        </output>
      )}
      {access === 'readonly' && <span className="record-field-hint">{screenCopy[locale].readOnly}</span>}
      {isMasked && <span className="record-field-hint">{screenCopy[locale].valueMasked}</span>}
    </div>
  )
}

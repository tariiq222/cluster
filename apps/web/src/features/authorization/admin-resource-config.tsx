import type { ReactNode } from 'react'
import type { AuthorizationItem, AuthorizationResource } from '../../api/r1'

export type Locale = 'ar' | 'en'

export type AdminResource = AuthorizationResource | 'supervisory'

export type ResourceColumn = {
  key: string
  label: { ar: string; en: string }
  dir?: 'ltr' | 'rtl'
  render?: (item: AuthorizationItem) => ReactNode
}

export type EditField =
  | 'name'
  | 'status'
  | 'reason'
  | 'code'
  | 'scope'
  | 'scopeId'
  | 'subject'
  | 'role'
  | 'start'
  | 'end'
  | 'policy'

export const ROLE_ASSIGNMENT_COLUMNS: ResourceColumn[] = [
  { key: 'user_id', label: { ar: 'المستخدم', en: 'User' }, dir: 'ltr' },
  { key: 'role_id', label: { ar: 'الدور', en: 'Role' }, dir: 'ltr' },
  { key: 'scope_type', label: { ar: 'نوع النطاق', en: 'Scope type' } },
  { key: 'scope_id', label: { ar: 'معرّف النطاق', en: 'Scope ID' }, dir: 'ltr' },
  { key: 'start_at', label: { ar: 'تاريخ البدء', en: 'Start' } },
  { key: 'end_at', label: { ar: 'تاريخ الانتهاء', en: 'End' } },
  { key: 'status', label: { ar: 'الحالة', en: 'Status' } },
]

export const DELEGATION_COLUMNS: ResourceColumn[] = [
  { key: 'subject_user_id', label: { ar: 'المفوَّض', en: 'Subject' }, dir: 'ltr' },
  { key: 'role_id', label: { ar: 'الدور المفوَّض', en: 'Role' }, dir: 'ltr' },
  { key: 'scope_type', label: { ar: 'نوع النطاق', en: 'Scope type' } },
  { key: 'scope_id', label: { ar: 'معرّف النطاق', en: 'Scope ID' }, dir: 'ltr' },
  { key: 'start_at', label: { ar: 'تاريخ البدء', en: 'Start' } },
  { key: 'end_at', label: { ar: 'تاريخ الانتهاء', en: 'End' } },
  { key: 'status', label: { ar: 'الحالة', en: 'Status' } },
]

export const CLASSIFICATION_POLICY_COLUMNS: ResourceColumn[] = [
  { key: 'code', label: { ar: 'الرمز', en: 'Code' }, dir: 'ltr' },
  { key: 'name', label: { ar: 'الاسم', en: 'Name' } },
  { key: 'scope_type', label: { ar: 'نوع النطاق', en: 'Scope type' } },
  { key: 'scope_id', label: { ar: 'معرّف النطاق', en: 'Scope ID' }, dir: 'ltr' },
  { key: 'classification', label: { ar: 'التصنيف', en: 'Classification' } },
  { key: 'status', label: { ar: 'الحالة', en: 'Status' } },
]

export const FIELD_ACCESS_TEMPLATE_COLUMNS: ResourceColumn[] = [
  { key: 'code', label: { ar: 'الرمز', en: 'Code' }, dir: 'ltr' },
  { key: 'name', label: { ar: 'الاسم', en: 'Name' } },
  { key: 'scope_type', label: { ar: 'نوع النطاق', en: 'Scope type' } },
  { key: 'scope_id', label: { ar: 'معرّف النطاق', en: 'Scope ID' }, dir: 'ltr' },
  { key: 'status', label: { ar: 'الحالة', en: 'Status' } },
]

export const SUPERVISORY_COLUMNS: ResourceColumn[] = [
  { key: 'subject_user_id', label: { ar: 'المشرف عليه', en: 'Subject' }, dir: 'ltr' },
  { key: 'supervisor_id', label: { ar: 'المشرف', en: 'Supervisor' }, dir: 'ltr' },
  { key: 'scope_type', label: { ar: 'نوع النطاق', en: 'Scope type' } },
  { key: 'scope_id', label: { ar: 'معرّف النطاق', en: 'Scope ID' }, dir: 'ltr' },
  { key: 'status', label: { ar: 'الحالة', en: 'Status' } },
]

export const RESOURCE_COLUMNS: Record<AdminResource, ResourceColumn[]> = {
  'role-assignments': ROLE_ASSIGNMENT_COLUMNS,
  delegations: DELEGATION_COLUMNS,
  'classification-policies': CLASSIFICATION_POLICY_COLUMNS,
  'field-access-templates': FIELD_ACCESS_TEMPLATE_COLUMNS,
  supervisory: SUPERVISORY_COLUMNS,
  roles: [
    { key: 'code', label: { ar: 'الرمز', en: 'Code' }, dir: 'ltr' },
    { key: 'name', label: { ar: 'الاسم', en: 'Name' } },
    { key: 'status', label: { ar: 'الحالة', en: 'Status' } },
  ],
  capabilities: [
    { key: 'code', label: { ar: 'الرمز', en: 'Code' }, dir: 'ltr' },
    { key: 'name', label: { ar: 'الاسم', en: 'Name' } },
    { key: 'classification', label: { ar: 'التصنيف', en: 'Classification' } },
    { key: 'status', label: { ar: 'الحالة', en: 'Status' } },
  ],
}

const ROLE_ASSIGNMENT_EDIT_FIELDS: EditField[] = [
  'name',
  'status',
  'reason',
  'scope',
  'scopeId',
  'subject',
  'role',
  'start',
  'end',
  'policy',
]
const DELEGATION_EDIT_FIELDS: EditField[] = [
  'name',
  'status',
  'reason',
  'scope',
  'scopeId',
  'subject',
  'start',
  'end',
  'policy',
]
const CLASSIFICATION_POLICY_EDIT_FIELDS: EditField[] = ['name', 'status', 'policy']
const FIELD_ACCESS_TEMPLATE_EDIT_FIELDS: EditField[] = ['name', 'status', 'policy']
const SUPERVISORY_EDIT_FIELDS: EditField[] = ['status']
const ROLES_EDIT_FIELDS: EditField[] = ['name', 'status']
const CAPABILITIES_EDIT_FIELDS: EditField[] = ['name', 'status']

export const RESOURCE_EDIT_FIELDS: Record<AdminResource, EditField[]> = {
  'role-assignments': ROLE_ASSIGNMENT_EDIT_FIELDS,
  delegations: DELEGATION_EDIT_FIELDS,
  'classification-policies': CLASSIFICATION_POLICY_EDIT_FIELDS,
  'field-access-templates': FIELD_ACCESS_TEMPLATE_EDIT_FIELDS,
  supervisory: SUPERVISORY_EDIT_FIELDS,
  roles: ROLES_EDIT_FIELDS,
  capabilities: CAPABILITIES_EDIT_FIELDS,
}

export function getColumnValue(item: AuthorizationItem, key: string): string {
  const value = item[key]
  if (value === null || value === undefined) return '—'
  if (typeof value === 'string') return value
  if (typeof value === 'number' || typeof value === 'boolean') return String(value)
  return JSON.stringify(value)
}

export function ResourceItemTable({
  resource,
  items,
  locale,
  onSelect,
}: {
  resource: AdminResource
  items: AuthorizationItem[]
  locale: Locale
  onSelect?: (item: AuthorizationItem) => void
}) {
  const columns = RESOURCE_COLUMNS[resource]
  return (
    <div className="table-scroll">
      <table className="data-table">
        <caption className="visually-hidden">{resource}</caption>
        <thead>
          <tr>
            {columns.map((column) => (
              <th key={column.key} dir={column.dir}>{column.label[locale]}</th>
            ))}
            {onSelect ? <th>{locale === 'ar' ? 'إجراء' : 'Action'}</th> : null}
          </tr>
        </thead>
        <tbody>
          {items.map((item, index) => {
            const id = typeof item.id === 'string' ? item.id : `item-${index}`
            return (
              <tr key={id}>
                {columns.map((column) => (
                  <td key={column.key} dir={column.dir}>{column.render ? column.render(item) : getColumnValue(item, column.key)}</td>
                ))}
                {onSelect ? (
                  <td>
                    <button type="button" onClick={() => onSelect(item)}>
                      {locale === 'ar' ? 'تعديل' : 'Edit'}
                    </button>
                  </td>
                ) : null}
              </tr>
            )
          })}
        </tbody>
      </table>
    </div>
  )
}
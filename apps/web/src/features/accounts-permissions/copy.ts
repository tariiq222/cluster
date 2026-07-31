import type { Locale } from '../../app/copy'

export type AnnouncementKey =
  | 'role.created' | 'role.updated' | 'role.cloned' | 'role.archived'
  | 'assignment.created' | 'assignment.updated' | 'assignment.revoked' | 'assignment.expired'
  | 'role_capability.revoked'

export const ASSIGNMENT_SCOPE_RECORD_SET_HELPER_ID = 'assignment-scope-record-set-helper'

type LocalizedCopy = {
  tabs: string[]
  emptyStates: { accounts: string; roles: string; assignments: string; policies: string }
  unavailable: { policies: string; inspector: string }
  announcements: Record<AnnouncementKey, string>
  confirmations: { account: (name: string) => string; role: (name: string) => string; scope: (name: string) => string }
  inspector: { roleApplies: (scope: string) => string; decision: (outcome: string) => string }
  capabilityCount: (count: number) => string
  assignmentCount: (count: number) => string
  assignmentScope: {
    levels: { cluster: string; facility: string; unit: string; recordSet: string }
    recordSetHelperId: string
    recordSetHelper: string
    loading: string
    empty: string
    retry: string
  }
}

const ar: LocalizedCopy = {
  tabs: ['الحسابات', 'الأدوار والصلاحيات', 'إسنادات الأدوار', 'السياسات والنطاقات', 'فاحص قرار الصلاحية'],
  emptyStates: { accounts: 'لا توجد حسابات لعرضها.', roles: 'لا توجد أدوار مخصصة لعرضها.', assignments: 'لا توجد إسنادات أدوار لعرضها.', policies: 'لا توجد سياسات أو نطاقات متاحة.' },
  unavailable: { policies: 'أدوات السياسات والنطاقات المتقدمة غير متاحة لحسابك.', inspector: 'هذه الأداة المتقدمة غير متاحة لحسابك.' },
  announcements: {
    'role.created': 'تم إنشاء الدور بنجاح.',
    'role.updated': 'تم تحديث الدور بنجاح.',
    'role.cloned': 'تم نسخ الدور بنجاح.',
    'role.archived': 'تمت أرشفة الدور بنجاح.',
    'assignment.created': 'تم إنشاء إسناد الدور بنجاح.',
    'assignment.updated': 'تم تحديث إسناد الدور بنجاح.',
    'assignment.revoked': 'تم إلغاء إسناد الدور بنجاح.',
    'assignment.expired': 'تم إنهاء إسناد الدور بنجاح.',
    'role_capability.revoked': 'تمت إزالة الصلاحية من الدور بنجاح.',
  },
  confirmations: {
    account: (name) => `هل تريد متابعة هذا الإجراء على الحساب «${name}»؟`,
    role: (name) => `هل تريد متابعة هذا الإجراء على الدور «${name}»؟`,
    scope: (name) => `هل تريد متابعة هذا الإجراء على النطاق «${name}»؟`,
  },
  inspector: {
    roleApplies: (scope) => `ينطبق هذا الدور في نطاق ${scope}.`,
    decision: (outcome) => `نتيجة قرار الصلاحية: ${outcome}.`,
  },
  capabilityCount: (count) =>
    count === 0 ? 'لا توجد صلاحيات'
      : count === 1 ? 'صلاحية واحدة'
      : count === 2 ? 'صلاحيتان'
      : count >= 3 && count <= 10 ? `${count} صلاحيات`
      : `${count} صلاحية`,
  assignmentCount: (count) =>
    count === 0 ? 'لا توجد إسنادات'
      : count === 1 ? 'إسناد واحد'
      : count === 2 ? 'إسنادان'
      : count >= 3 && count <= 10 ? `${count} إسنادات`
      : `${count} إسنادًا`,
  assignmentScope: {
    levels: { cluster: 'التجمع', facility: 'المنشأة', unit: 'الوحدة', recordSet: 'مجموعة السجلات' },
    recordSetHelperId: ASSIGNMENT_SCOPE_RECORD_SET_HELPER_ID,
    recordSetHelper: 'نطاقات مجموعة السجلات غير متاحة بعد.',
    loading: 'جارٍ تحميل أهداف النطاق…',
    empty: 'لا توجد أهداف نطاق متاحة لهذا المستوى.',
    retry: 'إعادة تحميل الأهداف',
  },
}

const en: LocalizedCopy = {
  tabs: ['Accounts', 'Roles & Permissions', 'Role Assignments', 'Policies & Scopes', 'Permission Decision Inspector'],
  emptyStates: { accounts: 'No accounts to display.', roles: 'No custom roles to display.', assignments: 'No role assignments to display.', policies: 'No policies or scopes are available.' },
  unavailable: { policies: 'The advanced policies and scopes tools are not available to your account.', inspector: 'This advanced tool is not available to your account.' },
  announcements: {
    'role.created': 'Role created successfully.',
    'role.updated': 'Role updated successfully.',
    'role.cloned': 'Role cloned successfully.',
    'role.archived': 'Role archived successfully.',
    'assignment.created': 'Role assignment created successfully.',
    'assignment.updated': 'Role assignment updated successfully.',
    'assignment.revoked': 'Role assignment revoked successfully.',
    'assignment.expired': 'Role assignment expired successfully.',
    'role_capability.revoked': 'Role capability revoked successfully.',
  },
  confirmations: {
    account: (name) => `Continue this action for account “${name}”?`,
    role: (name) => `Continue this action for role “${name}”?`,
    scope: (name) => `Continue this action for scope “${name}”?`,
  },
  inspector: {
    roleApplies: (scope) => `This role applies in ${scope}.`,
    decision: (outcome) => `Permission decision: ${outcome}.`,
  },
  capabilityCount: (count) => `${count} ${count === 1 ? 'capability' : 'capabilities'}`,
  assignmentCount: (count) => `${count} ${count === 1 ? 'assignment' : 'assignments'}`,
  assignmentScope: {
    levels: { cluster: 'Cluster', facility: 'Facility', unit: 'Unit', recordSet: 'Record set' },
    recordSetHelperId: ASSIGNMENT_SCOPE_RECORD_SET_HELPER_ID,
    recordSetHelper: 'Record-set scope is not yet available.',
    loading: 'Loading scope targets…',
    empty: 'No scope targets available for this level.',
    retry: 'Reload targets',
  },
}

export const accountsPermissionsCopy = { ar, en } as const
export const accountsPermissionsTabs = accountsPermissionsCopy.en.tabs

export function pluralizeCapabilities(locale: Locale, count: number): string {
  return accountsPermissionsCopy[locale].capabilityCount(count)
}

export function pluralizeAssignments(locale: Locale, count: number): string {
  return accountsPermissionsCopy[locale].assignmentCount(count)
}

export function accountsPermissionsText(locale: Locale): LocalizedCopy {
  return accountsPermissionsCopy[locale]
}

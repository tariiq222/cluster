/**
 * Typed projections of the PlatformSettings API wire payloads.
 *
 * The generated contract models every platform response as the generic
 * `EntityResponse` / `CollectionResponse` envelope; these projections describe
 * the concrete bodies the PlatformSettings controllers actually emit so the
 * screens never read untyped `Record<string, unknown>` values.
 */

export interface PlatformIssue {
  source: string
  code: string
}

export interface PlatformHealthCheckMetric {
  code: string
  status: string
  latency_ms: number
}

export interface PlatformBackupReport {
  status: string
  last_successful_at: string | null
  last_failed_at: string | null
  last_validation_at: string | null
  allowed_actions?: string[]
}

export interface PlatformOperationsOverview {
  status: string
  updated_at?: string
  issues: PlatformIssue[]
  metrics?: {
    health_checks?: PlatformHealthCheckMetric[]
    backup?: PlatformBackupReport
  }
  allowed_actions?: string[]
}

export interface PlatformHealthCheck {
  code: string
  status: string
  checked_at: string
  latency_ms: number
  message_code?: string
}

export interface PlatformHealth {
  status: string
  updated_at: string
  checks: PlatformHealthCheck[]
  allowed_actions?: string[]
}

export interface PlatformOperationResult {
  http_status?: number
  operation_id: string
  status: string
  allowed_actions?: string[]
}

export interface PlatformSecurityPolicy {
  idle_timeout_minutes?: number
  absolute_session_hours?: number
  minimum_password_length?: number
  password_history_count?: number
  failed_login_attempts?: number
  failed_login_window_minutes?: number
  lockout_minutes?: number
}

export interface PlatformSettingsVersion {
  id?: string
  version_id?: string
  status?: string
  lock_version?: number
  content_hash?: string
  default_locale?: string
  timezone?: string
  security?: PlatformSecurityPolicy
  active_log_months?: number
  allowed_actions?: string[]
}

export interface PlatformSettingsVersionsList {
  items: PlatformSettingsVersion[]
  next_cursor?: string | null
}

export interface PlatformHolidayEntry {
  type?: string
  date?: string
  ends_on?: string | null
  is_working_day?: boolean
  starts_at?: string | null
  ends_at?: string | null
  reason?: string | null
}

export interface BusinessCalendarValues {
  working_days?: number[]
  weekends?: number[]
  holidays?: PlatformHolidayEntry[]
}

export interface BusinessCalendarEntity {
  id: string
  scope_type: string
  scope_id: string
  status: string
  timezone?: string
  lock_version: number
  values?: BusinessCalendarValues
  allowed_actions?: string[]
}

export interface BusinessCalendarList {
  items: BusinessCalendarEntity[]
}

export interface PlatformAlertPolicy {
  id: string
  code: string
  status: string
  severity: string
  channel: string
  lock_version: number
  allowed_actions?: string[]
}

export interface PlatformAlertPolicyList {
  items: PlatformAlertPolicy[]
}

export interface PlatformMaintenanceWindow {
  id: string
  status: string
  starts_at: string
  ends_at?: string | null
  message_ar?: string
  message_en?: string
  lock_version: number
  allowed_actions?: string[]
}

export interface PlatformMaintenanceWindowList {
  items: PlatformMaintenanceWindow[]
}

export interface PlatformTechnicalLogEntry {
  id: string
  source: string
  category?: string
  severity: string
  message_ar?: string
  message_en?: string
  occurred_at: string
  correlation_id?: string
}

export interface PlatformTechnicalLogList {
  items: PlatformTechnicalLogEntry[]
  next_cursor?: string | null
  allowed_actions?: string[]
}

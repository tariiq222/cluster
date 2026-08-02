import type { ListAuditEventsParams } from '../../api/generated/cluster'

export type FilterDraft = {
  sourceModule: string
  action: string
  classification: '' | 'public' | 'internal' | 'confidential' | 'top_secret'
  occurredFrom: string
  occurredTo: string
}

/*
 * Client-side redaction guard (defense in depth): server-redacted context is
 * already sanitized, but any key whose name carries hash / key / fingerprint /
 * secret / HMAC material must never reach the DOM regardless of upstream data
 * — at every nesting depth. Sensitive properties are dropped entirely while
 * allowed siblings and array structure are preserved.
 */
export const SENSITIVE_CONTEXT_KEY = /hash|key|fingerprint|secret|hmac/i

export function sanitizeAuditContext<T>(value: T): T {
  if (Array.isArray(value)) {
    return value.map((item) => sanitizeAuditContext(item)) as T
  }
  if (value !== null && typeof value === 'object') {
    const result: Record<string, unknown> = {}
    for (const [key, child] of Object.entries(value as Record<string, unknown>)) {
      if (SENSITIVE_CONTEXT_KEY.test(key)) continue
      result[key] = sanitizeAuditContext(child)
    }
    return result as T
  }
  return value
}

export function redactedContextEntries(
  context: Record<string, unknown> | null | undefined,
): Array<[string, unknown]> {
  if (!context) return []
  return Object.entries(sanitizeAuditContext(context))
}

export function queryFromFilters(filters: FilterDraft): ListAuditEventsParams {
  const sourceModule = filters.sourceModule.trim()
  const action = filters.action.trim()
  return {
    ...(sourceModule ? { source_module: sourceModule } : {}),
    ...(action ? { action } : {}),
    ...(filters.classification ? { classification: filters.classification } : {}),
    ...(filters.occurredFrom ? { occurred_from: new Date(filters.occurredFrom).toISOString() } : {}),
    ...(filters.occurredTo ? { occurred_to: new Date(filters.occurredTo).toISOString() } : {}),
  }
}
